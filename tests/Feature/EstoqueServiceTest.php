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

    public function test_entrada_sem_controlar_lote_mas_controlando_marca_cria_lote_sem_codigo_nem_validade(): void
    {
        $produto = $this->produto(['produto_controla_marca' => true]);
        $marca = \App\Models\Marca::create(['marca_nome' => 'Scala']);

        $this->service->registrarEntrada($produto, 10, 3.00, MovimentacaoOrigemEnum::COMPRA, [
            'user_id' => $this->userId, 'marca_id' => $marca->id,
        ]);
        $produto->refresh();

        $this->assertEqualsWithDelta(10.0, (float) $produto->produto_saldo_estoque, 0.001);
        $lote = $produto->lotes()->first();
        $this->assertNotNull($lote);
        $this->assertSame('Scala', $lote->marca->marca_nome);
        $this->assertNull($lote->lote_codigo);
        $this->assertNull($lote->lote_validade);
    }

    public function test_saida_consome_lote_de_produto_que_so_controla_marca_em_fifo(): void
    {
        $produto = $this->produto(['produto_controla_marca' => true]);
        $marcaA = \App\Models\Marca::create(['marca_nome' => 'Marca A']);
        $marcaB = \App\Models\Marca::create(['marca_nome' => 'Marca B']);

        $this->service->registrarEntrada($produto, 5, 2.00, MovimentacaoOrigemEnum::COMPRA, [
            'user_id' => $this->userId, 'marca_id' => $marcaA->id,
        ]);
        $this->service->registrarEntrada($produto, 5, 2.00, MovimentacaoOrigemEnum::COMPRA, [
            'user_id' => $this->userId, 'marca_id' => $marcaB->id,
        ]);

        // Sem validade, a ordem de consumo é a de entrada (FIFO): a marca A primeiro.
        $movs = $this->service->registrarSaida($produto, 6, MovimentacaoOrigemEnum::VENDA, ['user_id' => $this->userId]);

        $this->assertCount(2, $movs);
        $this->assertSame('Marca A', $movs->first()->lote->marca->marca_nome);
        $this->assertEqualsWithDelta(5.0, (float) $movs->first()->mov_quantidade, 0.001);
        $this->assertSame('Marca B', $movs->last()->lote->marca->marca_nome);
        $this->assertEqualsWithDelta(1.0, (float) $movs->last()->mov_quantidade, 0.001);
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

    /** Massa e pizza: farinha -> massa (insumo_produzido) -> pizza (produzido). */
    private function montarCadeiaMassaEPizza(bool $massaControlaEstoque): array
    {
        $farinha = $this->produto(['produto_descricao' => 'Farinha']);

        $massa = Produto::create([
            'produto_descricao' => 'Massa',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => \App\Enums\ProdutoTipoEnum::INSUMO_PRODUZIDO->value,
            'produto_controla_estoque' => $massaControlaEstoque,
            'produto_ficha_rendimento' => 10,
        ]);
        // 1 KG de farinha rende 10 unidades de massa.
        FichaTecnicaItem::create([
            'fti_produto_id' => $massa->id,
            'fti_insumo_id' => $farinha->id,
            'fti_quantidade' => 1,
            'fti_percentual_perda' => 0,
        ]);

        $pizza = Produto::create([
            'produto_descricao' => 'Pizza',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => \App\Enums\ProdutoTipoEnum::PRODUZIDO->value,
            'produto_ficha_rendimento' => 1,
        ]);
        // 0,3 unidade de massa por pizza.
        FichaTecnicaItem::create([
            'fti_produto_id' => $pizza->id,
            'fti_insumo_id' => $massa->id,
            'fti_quantidade' => 0.3,
            'fti_percentual_perda' => 0,
        ]);

        return [$farinha, $massa, $pizza];
    }

    public function test_itens_consumo_para_no_insumo_produzido_com_saldo_proprio(): void
    {
        [$farinha, $massa, $pizza] = $this->montarCadeiaMassaEPizza(massaControlaEstoque: true);

        $itens = $this->service->itensConsumo($pizza, 1);

        // Lote já produzido: baixa direto da massa, sem re-explodir a ficha dela.
        $this->assertCount(1, $itens);
        $this->assertSame($massa->id, $itens->first()['produto']->id);
        $this->assertEqualsWithDelta(0.3, $itens->first()['quantidade'], 0.0001);
    }

    public function test_itens_consumo_expande_insumo_produzido_virtual_ate_a_materia_prima(): void
    {
        [$farinha, $massa, $pizza] = $this->montarCadeiaMassaEPizza(massaControlaEstoque: false);

        $itens = $this->service->itensConsumo($pizza, 1);

        // Sem saldo próprio: continua explodindo até a farinha.
        // 1 pizza -> 0,3 massa -> 0,3 * 1 / 10 (rendimento) = 0,03 KG de farinha.
        $this->assertCount(1, $itens);
        $this->assertSame($farinha->id, $itens->first()['produto']->id);
        $this->assertEqualsWithDelta(0.03, $itens->first()['quantidade'], 0.0001);
    }

    public function test_itens_consumo_protege_contra_ciclo_entre_insumos_produzidos(): void
    {
        $a = Produto::create([
            'produto_descricao' => 'A', 'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => \App\Enums\ProdutoTipoEnum::INSUMO_PRODUZIDO->value,
            'produto_controla_estoque' => false,
        ]);
        $b = Produto::create([
            'produto_descricao' => 'B', 'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => \App\Enums\ProdutoTipoEnum::INSUMO_PRODUZIDO->value,
            'produto_controla_estoque' => false,
        ]);
        // Ciclo criado direto no banco (contornando a validação de cadastro).
        FichaTecnicaItem::create(['fti_produto_id' => $a->id, 'fti_insumo_id' => $b->id, 'fti_quantidade' => 1, 'fti_percentual_perda' => 0]);
        FichaTecnicaItem::create(['fti_produto_id' => $b->id, 'fti_insumo_id' => $a->id, 'fti_quantidade' => 1, 'fti_percentual_perda' => 0]);

        $itens = $this->service->itensConsumo($a, 1);

        $this->assertNotEmpty($itens);
    }

    public function test_registrar_producao_baixa_materia_prima_e_credita_saldo_com_lote(): void
    {
        [$farinha, $massa, $pizza] = $this->montarCadeiaMassaEPizza(massaControlaEstoque: true);
        $massa->update(['produto_controla_lote' => true]);
        $this->service->registrarEntrada($farinha, 10, 5.00, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);

        $mov = $this->service->registrarProducao($massa->refresh(), 10, [
            'lote_codigo' => 'M-1',
            'validade' => '2026-08-01',
            'user_id' => $this->userId,
        ]);

        // 10 unidades de massa / rendimento 10 = 1 KG de farinha consumido.
        $this->assertEqualsWithDelta(9.0, (float) $farinha->refresh()->produto_saldo_estoque, 0.001);

        // Custo da massa: (1 KG * 5,00) / 10 = 0,50 por unidade.
        $massa->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $massa->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(0.5, (float) $massa->produto_custo_medio, 0.0001);
        $this->assertEqualsWithDelta(0.5, (float) $mov->mov_custo_unitario, 0.0001);
        $this->assertSame(MovimentacaoOrigemEnum::PRODUCAO, $mov->mov_origem);

        $lote = $massa->lotes()->where('lote_codigo', 'M-1')->first();
        $this->assertNotNull($lote);
        $this->assertEqualsWithDelta(10.0, (float) $lote->lote_qtd_atual, 0.001);
        $this->assertSame('2026-08-01', $lote->lote_validade->toDateString());
    }

    public function test_registrar_producao_lanca_excecao_sem_saldo_de_materia_prima(): void
    {
        [$farinha, $massa, $pizza] = $this->montarCadeiaMassaEPizza(massaControlaEstoque: true);
        $farinha->update(['produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR]);

        // Sem entrada de farinha: saldo zero, modo BLOQUEAR.
        $this->expectException(EstoqueInsuficienteException::class);
        $this->service->registrarProducao($massa->refresh(), 10, ['user_id' => $this->userId]);
    }

    public function test_registrar_producao_lanca_excecao_se_produto_nao_controla_estoque(): void
    {
        [$farinha, $massa, $pizza] = $this->montarCadeiaMassaEPizza(massaControlaEstoque: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->registrarProducao($massa, 10, ['user_id' => $this->userId]);
    }

    public function test_registrar_producao_lanca_excecao_sem_ficha_tecnica(): void
    {
        $produto = $this->produto(['produto_descricao' => 'Sem Ficha']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->registrarProducao($produto, 10, ['user_id' => $this->userId]);
    }
}
