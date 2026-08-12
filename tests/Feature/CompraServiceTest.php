<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\CompraStatusEnum;
use App\Enums\FormaPagamento;
use App\Enums\Periodicidade;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\FornecedorProduto;
use App\Models\Marca;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\CompraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompraServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompraService $service;

    private int $categoriaId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CompraService::class);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
        $this->userId = User::factory()->create(['name_first' => 'Comprador'])->id;
    }

    private function insumo(string $nome, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
        ], $attrs));
    }

    private function fornecedor(): Prestador
    {
        return Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora X',
            'nome' => 'Distribuidora X',
            'cpf_cnpj' => '12345678000199',
        ]);
    }

    public function test_confirmar_gera_entradas_com_rateio_e_custo_medio(): void
    {
        $forn = $this->fornecedor();
        $queijo = $this->insumo('Queijo', ['produto_controla_lote' => true]);
        $oregano = $this->insumo('Orégano');
        $marcaSadia = Marca::create(['marca_nome' => 'Sadia']);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_numero' => '1001',
            'compra_data_entrada' => now()->toDateString(),
            'compra_valor_frete' => 22,
            'compra_user_id' => $this->userId,
        ]);
        // 2 CX, 1 CX = 10 KG, custo 100/CX => 20 KG, valor 200
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $queijo->id, 'ci_codigo_fornecedor' => 'Q-01',
            'ci_descricao_fornecedor' => 'QUEIJO', 'ci_quantidade_compra' => 2, 'ci_unidade_compra' => 'CX',
            'ci_fator_conversao' => 10, 'ci_custo_unitario_compra' => 100,
            'ci_lote_codigo' => 'L1', 'ci_marca_id' => $marcaSadia->id, 'ci_validade' => now()->addDays(30)->toDateString(),
        ]);
        // 5 KG, custo 4/KG => valor 20
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $oregano->id, 'ci_codigo_fornecedor' => 'O-01',
            'ci_quantidade_compra' => 5, 'ci_unidade_compra' => 'KG', 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 4,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $queijo->refresh();
        $oregano->refresh();
        // Frete 22 rateado: 200/220 -> 20 no queijo, 2 no orégano.
        $this->assertEqualsWithDelta(20.0, (float) $queijo->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(11.0, (float) $queijo->produto_custo_medio, 0.0001); // (200+20)/20
        $this->assertEqualsWithDelta(5.0, (float) $oregano->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(4.4, (float) $oregano->produto_custo_medio, 0.0001); // (20+2)/5

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertEqualsWithDelta(242.0, (float) $compra->compra_valor_total, 0.01);
        $this->assertEquals(2, $compra->movimentacoes()->count());

        // Lote do queijo vinculado ao item de compra, com a marca da NF preservada.
        $loteQueijo = $queijo->lotes()->first();
        $this->assertNotNull($loteQueijo->lote_compra_item_id);
        $this->assertSame('Sadia', $loteQueijo->marca->marca_nome);

        // De-para criado para ambos os itens.
        $this->assertEquals(2, FornecedorProduto::where('fp_prestador_id', $forn->id)->count());
    }

    public function test_confirmar_rastreia_marca_sem_exigir_lote_ou_validade(): void
    {
        $forn = $this->fornecedor();
        // Produto tipo "papel toalha": só controla marca, não lote/validade.
        $papelToalha = $this->insumo('Papel Toalha', ['produto_controla_marca' => true]);
        $marca = Marca::create(['marca_nome' => 'Scala']);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $papelToalha->id,
            'ci_descricao_fornecedor' => 'PAPEL TOALHA', 'ci_quantidade_compra' => 10, 'ci_unidade_compra' => 'UN',
            'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 3, 'ci_marca_id' => $marca->id,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $papelToalha->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $papelToalha->produto_saldo_estoque, 0.001);

        $lote = $papelToalha->lotes()->first();
        $this->assertNotNull($lote, 'Produto que só controla marca também deve gerar registro de lote (para rastrear a marca).');
        $this->assertSame('Scala', $lote->marca->marca_nome);
        $this->assertNull($lote->lote_codigo);
        $this->assertNull($lote->lote_validade);
    }

    public function test_confirmar_registra_hora_da_confirmacao_na_movimentacao(): void
    {
        $forn = $this->fornecedor();
        $insumo = $this->insumo('Farinha');

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 1, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 5,
        ]);

        $antes = now();
        $this->service->confirmar($compra->fresh('itens'));
        $depois = now();

        $movimentacao = $compra->movimentacoes()->first();
        $this->assertNotNull($movimentacao->mov_data);
        $this->assertTrue($movimentacao->mov_data->betweenIncluded($antes->copy()->subSecond(), $depois->copy()->addSecond()));
    }

    public function test_nao_confirma_compra_ja_confirmada(): void
    {
        $forn = $this->fornecedor();
        $insumo = $this->insumo('Farinha');
        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 1, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 5,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $this->expectException(ValidationException::class);
        $this->service->confirmar($compra->fresh('itens'));
    }

    public function test_nao_confirma_item_sem_produto(): void
    {
        $compra = Compra::create([
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => null,
            'ci_quantidade_compra' => 1, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 5,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->confirmar($compra->fresh('itens'));
    }

    private function planoDespesa(string $nome, Comportamento $comportamento = Comportamento::Variavel): PlanoDespesa
    {
        return PlanoDespesa::create([
            'nome' => $nome,
            'comportamento' => $comportamento,
            'periodicidade' => Periodicidade::Eventual,
        ]);
    }

    public function test_gerar_conta_pagar_rateia_despesas_por_plano_do_produto(): void
    {
        $forn = $this->fornecedor();
        $cmv = $this->planoDespesa('CMV / Insumos', Comportamento::Variavel);
        $bebidas = $this->planoDespesa('Bebidas para revenda', Comportamento::Variavel);

        $queijo = $this->insumo('Queijo', ['produto_plano_despesa_id' => $cmv->id]);
        $refri = $this->insumo('Refrigerante', [
            'produto_tipo' => 'revenda',
            'produto_plano_despesa_id' => $bebidas->id,
        ]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_numero' => '2002',
            'compra_data_entrada' => '2026-07-08',
            'compra_user_id' => $this->userId,
        ]);
        // Queijo: 2 * 100 = 200 (CMV)
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $queijo->id,
            'ci_quantidade_compra' => 2, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 100,
        ]);
        // Refrigerante: 10 * 5 = 50 (Bebidas)
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $refri->id,
            'ci_quantidade_compra' => 10, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 5,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $lancamentos = $this->service->gerarContaPagar($compra->refresh(), [
            'parcelas' => [
                ['vencimento' => '2026-08-07', 'valor' => 250.0, 'forma_pagamento' => FormaPagamento::Pix],
            ],
        ]);
        $lancamento = $lancamentos->first();

        // Cabeçalho do título.
        $this->assertCount(1, $lancamentos);
        $this->assertSame(TipoLancamento::Pagar, $lancamento->tipo);
        $this->assertSame(StatusLancamento::Pendente, $lancamento->status);
        $this->assertSame($compra->id, $lancamento->compra_id);
        $this->assertSame($forn->id, $lancamento->favorecido_id);
        $this->assertNull($lancamento->plano_despesa_id); // 2 planos -> classificação no rateio
        $this->assertEqualsWithDelta(250.0, (float) $lancamento->valor, 0.01);
        $this->assertSame('2026-08-07', $lancamento->vencimento->toDateString());
        $this->assertSame(FormaPagamento::Pix, $lancamento->forma_pagamento);
        $this->assertSame(1, $lancamento->parcela_total);
        $this->assertNull($lancamento->parcelaLabel); // 1 única parcela -> sem rótulo "N/T"

        // Rateio: 2 linhas somando o total, cada uma no plano do produto.
        $despesas = $lancamento->despesas()->get();
        $this->assertCount(2, $despesas);
        $this->assertEqualsWithDelta(250.0, (float) $despesas->sum('valor'), 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $despesas->firstWhere('plano_despesa_id', $cmv->id)->valor, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $despesas->firstWhere('plano_despesa_id', $bebidas->id)->valor, 0.01);
    }

    public function test_gerar_conta_pagar_com_multiplas_parcelas_cria_n_lancamentos_com_rateio_proporcional(): void
    {
        $forn = $this->fornecedor();
        $cmv = $this->planoDespesa('CMV / Insumos', Comportamento::Variavel);
        $bebidas = $this->planoDespesa('Bebidas para revenda', Comportamento::Variavel);

        $queijo = $this->insumo('Queijo', ['produto_plano_despesa_id' => $cmv->id]);
        $refri = $this->insumo('Refrigerante', [
            'produto_tipo' => 'revenda',
            'produto_plano_despesa_id' => $bebidas->id,
        ]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_numero' => '3003',
            'compra_data_entrada' => '2026-07-08',
            'compra_user_id' => $this->userId,
        ]);
        // Queijo: 2 * 100 = 200 (CMV)
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $queijo->id,
            'ci_quantidade_compra' => 2, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 100,
        ]);
        // Refrigerante: 20 * 5 = 100 (Bebidas)
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $refri->id,
            'ci_quantidade_compra' => 20, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 5,
        ]);

        $this->service->confirmar($compra->fresh('itens'));
        // Total da compra: 300 (200 CMV + 100 Bebidas). 3 parcelas: 50/30/20%.
        $lancamentos = $this->service->gerarContaPagar($compra->refresh(), [
            'parcelas' => [
                ['vencimento' => '2026-08-07', 'valor' => 150.0, 'forma_pagamento' => FormaPagamento::Boleto],
                ['vencimento' => '2026-08-14', 'valor' => 90.0, 'forma_pagamento' => FormaPagamento::Boleto],
                ['vencimento' => '2026-08-21', 'valor' => 60.0, 'forma_pagamento' => FormaPagamento::Pix],
            ],
        ]);

        $this->assertCount(3, $lancamentos);

        foreach ($lancamentos as $indice => $lancamento) {
            $this->assertSame($indice + 1, $lancamento->parcela_numero);
            $this->assertSame(3, $lancamento->parcela_total);
            $this->assertSame($compra->id, $lancamento->compra_id);

            // Rateio proporcional: 2/3 CMV, 1/3 Bebidas em cada parcela.
            $despesas = $lancamento->despesas()->get();
            $this->assertCount(2, $despesas);
            $this->assertEqualsWithDelta((float) $lancamento->valor, (float) $despesas->sum('valor'), 0.01);
            $cmvLinha = (float) $despesas->firstWhere('plano_despesa_id', $cmv->id)->valor;
            $bebidasLinha = (float) $despesas->firstWhere('plano_despesa_id', $bebidas->id)->valor;
            $this->assertEqualsWithDelta((float) $lancamento->valor * (2 / 3), $cmvLinha, 0.02);
            $this->assertEqualsWithDelta((float) $lancamento->valor * (1 / 3), $bebidasLinha, 0.02);
        }

        $this->assertEqualsWithDelta(300.0, (float) $lancamentos->sum('valor'), 0.01);
    }

    public function test_gerar_conta_pagar_permite_soma_de_parcelas_diferente_do_valor_da_compra_juros_embutido(): void
    {
        $forn = $this->fornecedor();
        $plano = $this->planoDespesa('CMV / Insumos');
        $insumo = $this->insumo('Farinha', ['produto_plano_despesa_id' => $plano->id]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 10, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 10,
        ]);

        $this->service->confirmar($compra->fresh('itens'));
        // Compra = 100. 3 parcelas de 35 cada = 105 (5% de juros embutido).
        $lancamentos = $this->service->gerarContaPagar($compra->refresh(), [
            'parcelas' => [
                ['vencimento' => now()->addDays(30)->toDateString(), 'valor' => 35.0],
                ['vencimento' => now()->addDays(60)->toDateString(), 'valor' => 35.0],
                ['vencimento' => now()->addDays(90)->toDateString(), 'valor' => 35.0],
            ],
        ]);

        $this->assertCount(3, $lancamentos);
        $this->assertEqualsWithDelta(105.0, (float) $lancamentos->sum('valor'), 0.01);
    }

    public function test_gerar_conta_pagar_e_idempotente(): void
    {
        $forn = $this->fornecedor();
        $plano = $this->planoDespesa('CMV / Insumos');
        $insumo = $this->insumo('Farinha', ['produto_plano_despesa_id' => $plano->id]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 3, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 10,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $dados = ['parcelas' => [['vencimento' => now()->toDateString(), 'valor' => 30.0]]];
        $lancamentos = $this->service->gerarContaPagar($compra->refresh(), $dados);
        // 1 plano -> cabeçalho recebe o plano e o snapshot do comportamento.
        $this->assertSame($plano->id, $lancamentos->first()->plano_despesa_id);
        $this->assertSame(Comportamento::Variavel, $lancamentos->first()->comportamento);

        $this->expectException(ValidationException::class);
        $this->service->gerarContaPagar($compra->refresh(), $dados);
    }

    public function test_gerar_conta_pagar_falha_sem_parcelas(): void
    {
        $forn = $this->fornecedor();
        $insumo = $this->insumo('Farinha');

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 1, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 5,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $this->expectException(ValidationException::class);
        $this->service->gerarContaPagar($compra->refresh(), ['parcelas' => []]);
    }

    public function test_gerar_conta_pagar_com_parcela_ja_paga_nasce_com_status_pago(): void
    {
        $forn = $this->fornecedor();
        $plano = $this->planoDespesa('CMV / Insumos');
        $insumo = $this->insumo('Farinha', ['produto_plano_despesa_id' => $plano->id]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 10, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 10,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $lancamentos = $this->service->gerarContaPagar($compra->refresh(), [
            'parcelas' => [
                ['vencimento' => '2026-08-07', 'valor' => 100.0, 'forma_pagamento' => FormaPagamento::Pix, 'ja_pago' => true],
            ],
        ]);
        $lancamento = $lancamentos->first();

        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertSame('2026-08-07', $lancamento->data_pagamento->toDateString());
        $this->assertSame(FormaPagamento::Pix, $lancamento->forma_pagamento);
        $this->assertCount(1, $lancamento->pagamentos);
        $this->assertEqualsWithDelta(100.0, (float) $lancamento->pagamentos->first()->valor, 0.01);
        $this->assertSame(FormaPagamento::Pix, $lancamento->pagamentos->first()->forma_pagamento);
        $this->assertSame('2026-08-07', $lancamento->pagamentos->first()->data_pagamento->toDateString());
        $this->assertSame(0.0, $lancamento->valorRestante);
        $this->assertTrue($lancamento->estaQuitado);
    }

    public function test_gerar_conta_pagar_sem_ja_pago_mantem_comportamento_atual(): void
    {
        $forn = $this->fornecedor();
        $plano = $this->planoDespesa('CMV / Insumos');
        $insumo = $this->insumo('Farinha', ['produto_plano_despesa_id' => $plano->id]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 10, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 10,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $lancamentos = $this->service->gerarContaPagar($compra->refresh(), [
            'parcelas' => [
                ['vencimento' => now()->addDays(30)->toDateString(), 'valor' => 100.0, 'ja_pago' => false],
            ],
        ]);
        $lancamento = $lancamentos->first();

        $this->assertSame(StatusLancamento::Pendente, $lancamento->status);
        $this->assertNull($lancamento->data_pagamento);
        $this->assertCount(0, $lancamento->pagamentos);
    }

    public function test_gerar_conta_pagar_com_parcelas_mistas_pagas_e_pendentes(): void
    {
        $forn = $this->fornecedor();
        $plano = $this->planoDespesa('CMV / Insumos');
        $insumo = $this->insumo('Farinha', ['produto_plano_despesa_id' => $plano->id]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 100, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 10,
        ]);

        $this->service->confirmar($compra->fresh('itens'));
        // Compra de 1000: 600 já pagos via Pix + 400 pendentes.
        $lancamentos = $this->service->gerarContaPagar($compra->refresh(), [
            'parcelas' => [
                ['vencimento' => now()->toDateString(), 'valor' => 600.0, 'forma_pagamento' => FormaPagamento::Pix, 'ja_pago' => true],
                ['vencimento' => now()->addDays(15)->toDateString(), 'valor' => 400.0, 'ja_pago' => false],
            ],
        ]);

        $this->assertCount(2, $lancamentos);

        $pago = $lancamentos->firstWhere('parcela_numero', 1);
        $this->assertSame(2, $pago->parcela_total);
        $this->assertSame(StatusLancamento::Pago, $pago->status);
        $this->assertCount(1, $pago->pagamentos);
        $this->assertEqualsWithDelta(600.0, (float) $pago->valor, 0.01);
        // Rateio da parcela paga preservado normalmente.
        $this->assertEqualsWithDelta(600.0, (float) $pago->despesas()->sum('valor'), 0.01);

        $pendente = $lancamentos->firstWhere('parcela_numero', 2);
        $this->assertSame(StatusLancamento::Pendente, $pendente->status);
        $this->assertCount(0, $pendente->pagamentos);
        $this->assertEqualsWithDelta(400.0, (float) $pendente->valor, 0.01);
    }

    public function test_gerar_conta_pagar_ja_pago_sem_forma_pagamento_nao_estoura(): void
    {
        $forn = $this->fornecedor();
        $plano = $this->planoDespesa('CMV / Insumos');
        $insumo = $this->insumo('Farinha', ['produto_plano_despesa_id' => $plano->id]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 5, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 10,
        ]);

        $this->service->confirmar($compra->fresh('itens'));

        $lancamentos = $this->service->gerarContaPagar($compra->refresh(), [
            'parcelas' => [
                ['vencimento' => now()->toDateString(), 'valor' => 50.0, 'ja_pago' => true],
            ],
        ]);
        $lancamento = $lancamentos->first();

        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertNull($lancamento->pagamentos->first()->forma_pagamento);
    }

    public function test_nao_gera_conta_pagar_de_compra_nao_confirmada(): void
    {
        $compra = Compra::create([
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->gerarContaPagar($compra, ['parcelas' => [['vencimento' => now()->toDateString(), 'valor' => 10.0]]]);
    }
}
