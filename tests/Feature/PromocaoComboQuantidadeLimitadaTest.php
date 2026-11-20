<?php

namespace Tests\Feature;

use App\Enums\CanalLancamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\PromocaoStatusEnum;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\PedidoComboAplicacao;
use App\Models\Produto;
use App\Models\PromocaoCombo;
use App\Services\CardapioService;
use App\Services\ItemSolicitado;
use App\Services\LancamentoItemPedidoService;
use App\Services\TotaisPedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Promoção Combo com quantidade limitada (Fase 4): o saldo vem dos combos
 * gravados nos pedidos não cancelados, então cancelar devolve o combo e
 * recalcular o próprio pedido não gasta saldo de novo.
 */
class PromocaoComboQuantidadeLimitadaTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $grande;

    private OpcoesEntregas $retirada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grande = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio' => true, 'categoria_cardapio_garcom' => true]);
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
    }

    private function produto(string $nome, float $preco = 50.00): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->grande->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
            'produto_cardapio_garcom' => true,
        ]);
    }

    /** Pedido com duas grandes (fecha um combo de 2 por R$ 87,99). */
    private function pedidoComDuasGrandes(): Pedido
    {
        $pedido = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_opcaoentrega_id' => $this->retirada->id]);
        $lancamento = app(LancamentoItemPedidoService::class);

        foreach ([$this->produto('Calabresa'), $this->produto('Frango')] as $produto) {
            $lancamento->gravar($pedido->id, $lancamento->precificar(new ItemSolicitado($produto->id, 1), CanalLancamentoEnum::SALAO));
        }

        return $pedido;
    }

    public function test_combo_acaba_quando_a_quantidade_e_usada_e_volta_quando_o_pedido_e_cancelado(): void
    {
        $promocao = PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_qtd_total' => 1]);

        $primeiro = $this->pedidoComDuasGrandes();
        $segundo = $this->pedidoComDuasGrandes();

        $this->assertSame('87.99', $primeiro->fresh()->pedido_valor_total);
        $this->assertSame('100.00', $segundo->fresh()->pedido_valor_total);
        $this->assertSame(0, $promocao->combosRestantes());
        $this->assertSame(PromocaoStatusEnum::Esgotada, $promocao->fresh()->status());

        $primeiro->update(['pedido_status' => 'CANCELADO']);
        app(TotaisPedidoService::class)->recalcular($segundo->fresh());

        $this->assertSame('87.99', $segundo->fresh()->pedido_valor_total);
        $this->assertSame(0, $promocao->combosRestantes());
    }

    public function test_recalcular_o_pedido_que_ja_tem_o_combo_nao_gasta_o_saldo_de_novo(): void
    {
        $promocao = PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_qtd_total' => 1]);
        $pedido = $this->pedidoComDuasGrandes();

        app(TotaisPedidoService::class)->recalcular($pedido->fresh());
        app(TotaisPedidoService::class)->recalcular($pedido->fresh());

        $this->assertSame('87.99', $pedido->fresh()->pedido_valor_total);
        $this->assertSame(1, PedidoComboAplicacao::count());
        $this->assertSame(0, $promocao->combosRestantes());
    }

    public function test_recorrente_conta_so_os_pedidos_da_ocorrencia_atual(): void
    {
        $promocao = PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_qtd_total' => 1]);
        $antigo = $this->pedidoComDuasGrandes();
        $antigo->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        $promocao->update(['promocombo_recorrente' => true, 'promocombo_ultimo_reset_em' => now()->subHour()]);

        $this->assertSame(1, $promocao->fresh()->combosRestantes());
        $this->assertSame('87.99', $this->pedidoComDuasGrandes()->fresh()->pedido_valor_total);
    }

    public function test_comando_de_recorrencia_reabre_o_saldo_da_nova_ocorrencia(): void
    {
        $promocao = PromocaoCombo::factory()->daCategoria($this->grande)->create([
            'promocombo_qtd_total' => 1,
            'promocombo_recorrente' => true,
            'promocombo_inicio' => now()->subWeek(),
            'promocombo_dias_semana' => [now()->dayOfWeek],
            'promocombo_hora_inicio' => '00:00:00',
            'promocombo_hora_fim' => '23:59:59',
        ]);
        $antigo = $this->pedidoComDuasGrandes();
        $antigo->forceFill(['created_at' => now()->subMinute()])->saveQuietly();

        $this->assertSame(0, $promocao->combosRestantes());

        $this->artisan('promocoes:resetar-recorrentes')->assertSuccessful();

        $this->assertNotNull($promocao->fresh()->promocombo_ultimo_reset_em);
        $this->assertSame(1, $promocao->fresh()->combosRestantes());
    }

    public function test_cardapio_mostra_quantos_restam_e_tira_a_promocao_esgotada(): void
    {
        $this->produto('Portuguesa');
        $promocao = PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_qtd_total' => 2]);
        $this->pedidoComDuasGrandes();

        $this->assertSame(1, app(CardapioService::class)->dados()['promocoesCombo'][0]['restam']);

        $promocao->update(['promocombo_qtd_total' => 1]);

        $this->assertSame([], app(CardapioService::class)->dados()['promocoesCombo']);
    }

    public function test_checkout_avisa_quando_o_combo_esgota_enquanto_o_cliente_finaliza(): void
    {
        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);
        $promocao = PromocaoCombo::factory()->daCategoria($this->grande)->create(['promocombo_qtd_total' => 1, 'promocombo_nome' => 'Duas grandes']);
        $calabresa = $this->produto('Calabresa');
        $frango = $this->produto('Frango');
        $outro = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_opcaoentrega_id' => $this->retirada->id]);

        // Outro pedido leva o último combo depois da prévia do checkout e antes de gravar as linhas.
        Pedido::created(function (Pedido $pedido) use ($outro, $promocao): void {
            if ($pedido->id !== $outro->id && PedidoComboAplicacao::count() === 0) {
                PedidoComboAplicacao::create([
                    'pca_pedido_id' => $outro->id,
                    'pca_promocao_combo_id' => $promocao->id,
                    'pca_chave' => 'corrida',
                    'pca_nome' => 'Duas grandes',
                    'pca_quantidade' => 2,
                    'pca_preco' => 87.99,
                    'pca_valor_cheio' => 100,
                    'pca_desconto' => 12.01,
                ]);
            }
        });

        $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Cliente Teste',
            'telefone' => '64999998888',
            'opcao_entrega_id' => $this->retirada->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => [['id' => $calabresa->id, 'qty' => 1], ['id' => $frango->id, 'qty' => 1]],
        ])
            ->assertOk()
            ->assertJsonPath('combos', [])
            ->assertJsonPath('avisos', ['A promoção "Duas grandes" esgotou enquanto você finalizava: 1 combo saiu pelo preço normal.']);
    }
}
