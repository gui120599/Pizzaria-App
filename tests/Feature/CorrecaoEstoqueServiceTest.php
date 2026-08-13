<?php

namespace Tests\Feature;

use App\Enums\MovimentacaoOrigemEnum;
use App\Models\Categoria;
use App\Models\FichaTecnicaItem;
use App\Models\ItensVenda;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\CorrecaoEstoqueService;
use App\Services\EstoqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CorrecaoEstoqueServiceTest extends TestCase
{
    use RefreshDatabase;

    private EstoqueService $estoque;

    private CorrecaoEstoqueService $correcao;

    private int $categoriaId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estoque = app(EstoqueService::class);
        $this->correcao = app(CorrecaoEstoqueService::class);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
        $this->userId = User::factory()->create(['name_first' => 'Operador'])->id;
        $this->actingAs(User::find($this->userId));
    }

    private function produto(array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => 'Molho de Tomate Lata',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_controla_estoque' => true,
        ], $attrs));
    }

    private function itemVenda(Venda $venda, Produto $produto, float $custoUnitario): ItensVenda
    {
        return ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $venda->id,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_custo_unitario' => $custoUnitario,
        ]);
    }

    public function test_sem_saidas_depois_corrige_saldo_e_custo_medio(): void
    {
        $produto = $this->produto();

        // Comprou 1 CX de 15un por R$150 (R$10/un), mas lançou como 1un a R$150.
        $errada = $this->estoque->registrarEntrada($produto, 1, 150, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);

        $correcao = $this->correcao->aplicar($errada, 15, 10, 'Item não conferido — 1 CX de 15un lançada como 1un.');

        $produto->refresh();
        $this->assertEqualsWithDelta(15.0, (float) $produto->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $produto->produto_custo_medio, 0.0001);
        $this->assertSame($produto->id, $correcao->ec_produto_id);
        $this->assertSame($errada->id, $correcao->ec_movimentacao_id);
        $this->assertSame($this->userId, $correcao->ec_user_id);
    }

    public function test_grava_custo_medio_apos_em_entradas_e_saidas(): void
    {
        $produto = $this->produto();

        $entrada1 = $this->estoque->registrarEntrada($produto, 10, 4, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);
        $this->assertEqualsWithDelta(4.0, (float) $entrada1->mov_custo_medio_apos, 0.0001);

        $entrada2 = $this->estoque->registrarEntrada($produto, 10, 6, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);
        $this->assertEqualsWithDelta(5.0, (float) $entrada2->mov_custo_medio_apos, 0.0001);

        $saidas = $this->estoque->registrarSaida($produto, 5, MovimentacaoOrigemEnum::VENDA, ['user_id' => $this->userId]);
        // Saída nunca muda o médio — grava o mesmo valor vigente no momento.
        $this->assertEqualsWithDelta(5.0, (float) $saidas->first()->mov_custo_medio_apos, 0.0001);
    }

    /**
     * Uma entrada posterior à corrigida não muda sua própria quantidade/custo
     * (o preço pago naquela compra continua sendo aquele), mas o médio
     * resultante dela sim — mov_custo_medio_apos precisa acompanhar isso
     * mesmo quando a linha não é marcada como "mudou" pelos outros campos.
     */
    public function test_corrige_custo_medio_apos_em_entrada_posterior_nao_alterada(): void
    {
        $produto = $this->produto();

        $errada = $this->estoque->registrarEntrada($produto, 1, 150, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);
        $posterior = $this->estoque->registrarEntrada($produto, 10, 5, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);

        // Antes da correção: médio contaminado pela entrada errada.
        $this->assertEqualsWithDelta(200 / 11, (float) $posterior->mov_custo_medio_apos, 0.0001);

        $this->correcao->aplicar($errada, 15, 10, 'Correção de teste');

        $posterior->refresh();
        // mov_quantidade/mov_custo_unitario da entrada posterior continuam os mesmos...
        $this->assertEqualsWithDelta(10.0, (float) $posterior->mov_quantidade, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $posterior->mov_custo_unitario, 0.0001);
        // ...mas o médio resultante dela precisa refletir a correção em cadeia.
        $this->assertEqualsWithDelta(8.0, (float) $posterior->mov_custo_medio_apos, 0.0001);
    }

    public function test_venda_direta_depois_da_entrada_errada_tem_custo_corrigido(): void
    {
        $produto = $this->produto();

        $errada = $this->estoque->registrarEntrada($produto, 1, 150, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);

        $venda = Venda::create([]);
        $saidas = $this->estoque->registrarSaida($produto, 1, MovimentacaoOrigemEnum::VENDA, [
            'user_id' => $this->userId,
            'venda_id' => $venda->id,
        ]);
        $itemVenda = $this->itemVenda($venda, $produto, (float) $saidas->first()->mov_custo_unitario);

        // Antes de corrigir: a venda saiu valorada pelo custo médio inflado (150).
        $this->assertEqualsWithDelta(150.0, (float) $itemVenda->item_venda_custo_unitario, 0.01);

        $this->correcao->aplicar($errada, 15, 10, 'Correção de teste');

        $produto->refresh();
        $itemVenda->refresh();
        $saida = $saidas->first()->fresh();

        // Saldo: 15 entraram, 1 saiu.
        $this->assertEqualsWithDelta(14.0, (float) $produto->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $produto->produto_custo_medio, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $saida->mov_custo_unitario, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $saida->mov_custo_total, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $itemVenda->item_venda_custo_unitario, 0.0001);
    }

    public function test_consumo_via_ficha_tecnica_nao_altera_itens_vendas_mas_e_reportado(): void
    {
        $insumo = $this->produto(['produto_descricao' => 'Muçarela Ralada']);
        $pizza = $this->produto(['produto_descricao' => 'Pizza Muçarela', 'produto_tipo' => 'produzido']);

        FichaTecnicaItem::create([
            'fti_produto_id' => $pizza->id,
            'fti_insumo_id' => $insumo->id,
            'fti_quantidade' => 1,
        ]);

        $errada = $this->estoque->registrarEntrada($insumo, 1, 150, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);

        $venda = Venda::create([]);
        // Simula a baixa do insumo feita pela venda da pizza (via ficha técnica) —
        // mov_venda_id aponta pra venda, mas quem foi vendido foi a pizza, não o insumo.
        $this->estoque->registrarSaida($insumo, 1, MovimentacaoOrigemEnum::VENDA, [
            'user_id' => $this->userId,
            'venda_id' => $venda->id,
        ]);
        $itemVendaPizza = $this->itemVenda($venda, $pizza, 150.0);

        $resultado = $this->correcao->simular($insumo, $errada, 15, 10);
        $this->assertCount(0, $resultado['vendas_afetadas']);
        $this->assertCount(1, $resultado['vendas_indiretas_ignoradas']);

        $this->correcao->aplicar($errada, 15, 10, 'Correção de teste');

        // O item vendido foi a pizza — seu custo snapshotado não é tocado
        // automaticamente (exigiria reconstituir a ficha técnica retroativamente).
        $itemVendaPizza->refresh();
        $this->assertEqualsWithDelta(150.0, (float) $itemVendaPizza->item_venda_custo_unitario, 0.01);
    }

    public function test_corrige_lote_criado_pela_entrada_errada(): void
    {
        $produto = $this->produto(['produto_controla_lote' => true]);

        $errada = $this->estoque->registrarEntrada($produto, 1, 150, MovimentacaoOrigemEnum::COMPRA, [
            'user_id' => $this->userId,
            'lote_codigo' => 'L-ERR-1',
            'validade' => '2026-12-31',
        ]);

        $this->correcao->aplicar($errada, 15, 10, 'Correção de teste');

        $lote = $errada->lote()->first()->fresh();
        $this->assertEqualsWithDelta(15.0, (float) $lote->lote_qtd_inicial, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $lote->lote_qtd_atual, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $lote->lote_custo_unitario, 0.0001);
    }

    public function test_exclui_movimentacao_solta_e_recalcula_saldo_e_custo_medio(): void
    {
        $produto = $this->produto();

        $this->estoque->registrarEntrada($produto, 10, 4, MovimentacaoOrigemEnum::PRODUCAO, ['user_id' => $this->userId]);
        // Lançamento duplicado por engano na ação "Movimentar" — sem compra/venda por trás.
        $duplicada = $this->estoque->registrarEntrada($produto, 5, 10, MovimentacaoOrigemEnum::TRANSFERENCIA, ['user_id' => $this->userId]);

        $produto->refresh();
        $this->assertEqualsWithDelta(15.0, (float) $produto->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(6.0, (float) $produto->produto_custo_medio, 0.0001);

        $resultado = $this->correcao->simularExclusao($produto, $duplicada);
        $this->assertEqualsWithDelta(10.0, $resultado['produto_saldo_novo'], 0.001);

        $correcao = $this->correcao->aplicarExclusao($duplicada, 'Lançamento duplicado por engano.');

        $produto->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $produto->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $produto->produto_custo_medio, 0.0001);
        $this->assertNotNull($duplicada->fresh()->deleted_at);
        $this->assertSame($duplicada->id, $correcao->ec_movimentacao_id);
    }

    public function test_nao_exclui_movimentacao_vinculada_a_venda(): void
    {
        $produto = $this->produto();
        $this->estoque->registrarEntrada($produto, 10, 4, MovimentacaoOrigemEnum::COMPRA, ['user_id' => $this->userId]);

        $venda = Venda::create([]);
        $saidas = $this->estoque->registrarSaida($produto, 2, MovimentacaoOrigemEnum::VENDA, [
            'user_id' => $this->userId,
            'venda_id' => $venda->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->correcao->aplicarExclusao($saidas->first(), 'Tentativa inválida');
    }

    public function test_nao_exclui_movimentacao_vinculada_a_documento_de_referencia(): void
    {
        $produto = $this->produto();

        $errada = $this->estoque->registrarEntrada($produto, 5, 4, MovimentacaoOrigemEnum::COMPRA, [
            'user_id' => $this->userId,
            'referencia' => $produto,
        ]);

        $this->expectException(ValidationException::class);
        $this->correcao->aplicarExclusao($errada, 'Tentativa inválida');
    }

    /**
     * Deixar o saldo negativo temporariamente é permitido de propósito: o
     * usuário pode estar corrigindo uma movimentação sabendo que vai
     * reconciliar a diferença com um Balanço físico logo em seguida.
     */
    public function test_permite_saldo_negativo_e_sinaliza_na_previa(): void
    {
        $produto = $this->produto();

        $this->estoque->registrarEntrada($produto, 10, 4, MovimentacaoOrigemEnum::PRODUCAO, ['user_id' => $this->userId]);
        $duplicada = $this->estoque->registrarEntrada($produto, 5, 4, MovimentacaoOrigemEnum::TRANSFERENCIA, ['user_id' => $this->userId]);
        $this->estoque->registrarSaida($produto, 12, MovimentacaoOrigemEnum::PERDA, ['user_id' => $this->userId]);

        $resultado = $this->correcao->simularExclusao($produto, $duplicada);
        $this->assertTrue($resultado['saldo_ficaria_negativo']);

        $this->correcao->aplicarExclusao($duplicada, 'Corrige agora, balanço depois.');

        $produto->refresh();
        $this->assertEqualsWithDelta(-2.0, (float) $produto->produto_saldo_estoque, 0.001);
    }
}
