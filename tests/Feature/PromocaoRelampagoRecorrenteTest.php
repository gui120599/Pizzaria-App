<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\PromocaoStatusEnum;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use App\Services\PromocaoRelampagoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4: promoção relâmpago recorrente (dias da semana + horário fixo, sem
 * data final obrigatória), com reset do contador a cada nova ocorrência.
 */
class PromocaoRelampagoRecorrenteTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function produto(float $preco = 55.00): Produto
    {
        return Produto::create([
            'produto_descricao' => 'Marguerita',
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    private function promocaoRecorrente(array $attrs = [], ?Produto $produto = null): PromocaoRelampago
    {
        $promocao = PromocaoRelampago::create(array_merge([
            'promocao_nome' => 'Terça da Pizza',
            'promocao_ativa' => true,
            'promocao_recorrente' => true,
            'promocao_inicio' => now()->subMonth(),
            'promocao_dias_semana' => [2], // terça-feira
            'promocao_hora_inicio' => '18:00:00',
            'promocao_hora_fim' => '20:00:00',
            'promocao_qtd_total' => 40,
            'promocao_qtd_vendida' => 0,
        ], $attrs));

        if ($produto) {
            $promocao->promocaoProdutos()->create([
                'prp_produto_id' => $produto->id,
                'prp_preco_promocional' => 39.90,
            ]);
        }

        return $promocao;
    }

    public function test_vigente_dentro_do_dia_e_horario_configurados(): void
    {
        // 2026-07-14 é uma terça-feira.
        Carbon::setTestNow(Carbon::parse('2026-07-14 19:00:00'));

        $promocao = $this->promocaoRecorrente();

        $this->assertTrue($promocao->vigente());
        $this->assertSame(PromocaoStatusEnum::Ativa, $promocao->status());
    }

    public function test_nao_vigente_fora_do_horario_no_dia_certo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 21:00:00'));

        $promocao = $this->promocaoRecorrente();

        $this->assertFalse($promocao->vigente());
        $this->assertSame(PromocaoStatusEnum::AguardandoJanela, $promocao->status());
    }

    public function test_nao_vigente_em_dia_da_semana_errado(): void
    {
        // 2026-07-15 é quarta-feira.
        Carbon::setTestNow(Carbon::parse('2026-07-15 19:00:00'));

        $promocao = $this->promocaoRecorrente();

        $this->assertFalse($promocao->vigente());
        $this->assertSame(PromocaoStatusEnum::AguardandoJanela, $promocao->status());
    }

    public function test_dias_semana_vazio_vale_todo_dia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 19:00:00'));

        $promocao = $this->promocaoRecorrente(['promocao_dias_semana' => null]);

        $this->assertTrue($promocao->vigente());
    }

    public function test_janela_atravessando_meia_noite(): void
    {
        $promocao = $this->promocaoRecorrente([
            'promocao_dias_semana' => [2],
            'promocao_hora_inicio' => '22:00:00',
            'promocao_hora_fim' => '02:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-14 23:30:00'));
        $this->assertTrue($promocao->vigente());

        Carbon::setTestNow(Carbon::parse('2026-07-15 01:30:00'));
        $this->assertTrue($promocao->vigente());

        Carbon::setTestNow(Carbon::parse('2026-07-15 03:00:00'));
        $this->assertFalse($promocao->vigente());
    }

    public function test_sem_horario_configurado_vale_o_dia_inteiro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 08:00:00'));

        $promocao = $this->promocaoRecorrente([
            'promocao_hora_inicio' => null,
            'promocao_hora_fim' => null,
        ]);

        $this->assertTrue($promocao->vigente());
    }

    public function test_status_agendada_antes_do_inicio_da_recorrencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 19:00:00'));

        $promocao = $this->promocaoRecorrente(['promocao_inicio' => now()->addWeek()]);

        $this->assertFalse($promocao->vigente());
        $this->assertSame(PromocaoStatusEnum::Agendada, $promocao->status());
    }

    public function test_status_encerrada_apos_data_final_da_recorrencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 19:00:00'));

        $promocao = $this->promocaoRecorrente([
            'promocao_data_final_recorrencia' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($promocao->vigente());
        $this->assertSame(PromocaoStatusEnum::Encerrada, $promocao->status());
    }

    public function test_scope_vigente_filtra_promocoes_recorrentes_corretamente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 19:00:00'));

        $dentro = $this->promocaoRecorrente(['promocao_nome' => 'Dentro']);
        $fora = $this->promocaoRecorrente(['promocao_nome' => 'Fora', 'promocao_dias_semana' => [3]]);

        $ids = PromocaoRelampago::query()->vigente()->pluck('id');

        $this->assertTrue($ids->contains($dentro->id));
        $this->assertFalse($ids->contains($fora->id));
    }

    public function test_deve_resetar_agora_true_apos_horario_de_inicio_sem_reset_previo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 18:05:00'));

        $promocao = $this->promocaoRecorrente();

        $this->assertTrue($promocao->deveResetarAgora());
    }

    public function test_deve_resetar_agora_false_antes_do_horario_de_inicio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 17:59:00'));

        $promocao = $this->promocaoRecorrente();

        $this->assertFalse($promocao->deveResetarAgora());
    }

    public function test_deve_resetar_agora_false_se_ja_resetou_hoje(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 18:05:00'));

        $promocao = $this->promocaoRecorrente(['promocao_ultimo_reset_em' => now()->setTime(18, 0)]);

        $this->assertFalse($promocao->deveResetarAgora());
    }

    public function test_comando_reseta_contador_na_nova_ocorrencia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 18:05:00'));

        $produto = $this->produto();
        $promocao = $this->promocaoRecorrente(['promocao_qtd_vendida' => 37], $produto);
        $promocao->promocaoProdutos()->first()->update(['prp_qtd_vendida' => 37]);

        $this->artisan('promocoes:resetar-recorrentes')->assertSuccessful();

        $promocao->refresh();
        $this->assertSame(0.0, (float) $promocao->promocao_qtd_vendida);
        $this->assertSame(0.0, (float) $promocao->promocaoProdutos()->first()->prp_qtd_vendida);
        $this->assertTrue($promocao->promocao_ultimo_reset_em->isSameMinute(now()));

        // Rodar de novo no mesmo dia não deve mexer em nada (idempotente).
        $promocao->update(['promocao_qtd_vendida' => 5]);
        $this->artisan('promocoes:resetar-recorrentes')->assertSuccessful();
        $this->assertSame(5.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_consumir_fora_da_janela_recorrente_lanca_excecao_e_nao_debita(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 21:00:00')); // terça, fora do horário

        $produto = $this->produto();
        $promocao = $this->promocaoRecorrente(attrs: [], produto: $produto);

        $pedido = Pedido::create(['pedido_status' => 'INICIADO', 'pedido_datahora_abertura' => now()]);
        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_promocao_id' => $promocao->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 55.00,
            'item_pedido_desconto_unitario' => 15.10,
            'item_pedido_valor' => 39.90,
            'item_pedido_desconto' => 15.10,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $this->expectException(PromocaoIndisponivelException::class);

        app(PromocaoRelampagoService::class)->consumir($item);
    }

    public function test_consumir_dentro_da_janela_recorrente_debita_normalmente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 19:00:00'));

        $produto = $this->produto();
        $promocao = $this->promocaoRecorrente(attrs: [], produto: $produto);

        $pedido = Pedido::create(['pedido_status' => 'INICIADO', 'pedido_datahora_abertura' => now()]);
        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_promocao_id' => $promocao->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 55.00,
            'item_pedido_desconto_unitario' => 15.10,
            'item_pedido_valor' => 39.90,
            'item_pedido_desconto' => 15.10,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        app(PromocaoRelampagoService::class)->consumir($item);

        $this->assertSame(1.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_reconciliacao_ignora_consumos_anteriores_ao_ultimo_reset(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 19:00:00')); // terça anterior

        $produto = $this->produto();
        $promocao = $this->promocaoRecorrente(attrs: [], produto: $produto);

        $pedido = Pedido::create(['pedido_status' => 'INICIADO', 'pedido_datahora_abertura' => now()]);
        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_promocao_id' => $promocao->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 55.00,
            'item_pedido_desconto_unitario' => 15.10,
            'item_pedido_valor' => 39.90,
            'item_pedido_desconto' => 15.10,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
        app(PromocaoRelampagoService::class)->consumir($item);

        // Semana seguinte: reseta a ocorrência (o consumo antigo fica pra trás).
        Carbon::setTestNow(Carbon::parse('2026-07-14 18:05:00'));
        $this->artisan('promocoes:resetar-recorrentes')->assertSuccessful();

        $this->artisan('promocoes:reconciliar')->assertSuccessful();

        // Sem divergência: o contador (zerado no reset) já bate com a soma dos
        // consumos desde o último reset (nenhum, ainda) — não volta a contar
        // o consumo da ocorrência anterior.
        $this->assertSame(0.0, (float) $promocao->fresh()->promocao_qtd_vendida);
    }
}
