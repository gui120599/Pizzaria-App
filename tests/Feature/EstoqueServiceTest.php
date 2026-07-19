<?php

namespace Tests\Feature;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\MovimentacaoOrigemEnum;
use App\Exceptions\EstoqueInsuficienteException;
use App\Models\Categoria;
use App\Models\FichaTecnicaItem;
use App\Models\Produto;
use App\Models\User;
use App\Services\EstoqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EstoqueServiceTest extends TestCase
{
    use RefreshDatabase;

    private EstoqueService $service;

    private int $categoriaId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EstoqueService::class);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
        $this->userId = User::factory()->create(['name_first' => 'Operador'])->id;
    }

    private function produto(array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => 'Insumo Teste',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => \App\Enums\ProdutoTipoEnum::INSUMO->value,
            'produto_controla_estoque' => true,
        ], $attrs));
    }

    public function test_entrada_recalcula_custo_medio_ponderado(): void
    {
        $produto = $this->produto();

        $this->service->registrarEntrada($produto, 10, 2.00, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);
        $produto->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $produto->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $produto->produto_custo_medio, 0.0001);

        $this->service->registrarEntrada($produto, 10, 4.00, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);
        $produto->refresh();
        // Custo médio ponderado: (10*2 + 10*4) / 20 = 3,00
        $this->assertEqualsWithDelta(20.0, (float) $produto->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $produto->produto_custo_medio, 0.0001);
    }

    public function test_saida_valora_pelo_custo_medio_e_reduz_saldo(): void
    {
        $produto = $this->produto();
        $this->service->registrarEntrada($produto, 10, 2.00, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);
        $this->service->registrarEntrada($produto, 10, 4.00, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);

        $movs = $this->service->registrarSaida($produto, 5, MovimentacaoOrigemEnum::VENDA, ['user_id' => $this->userId]);
        $produto->refresh();

        $this->assertCount(1, $movs);
        $this->assertEqualsWithDelta(15.0, (float) $produto->produto_saldo_estoque, 0.001);
        // Saída não altera o custo médio.
        $this->assertEqualsWithDelta(3.0, (float) $produto->produto_custo_medio, 0.0001);
        // Valorada pelo médio: 5 * 3,00 = 15,00.
        $this->assertEqualsWithDelta(3.0, (float) $movs->first()->mov_custo_unitario, 0.0001);
        $this->assertEqualsWithDelta(15.0, (float) $movs->first()->mov_custo_total, 0.01);
    }

    public function test_saida_com_lote_consome_em_fefo(): void
    {
        $produto = $this->produto(['produto_controla_lote' => true]);

        // Lote A vence depois; Lote B vence antes.
        $this->service->registrarEntrada($produto, 5, 2.00, MovimentacaoOrigemEnum::COMPRA, [
            'user_id' => $this->userId, 'validade' => '2026-07-10', 'lote_codigo' => 'L-A',
        ]);
        $this->service->registrarEntrada($produto, 5, 3.00, MovimentacaoOrigemEnum::COMPRA, [
            'user_id' => $this->userId, 'validade' => '2026-06-25', 'lote_codigo' => 'L-B',
        ]);

        $movs = $this->service->registrarSaida($produto, 6, MovimentacaoOrigemEnum::VENDA, ['user_id' => $this->userId]);
        $produto->refresh();

        // Gera uma movimentação por lote consumido.
        $this->assertCount(2, $movs);
        // FEFO: o primeiro consumido é o de menor validade (L-B).
        $this->assertSame('L-B', $movs->first()->lote->lote_codigo);
        $this->assertEqualsWithDelta(5.0, (float) $movs->first()->mov_quantidade, 0.001);
        $this->assertSame('L-A', $movs->last()->lote->lote_codigo);
        $this->assertEqualsWithDelta(1.0, (float) $movs->last()->mov_quantidade, 0.001);

        // Saída valorada pelo custo médio (2,50), não pelo custo do lote.
        $this->assertEqualsWithDelta(2.5, (float) $movs->first()->mov_custo_unitario, 0.0001);

        // Saldos: total 4; L-B zerado, L-A com 4.
        $this->assertEqualsWithDelta(4.0, (float) $produto->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $produto->lotes()->where('lote_codigo', 'L-B')->value('lote_qtd_atual'), 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $produto->lotes()->where('lote_codigo', 'L-A')->value('lote_qtd_atual'), 0.001);
    }

    public function test_ajuste_gera_entrada_ou_saida_conforme_diferenca(): void
    {
        $produto = $this->produto();
        $this->service->registrarEntrada($produto, 10, 2.00, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);

        // Contagem maior que o saldo -> entrada de ajuste.
        $this->service->registrarAjuste($produto->refresh(), 12, ['user_id' => $this->userId]);
        $this->assertEqualsWithDelta(12.0, (float) $produto->refresh()->produto_saldo_estoque, 0.001);

        // Contagem menor que o saldo -> saída de ajuste.
        $this->service->registrarAjuste($produto->refresh(), 8, ['user_id' => $this->userId]);
        $this->assertEqualsWithDelta(8.0, (float) $produto->refresh()->produto_saldo_estoque, 0.001);
    }

    public function test_modo_nao_controlar_nunca_bloqueia_nem_avisa(): void
    {
        // Default do produto é NAO_CONTROLAR: preserva o comportamento atual
        // (saldo negativo permitido) até o produto ser configurado.
        $produto = $this->produto();

        $avisos = $this->service->validarDisponibilidade($produto, 100);
        $this->assertSame([], $avisos);
    }

    public function test_modo_avisar_retorna_aviso_sem_bloquear(): void
    {
        $produto = $this->produto(['produto_modo_controle_estoque' => EstoqueModoControleEnum::AVISAR]);

        $avisos = $this->service->validarDisponibilidade($produto, 5);

        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('Insumo Teste', $avisos[0]);
    }

    public function test_modo_bloquear_lanca_excecao_sem_saldo_suficiente(): void
    {
        $produto = $this->produto(['produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR]);

        $this->expectException(EstoqueInsuficienteException::class);
        $this->service->validarDisponibilidade($produto, 5);
    }

    public function test_modo_bloquear_nao_lanca_excecao_com_saldo_suficiente(): void
    {
        $produto = $this->produto(['produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR]);
        $this->service->registrarEntrada($produto, 10, 2.00, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);

        $avisos = $this->service->validarDisponibilidade($produto->refresh(), 5);
        $this->assertSame([], $avisos);
    }

    public function test_checagem_de_disponibilidade_expande_ficha_tecnica(): void
    {
        $farinha = $this->produto([
            'produto_descricao' => 'Farinha',
            'produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR,
        ]);

        $pizza = Produto::create([
            'produto_descricao' => 'Pizza',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => \App\Enums\ProdutoTipoEnum::PRODUZIDO->value,
            'produto_ficha_rendimento' => 1,
        ]);

        // 0,3 KG de farinha por pizza, sem perda.
        FichaTecnicaItem::create([
            'fti_produto_id' => $pizza->id,
            'fti_insumo_id' => $farinha->id,
            'fti_quantidade' => 0.3,
            'fti_percentual_perda' => 0,
        ]);

        // Sem saldo de farinha: bloqueia mesmo o produto vendido sendo a pizza.
        $this->expectException(EstoqueInsuficienteException::class);
        $this->service->validarDisponibilidade($pizza, 1);
    }
}
