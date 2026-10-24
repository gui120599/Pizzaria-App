<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Escopos compartilhados do Pedido (abas da lista, filtros, exportação e o
 * guard de cancelarEntregue): cada um confere o conjunto que devolve.
 */
class PedidoEscoposTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $status, array $extra = []): Pedido
    {
        return Pedido::create(['pedido_status' => $status, 'pedido_valor_total' => 25.0] + $extra);
    }

    private function itemVendido(Pedido $pedido): void
    {
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 10.0]);
        $produto = Produto::create([
            'produto_descricao' => 'Refrigerante Lata',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas'])->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 10.0,
            'item_pedido_valor' => 10.0,
            'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => $venda->id,
        ]);
    }

    /** @return array<int, int> */
    private function ids(Builder $consulta): array
    {
        return $consulta->orderBy('id')->pluck('id')->all();
    }

    public function test_reais_tira_so_o_rascunho(): void
    {
        $rascunho = $this->pedido('INICIADO');
        $aberto = $this->pedido('ABERTO');
        $cancelado = $this->pedido('CANCELADO');

        $this->assertSame([$aberto->id, $cancelado->id], $this->ids(Pedido::reais()));
        $this->assertNotContains($rascunho->id, $this->ids(Pedido::reais()));
    }

    public function test_em_andamento_cobre_aberto_ate_em_transporte(): void
    {
        $dentro = collect(['ABERTO', 'PREPARANDO', 'PRONTO', 'EM TRANSPORTE'])
            ->map(fn (string $status): int => $this->pedido($status)->id)
            ->all();
        foreach (['INICIADO', 'ENTREGUE', 'FINALIZADO', 'CANCELADO'] as $fora) {
            $this->pedido($fora);
        }

        $this->assertSame($dentro, $this->ids(Pedido::emAndamento()));
    }

    public function test_nao_pagos_e_so_entregue_sem_venda_e_sem_item_vendido(): void
    {
        $pendurado = $this->pedido('ENTREGUE');
        $comVenda = $this->pedido('ENTREGUE', ['pedido_venda_id' => Venda::create(['venda_status' => 'INICIADA'])->id]);
        $parcial = $this->pedido('ENTREGUE');
        $this->itemVendido($parcial);
        $this->pedido('ABERTO');

        $this->assertSame([$pendurado->id], $this->ids(Pedido::naoPagos()));
        $this->assertNotContains($comVenda->id, $this->ids(Pedido::naoPagos()));
        $this->assertNotContains($parcial->id, $this->ids(Pedido::naoPagos()));
    }

    public function test_pagos_e_sem_pagamento_sao_complementares_e_batem_com_tem_pagamento(): void
    {
        $semNada = $this->pedido('ENTREGUE');
        $comVenda = $this->pedido('FINALIZADO', ['pedido_venda_id' => Venda::create(['venda_status' => 'FINALIZADA'])->id]);
        $parcial = $this->pedido('ENTREGUE');
        $this->itemVendido($parcial);

        $this->assertSame([$comVenda->id, $parcial->id], $this->ids(Pedido::pagos()));
        $this->assertSame([$semNada->id], $this->ids(Pedido::semPagamento()));

        foreach ([$semNada, $comVenda, $parcial] as $pedido) {
            $this->assertSame(
                Pedido::pagos()->whereKey($pedido->id)->exists(),
                $pedido->temPagamento(),
                "temPagamento() e scopePagos() divergem no pedido {$pedido->id}",
            );
        }
    }

    public function test_parados_usa_o_limite_atrasado_do_sla_do_status_atual(): void
    {
        $agora = Carbon::parse('2026-10-24 12:00:00');

        // ABERTO: atrasado a partir de 10 min na abertura; PREPARANDO: 35 min no preparo.
        $abertoAtrasado = $this->pedido('ABERTO', ['pedido_datahora_abertura' => $agora->copy()->subMinutes(11)]);
        $abertoNoPrazo = $this->pedido('ABERTO', ['pedido_datahora_abertura' => $agora->copy()->subMinutes(9)]);
        $preparandoAtrasado = $this->pedido('PREPARANDO', ['pedido_datahora_abertura' => $agora->copy()->subHours(3), 'pedido_datahora_preparo' => $agora->copy()->subMinutes(40)]);
        // Aberto há horas, mas entrou em PREPARANDO agora: no prazo (mede o status atual).
        $preparandoNoPrazo = $this->pedido('PREPARANDO', ['pedido_datahora_abertura' => $agora->copy()->subHours(3), 'pedido_datahora_preparo' => $agora->copy()->subMinutes(2)]);
        $semData = $this->pedido('PRONTO');
        $this->pedido('ENTREGUE', ['pedido_datahora_entrega' => $agora->copy()->subDays(2)]);
        $this->pedido('INICIADO');

        $this->assertSame(
            [$abertoAtrasado->id, $preparandoAtrasado->id],
            $this->ids(Pedido::parados($agora)),
        );
        $this->assertNotContains($abertoNoPrazo->id, $this->ids(Pedido::parados($agora)));
        $this->assertNotContains($preparandoNoPrazo->id, $this->ids(Pedido::parados($agora)));
        $this->assertNotContains($semData->id, $this->ids(Pedido::parados($agora)));
    }

    public function test_pendencias_junta_entregue_nao_pago_e_parado(): void
    {
        $agora = Carbon::parse('2026-10-24 12:00:00');

        $pendurado = $this->pedido('ENTREGUE');
        $parado = $this->pedido('ABERTO', ['pedido_datahora_abertura' => $agora->copy()->subHours(5)]);
        $pago = $this->pedido('ENTREGUE', ['pedido_venda_id' => Venda::create(['venda_status' => 'INICIADA'])->id]);
        $noPrazo = $this->pedido('ABERTO', ['pedido_datahora_abertura' => $agora->copy()->subMinutes(2)]);

        $this->assertSame([$pendurado->id, $parado->id], $this->ids(Pedido::pendencias($agora)));
        $this->assertNotContains($pago->id, $this->ids(Pedido::pendencias($agora)));
        $this->assertNotContains($noPrazo->id, $this->ids(Pedido::pendencias($agora)));
    }

    public function test_aberto_entre_usa_a_criacao_quando_nunca_foi_aberto_e_inclui_os_dois_extremos(): void
    {
        $naBorda = $this->pedido('ABERTO', ['pedido_datahora_abertura' => '2026-10-20 23:59:59']);
        $dentro = $this->pedido('ABERTO', ['pedido_datahora_abertura' => '2026-10-18 00:00:00']);
        $antes = $this->pedido('ABERTO', ['pedido_datahora_abertura' => '2026-10-17 23:59:59']);
        $depois = $this->pedido('ABERTO', ['pedido_datahora_abertura' => '2026-10-21 00:00:00']);
        $semAbertura = $this->pedido('CANCELADO');
        $semAbertura->forceFill(['created_at' => '2026-10-19 10:00:00'])->saveQuietly();
        $semAberturaFora = $this->pedido('CANCELADO');
        $semAberturaFora->forceFill(['created_at' => '2026-10-01 10:00:00'])->saveQuietly();

        $resultado = $this->ids(Pedido::abertoEntre(Carbon::parse('2026-10-18'), Carbon::parse('2026-10-20')));

        $this->assertSame([$naBorda->id, $dentro->id, $semAbertura->id], $resultado);
        $this->assertNotContains($antes->id, $resultado);
        $this->assertNotContains($depois->id, $resultado);
        $this->assertNotContains($semAberturaFora->id, $resultado);

        // Só o início, só o fim e nenhum dos dois.
        $this->assertContains($depois->id, $this->ids(Pedido::abertoEntre(Carbon::parse('2026-10-18'), null)));
        $this->assertContains($antes->id, $this->ids(Pedido::abertoEntre(null, Carbon::parse('2026-10-20'))));
        $this->assertCount(6, Pedido::abertoEntre(null, null)->get());
    }
}
