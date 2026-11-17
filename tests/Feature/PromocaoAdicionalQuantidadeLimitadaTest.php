<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Exceptions\PromocaoAdicionalEsgotadaException;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoAdicionalOferta;
use App\Models\User;
use App\Services\PromocaoAdicionalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Teto de brindes da campanha (promoad_qtd_total): "na compra de pizza
 * grande, refri 2L grátis — só os 20 primeiros". Conta 1 por oferta aceita,
 * soma todas as regras da campanha e é debitado na gravação do pedido.
 */
class PromocaoAdicionalQuantidadeLimitadaTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $grande;

    private Produto $calabresa;

    private Produto $frango;

    private Produto $brotinhoCalabresa;

    private Produto $refri;

    private OpcoesEntregas $retirada;

    protected function setUp(): void
    {
        parent::setUp();

        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);

        $this->grande = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio' => true]);
        $brotinho = Categoria::create(['categoria_nome' => 'Pizza Brotinho', 'categoria_cardapio' => true]);
        $bebidas = Categoria::create(['categoria_nome' => 'Refrigerante', 'categoria_cardapio' => true]);

        $this->calabresa = $this->produto('Calabresa', $this->grande, 59.90);
        $this->frango = $this->produto('Frango', $this->grande, 62.90);
        $this->brotinhoCalabresa = $this->produto('Calabresa Brotinho', $brotinho, 25.00);
        $this->refri = $this->produto('Guaraná Kuat 2L', $bebidas, 12.00);

        $this->retirada = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Retirada',
            'opcaoentrega_valor_frete' => 0,
            'opcaoentrega_min_valor_frete' => 0,
        ]);
    }

    private function produto(string $nome, Categoria $categoria, float $preco): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    /** Campanha com uma regra por pizza grande, cada uma oferecendo o refri grátis. */
    private function campanha(?int $qtdTotal, float $valorAdicional = 0.0): PromocaoAdicional
    {
        $promocao = PromocaoAdicional::create([
            'promoad_nome' => 'Pizza Grande + Kuat',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
            'promoad_qtd_total' => $qtdTotal,
        ]);

        foreach ([$this->calabresa, $this->frango] as $pizza) {
            $promocao->regras()->create(['par_produto_gatilho_id' => $pizza->id])
                ->ofertas()->create([
                    'pao_produto_oferta_id' => $this->refri->id,
                    'pao_valor_adicional' => $valorAdicional,
                ]);
        }

        return $promocao;
    }

    private function checkout(array $itens): TestResponse
    {
        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '11999998888',
            'opcao_entrega_id' => $this->retirada->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => $itens,
        ]);
    }

    private function pizzaComRefri(Produto $pizza): array
    {
        return ['id' => $pizza->id, 'qty' => 1, 'oferta_produto_id' => $this->refri->id];
    }

    private function refrisNoPedido(?Pedido $pedido = null): int
    {
        return ItensPedido::where('item_pedido_produto_id', $this->refri->id)
            ->when($pedido, fn ($q) => $q->where('item_pedido_pedido_id', $pedido->id))
            ->count();
    }

    public function test_campanha_sem_limite_concede_brinde_e_continua_sem_status_esgotada(): void
    {
        $promocao = $this->campanha(qtdTotal: null);

        $this->checkout([$this->pizzaComRefri($this->calabresa), $this->pizzaComRefri($this->frango)])
            ->assertOk()
            ->assertJsonPath('avisos', []);

        $this->assertSame(2, $this->refrisNoPedido());
        $this->assertSame(2.0, (float) $promocao->fresh()->promoad_qtd_vendida);
        $this->assertFalse($promocao->fresh()->esgotada());
    }

    public function test_com_limite_20_o_vigesimo_brinde_e_concedido_e_o_vigesimo_primeiro_nao(): void
    {
        $promocao = $this->campanha(qtdTotal: 20);
        $promocao->update(['promoad_qtd_vendida' => 19]);

        $this->checkout([$this->pizzaComRefri($this->calabresa)])->assertOk();

        $this->assertSame(1, $this->refrisNoPedido());
        $this->assertTrue($promocao->fresh()->esgotada());

        $this->checkout([$this->pizzaComRefri($this->frango)])
            ->assertOk()
            ->assertJsonPath('itens_sem_brinde', [0])
            ->assertJsonCount(1, 'avisos');

        $this->assertSame(1, $this->refrisNoPedido());
        $this->assertSame(20.0, (float) $promocao->fresh()->promoad_qtd_vendida);
        $this->assertSame(2, ItensPedido::whereIn('item_pedido_produto_id', [$this->calabresa->id, $this->frango->id])->count());
    }

    public function test_campanha_ja_esgotada_no_checkout_grava_a_pizza_sem_brinde_e_avisa(): void
    {
        $promocao = $this->campanha(qtdTotal: 20);
        $promocao->update(['promoad_qtd_vendida' => 20]);

        $this->checkout([$this->pizzaComRefri($this->calabresa)])
            ->assertOk()
            ->assertJsonPath('itens_sem_brinde', [0])
            ->assertJsonPath('avisos.0', 'O brinde de Calabresa não está mais disponível e não foi incluído.');

        $this->assertSame(0, $this->refrisNoPedido());
        $this->assertStringContainsString('SEM BRINDE', ItensPedido::sole()->item_pedido_observacao);
    }

    public function test_saldo_1_com_duas_pizzas_concede_um_brinde_e_anota_a_outra_pizza(): void
    {
        $promocao = $this->campanha(qtdTotal: 1);

        $this->checkout([$this->pizzaComRefri($this->calabresa), $this->pizzaComRefri($this->frango)])
            ->assertOk()
            ->assertJsonPath('itens_sem_brinde', [1])
            ->assertJsonPath('avisos.0', 'A promoção "Pizza Grande + Kuat" esgotou: este item seguiu sem o brinde.');

        $frango = ItensPedido::where('item_pedido_produto_id', $this->frango->id)->sole();

        $this->assertSame(1, $this->refrisNoPedido());
        $this->assertSame(1.0, (float) $promocao->fresh()->promoad_qtd_vendida);
        $this->assertStringContainsString('SEM BRINDE', $frango->item_pedido_observacao);
        $this->assertSame(0, ItensPedido::where('item_pedido_origem_id', $frango->id)->count());
    }

    public function test_brinde_pago_nao_concedido_sai_do_total_do_pedido(): void
    {
        $this->campanha(qtdTotal: 1, valorAdicional: 5.00);

        $this->checkout([$this->pizzaComRefri($this->calabresa), $this->pizzaComRefri($this->frango)])
            ->assertOk()
            ->assertJsonPath('total_final', 127.8);

        // 59,90 + 62,90 + 1 brinde de 5,00 (o segundo não foi concedido).
        $this->assertSame('127.80', Pedido::sole()->pedido_valor_total);
    }

    public function test_cancelar_pedido_devolve_o_brinde_ao_saldo_da_campanha(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $promocao = $this->campanha(qtdTotal: 20);

        $this->checkout([$this->pizzaComRefri($this->calabresa), $this->pizzaComRefri($this->frango)])->assertOk();
        $this->assertSame(2.0, (float) $promocao->fresh()->promoad_qtd_vendida);

        $this->post(route('pedido.cancelar', Pedido::sole()->id), [
            'pedido_motivo_cancelamento' => 'outro',
            'pedido_motivo_cancelamento_observacao' => 'Teste de estorno',
        ])->assertRedirect();

        $this->assertSame('CANCELADO', Pedido::sole()->pedido_status);
        $this->assertSame(0.0, (float) $promocao->fresh()->promoad_qtd_vendida);
    }

    /**
     * Dois pedidos de verdade em paralelo não cabem no PHPUnit (uma conexão,
     * dentro da transação do RefreshDatabase). O que impede o 21º brinde é o
     * UPDATE condicional no banco, então o teste reproduz a corrida no ponto
     * que importa: os dois pedidos já estão montados com o brinde quando o
     * saldo ainda era 1; consumir() não confere saldo em PHP, só o UPDATE
     * condicional decide — o primeiro grava e o segundo é recusado.
     */
    public function test_dois_pedidos_disputando_o_ultimo_brinde_so_um_ganha(): void
    {
        $promocao = $this->campanha(qtdTotal: 1);
        $oferta = PromocaoAdicionalOferta::firstOrFail();
        $pedidoA = $this->itemOferta($oferta);
        $pedidoB = $this->itemOferta($oferta);

        $service = app(PromocaoAdicionalService::class);
        $service->consumir($pedidoA);

        try {
            $service->consumir($pedidoB);
            $this->fail('O segundo pedido não podia levar o último brinde.');
        } catch (PromocaoAdicionalEsgotadaException) {
        }

        $this->assertSame(1.0, (float) $promocao->fresh()->promoad_qtd_vendida);
        $this->assertSame(1, DB::table('promocao_adicional_consumos')->count());
    }

    public function test_pizza_que_nao_e_grande_nao_ganha_brinde_nem_consome_saldo(): void
    {
        $promocao = $this->campanha(qtdTotal: 20);

        $this->checkout([['id' => $this->brotinhoCalabresa->id, 'qty' => 1, 'oferta_produto_id' => $this->refri->id]])
            ->assertOk();

        $this->assertSame(0, $this->refrisNoPedido());
        $this->assertSame(0.0, (float) $promocao->fresh()->promoad_qtd_vendida);
    }

    public function test_campanha_esgotada_sai_do_cardapio(): void
    {
        $promocao = $this->campanha(qtdTotal: 2);

        $this->get(route('cardapio'))->assertOk()->assertSee('Pizza Grande + Kuat');

        $promocao->update(['promoad_qtd_vendida' => 2]);

        $this->get(route('cardapio'))->assertOk()->assertDontSee('Pizza Grande + Kuat');
    }

    public function test_reset_recorrente_zera_o_teto_da_campanha(): void
    {
        $promocao = $this->campanha(qtdTotal: 20);
        $promocao->update([
            'promoad_recorrente' => true,
            'promoad_inicio' => now()->subWeek(),
            'promoad_fim' => null,
            'promoad_qtd_vendida' => 20,
        ]);

        $this->artisan('promocoes:resetar-recorrentes')->assertSuccessful();

        $this->assertSame(0.0, (float) $promocao->fresh()->promoad_qtd_vendida);
    }

    private function itemOferta(PromocaoAdicionalOferta $oferta): ItensPedido
    {
        $pedido = Pedido::create([
            'pedido_status' => 'INICIADO',
            'pedido_datahora_abertura' => now(),
            'pedido_valor_itens' => 59.90,
            'pedido_valor_total' => 59.90,
        ]);

        $base = [
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ];

        $gatilho = ItensPedido::create($base + [
            'item_pedido_produto_id' => $this->calabresa->id,
            'item_pedido_valor_unitario' => 59.90,
            'item_pedido_valor' => 59.90,
        ]);

        return ItensPedido::create($base + [
            'item_pedido_produto_id' => $this->refri->id,
            'item_pedido_promocao_adicional_regra_id' => $oferta->pao_regra_id,
            'item_pedido_promocao_adicional_oferta_id' => $oferta->id,
            'item_pedido_origem_id' => $gatilho->id,
            'item_pedido_valor_unitario' => 0,
            'item_pedido_valor' => 0,
        ]);
    }
}
