<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\CompraStatusEnum;
use App\Enums\FormaPagamento;
use App\Enums\Periodicidade;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Enums\VencimentoOrigemEnum;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\ContaFinanceira;
use App\Models\FornecedorCondicaoCompra;
use App\Models\PlanoDespesa;
use App\Models\PrazoPagamento;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\CompraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ConfirmarCompraContaPagarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function compraComItem(float $custoUnitario = 25.0, float $quantidade = 4.0): Compra
    {
        $categoria = Categoria::create(['categoria_nome' => 'Teste']);
        $plano = PlanoDespesa::create([
            'nome' => 'CMV / Insumos',
            'comportamento' => Comportamento::Variavel,
            'periodicidade' => Periodicidade::Eventual,
        ]);
        $forn = Prestador::create([
            'tipo' => 'pj', 'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora X', 'nome' => 'Distribuidora X',
        ]);
        $insumo = Produto::create([
            'produto_descricao' => 'Farinha',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
            'produto_plano_despesa_id' => $plano->id,
        ]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_numero' => '3003',
            'compra_data_entrada' => '2026-07-08',
            'compra_user_id' => auth()->id(),
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => $quantidade, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => $custoUnitario,
        ]);

        // Fora do form do Filament, criar o item direto via Eloquent não recalcula o
        // total sozinho (isso normalmente acontece via ItensRelationManager/EditCompra::mount).
        app(CompraService::class)->recalcularTotais($compra->fresh('itens'));

        return $compra->fresh();
    }

    public function test_acao_da_tabela_confirma_e_gera_conta_a_pagar(): void
    {
        $compra = $this->compraComItem();

        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-07',
                'parcelas' => [
                    ['vencimento' => '2026-08-07', 'valor' => '100.00', 'forma_pagamento' => null],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);

        $lancamento = $compra->lancamentos()->first();
        $this->assertNotNull($lancamento);
        $this->assertCount(1, $compra->lancamentos);
        $this->assertSame(TipoLancamento::Pagar, $lancamento->tipo);
        $this->assertSame(StatusLancamento::Pendente, $lancamento->status);
        $this->assertEqualsWithDelta(100.0, (float) $lancamento->valor, 0.01); // 4 * 25
        $this->assertSame('2026-08-07', $lancamento->vencimento->toDateString());
        $this->assertCount(1, $lancamento->despesas);
        $this->assertSame(1, $lancamento->parcela_total);
        $this->assertNull($lancamento->parcelaLabel); // única parcela -> sem rótulo "N/T"
    }

    public function test_acao_da_pagina_de_edicao_confirma_e_gera_conta_a_pagar(): void
    {
        $compra = $this->compraComItem();

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->callAction('confirmar', data: [
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-10',
                'parcelas' => [
                    ['vencimento' => '2026-08-10', 'valor' => '100.00', 'forma_pagamento' => null],
                ],
            ])
            ->assertHasNoActionErrors();

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertNotNull($compra->lancamentos()->first());
        $this->assertSame('2026-08-10', $compra->lancamentos()->first()->vencimento->toDateString());
    }

    public function test_pode_confirmar_sem_gerar_conta_a_pagar(): void
    {
        $compra = $this->compraComItem();

        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'gerar_conta_pagar' => false,
            ])
            ->assertHasNoTableActionErrors();

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->compra_status);
        $this->assertSame(0, $compra->lancamentos()->count());
    }

    public function test_confirmar_permite_forma_pagamento_diferente_por_parcela(): void
    {
        // 4 * 30 = 120, dividido em 3 parcelas de 40 cada, com formas diferentes.
        $compra = $this->compraComItem(custoUnitario: 30.0, quantidade: 4.0);

        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-07',
                'parcelas' => [
                    ['vencimento' => '2026-08-07', 'valor' => '40.00', 'forma_pagamento' => FormaPagamento::Dinheiro->value],
                    ['vencimento' => '2026-08-14', 'valor' => '40.00', 'forma_pagamento' => FormaPagamento::Boleto->value],
                    ['vencimento' => '2026-08-21', 'valor' => '40.00', 'forma_pagamento' => FormaPagamento::Pix->value],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $compra->refresh();
        $lancamentos = $compra->lancamentos()->orderBy('parcela_numero')->get();

        $this->assertCount(3, $lancamentos);
        $this->assertSame(FormaPagamento::Dinheiro, $lancamentos[0]->forma_pagamento);
        $this->assertSame(FormaPagamento::Boleto, $lancamentos[1]->forma_pagamento);
        $this->assertSame(FormaPagamento::Pix, $lancamentos[2]->forma_pagamento);
        foreach ($lancamentos as $indice => $lancamento) {
            $this->assertSame($indice + 1, $lancamento->parcela_numero);
            $this->assertSame(3, $lancamento->parcela_total);
        }
    }

    public function test_confirmar_com_parcela_ja_paga_gera_titulo_com_status_pago(): void
    {
        $compra = $this->compraComItem(); // total 100

        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-07',
                'parcelas' => [
                    ['vencimento' => '2026-08-07', 'valor' => '100.00', 'forma_pagamento' => FormaPagamento::Pix->value, 'ja_pago' => true, 'conta_financeira_id' => $this->contaParaBaixa()->id],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $lancamento = $compra->refresh()->lancamentos()->first();
        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertCount(1, $lancamento->pagamentos);
        $this->assertEqualsWithDelta(100.0, (float) $lancamento->pagamentos->first()->valor, 0.01);
        $this->assertSame(FormaPagamento::Pix, $lancamento->pagamentos->first()->forma_pagamento);
        $this->assertSame('2026-08-07', $lancamento->pagamentos->first()->data_pagamento->toDateString());
    }

    /** Regressão: informar 'ja_pago' => false explicitamente mantém o comportamento de hoje. */
    public function test_confirmar_com_parcela_nao_paga_mantem_comportamento_atual(): void
    {
        $compra = $this->compraComItem(); // total 100

        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-07',
                'parcelas' => [
                    ['vencimento' => '2026-08-07', 'valor' => '100.00', 'forma_pagamento' => null, 'ja_pago' => false],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $lancamento = $compra->refresh()->lancamentos()->first();
        $this->assertSame(StatusLancamento::Pendente, $lancamento->status);
        $this->assertCount(0, $lancamento->pagamentos);
    }

    public function test_confirmar_com_parcelas_mistas_pagas_e_pendentes(): void
    {
        // 4 * 25 = 100, dividido em 60 já pago (Pix) + 40 pendente.
        $compra = $this->compraComItem();

        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-07',
                'parcelas' => [
                    ['vencimento' => '2026-08-07', 'valor' => '60.00', 'forma_pagamento' => FormaPagamento::Pix->value, 'ja_pago' => true, 'conta_financeira_id' => $this->contaParaBaixa()->id],
                    ['vencimento' => '2026-08-22', 'valor' => '40.00', 'forma_pagamento' => null, 'ja_pago' => false],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $compra->refresh();
        $lancamentos = $compra->lancamentos()->orderBy('parcela_numero')->get();

        $this->assertCount(2, $lancamentos);
        $this->assertSame(StatusLancamento::Pago, $lancamentos[0]->status);
        $this->assertCount(1, $lancamentos[0]->pagamentos);
        $this->assertSame(StatusLancamento::Pendente, $lancamentos[1]->status);
        $this->assertCount(0, $lancamentos[1]->pagamentos);
    }

    public function test_confirmar_associa_prazo_de_pagamento_aos_lancamentos_gerados(): void
    {
        $compra = $this->compraComItem(custoUnitario: 30.0, quantidade: 4.0); // total 120

        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '30/60/90']);
        $prazo->parcelas()->createMany([
            ['parcela_numero' => 1, 'parcela_dias' => 30, 'parcela_percentual' => 33.34],
            ['parcela_numero' => 2, 'parcela_dias' => 60, 'parcela_percentual' => 33.33],
            ['parcela_numero' => 3, 'parcela_dias' => 90, 'parcela_percentual' => 33.33],
        ]);

        // Parcelas como o JS calcularia a partir do prazo (data-base 2026-08-07 + dias).
        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-07',
                'prazo_pagamento_id' => $prazo->id,
                'parcelas' => [
                    ['vencimento' => '2026-09-06', 'valor' => '40.01', 'forma_pagamento' => null],
                    ['vencimento' => '2026-10-06', 'valor' => '39.99', 'forma_pagamento' => null],
                    ['vencimento' => '2026-11-05', 'valor' => '39.99', 'forma_pagamento' => null],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $compra->refresh();
        $lancamentos = $compra->lancamentos()->orderBy('parcela_numero')->get();

        $this->assertCount(3, $lancamentos);
        $this->assertTrue($lancamentos->every(fn ($l) => $l->prazo_pagamento_id === $prazo->id));
        $this->assertEqualsWithDelta(119.99, (float) $lancamentos->sum('valor'), 0.01);
    }

    /**
     * Reproduz um possível cenário real: o total da compra muda no banco (ex.: edição
     * de item via RelationManager, um Livewire component separado) DEPOIS que a página
     * de edição já carregou $record em memória. Sem refresh, o Select de prazo
     * calcularia as parcelas com o total antigo/zerado.
     */
    public function test_prazo_de_pagamento_usa_total_atualizado_mesmo_se_pagina_carregou_record_desatualizado(): void
    {
        $compra = $this->compraComItem(custoUnitario: 25.0, quantidade: 4.0); // total inicial 100

        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '30/60']);
        $prazo->parcelas()->createMany([
            ['parcela_numero' => 1, 'parcela_dias' => 30, 'parcela_percentual' => 50],
            ['parcela_numero' => 2, 'parcela_dias' => 60, 'parcela_percentual' => 50],
        ]);

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()]);

        // Simula alteração de item feita DEPOIS que a página já montou (ex.: outra aba,
        // outro componente) — o total no banco muda, mas o objeto em memória da página não é avisado.
        $compra->itens()->first()->update(['ci_custo_unitario_compra' => 50.0]); // dobra o custo
        app(CompraService::class)->recalcularTotais($compra->fresh('itens')); // total real agora 200

        $testable
            ->mountAction('confirmar')
            ->fillForm([
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-07',
                'prazo_pagamento_id' => $prazo->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $compra->refresh();
        $lancamentos = $compra->lancamentos()->orderBy('parcela_numero')->get();

        $this->assertCount(2, $lancamentos);
        // Deve refletir o total ATUALIZADO (200), não o valor com que a página carregou (100).
        $this->assertEqualsWithDelta(100.0, (float) $lancamentos[0]->valor, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $lancamentos[1]->valor, 0.01);
    }

    /** Reproduz o relato: trocar de um prazo já selecionado para outro deveria recalcular, não zerar. */
    public function test_trocar_de_prazo_de_pagamento_recalcula_as_parcelas_do_novo_prazo(): void
    {
        $compra = $this->compraComItem(custoUnitario: 100.0, quantidade: 1.0); // total 100,00

        $prazoA = PrazoPagamento::create(['prazo_pagamento_nome' => '30/60/90']);
        $prazoA->parcelas()->createMany([
            ['parcela_numero' => 1, 'parcela_dias' => 30, 'parcela_percentual' => 50],
            ['parcela_numero' => 2, 'parcela_dias' => 60, 'parcela_percentual' => 50],
        ]);

        $prazoB = PrazoPagamento::create(['prazo_pagamento_nome' => '7/15/20 dias']);
        $prazoB->parcelas()->createMany([
            ['parcela_numero' => 1, 'parcela_dias' => 7, 'parcela_percentual' => 40],
            ['parcela_numero' => 2, 'parcela_dias' => 15, 'parcela_percentual' => 30],
            ['parcela_numero' => 3, 'parcela_dias' => 20, 'parcela_percentual' => 30],
        ]);

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->mountAction('confirmar')
            ->fillForm([
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-07',
                'prazo_pagamento_id' => $prazoA->id,
            ])
            // Troca pra outro prazo, como o usuário faria ao mudar de ideia no Select.
            ->fillForm([
                'prazo_pagamento_id' => $prazoB->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $compra->refresh();
        $lancamentos = $compra->lancamentos()->orderBy('parcela_numero')->get();

        // Deve refletir o prazoB (3 parcelas, 40/30/30%), não o prazoA nem zero.
        $this->assertCount(3, $lancamentos);
        $this->assertEqualsWithDelta(40.0, (float) $lancamentos[0]->valor, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $lancamentos[1]->valor, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $lancamentos[2]->valor, 0.01);
        $this->assertSame('2026-08-14', $lancamentos[0]->vencimento->toDateString());
    }

    /**
     * Ao selecionar um Prazo de Pagamento, o Repeater de parcelas é calculado
     * automaticamente (vencimento = data-base + dias, valor = % do total), com o
     * resíduo de arredondamento reconciliado na maior parcela — sem precisar
     * informar o array de parcelas manualmente (diferente dos testes acima, que
     * simulam o valor já calculado como se o JS tivesse rodado).
     */
    public function test_selecionar_prazo_de_pagamento_calcula_parcelas_automaticamente_com_rateio_de_centavos(): void
    {
        $compra = $this->compraComItem(custoUnitario: 100.01, quantidade: 1.0); // total 100,01

        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '30/60/90 com resíduo']);
        $prazo->parcelas()->createMany([
            ['parcela_numero' => 1, 'parcela_dias' => 30, 'parcela_percentual' => 33.34],
            ['parcela_numero' => 2, 'parcela_dias' => 60, 'parcela_percentual' => 33.33],
            ['parcela_numero' => 3, 'parcela_dias' => 90, 'parcela_percentual' => 33.33],
        ]);

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->mountAction('confirmar')
            ->fillForm([
                'gerar_conta_pagar' => true,
                'data_base' => '2026-08-07',
                'prazo_pagamento_id' => $prazo->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $compra->refresh();
        $lancamentos = $compra->lancamentos()->orderBy('parcela_numero')->get();

        $this->assertCount(3, $lancamentos);
        $this->assertSame('2026-09-06', $lancamentos[0]->vencimento->toDateString());
        $this->assertSame('2026-10-06', $lancamentos[1]->vencimento->toDateString());
        $this->assertSame('2026-11-05', $lancamentos[2]->vencimento->toDateString());
        // 33,34% e 33,33% de 100,01 arredondam pra 33,34/33,33/33,33 (soma 100,00);
        // o centavo que falta pra fechar 100,01 vai pra maior parcela (a primeira).
        $this->assertEqualsWithDelta(33.35, (float) $lancamentos[0]->valor, 0.001);
        $this->assertEqualsWithDelta(33.33, (float) $lancamentos[1]->valor, 0.001);
        $this->assertEqualsWithDelta(33.33, (float) $lancamentos[2]->valor, 0.001);
        $this->assertEqualsWithDelta(100.01, (float) $lancamentos->sum('valor'), 0.001);
    }

    /** O botão "+" ao lado do Select de Prazo de pagamento cadastra um novo prazo sem sair do modal de confirmação. */
    public function test_botao_de_criacao_rapida_cria_prazo_de_pagamento_a_partir_do_select(): void
    {
        $compra = $this->compraComItem();

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->mountAction('confirmar')
            ->callFormComponentAction(
                components: 'prazo_pagamento_id',
                actions: 'createOption',
                data: [
                    'prazo_pagamento_nome' => '30/60/90 (rápido)',
                    'parcelas' => [
                        ['parcela_dias' => 30, 'parcela_percentual' => 50],
                        ['parcela_dias' => 60, 'parcela_percentual' => 50],
                    ],
                ],
                formName: 'mountedActionSchema0',
            )
            ->assertHasNoFormComponentActionErrors();

        $prazo = PrazoPagamento::where('prazo_pagamento_nome', '30/60/90 (rápido)')->first();
        $this->assertNotNull($prazo, 'O prazo criado pelo botão "+" deve existir no banco.');
        $this->assertCount(2, $prazo->parcelas);
        $this->assertSame([1, 2], $prazo->parcelas->pluck('parcela_numero')->all());
        $this->assertSame([30, 60], $prazo->parcelas->pluck('parcela_dias')->all());
    }

    /** Mesma trava de soma de percentuais = 0% vale pro cadastro rápido, não só pro CRUD completo. */
    public function test_botao_de_criacao_rapida_bloqueia_prazo_com_percentuais_zerados(): void
    {
        $compra = $this->compraComItem();

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->mountAction('confirmar')
            ->callFormComponentAction(
                components: 'prazo_pagamento_id',
                actions: 'createOption',
                data: [
                    'prazo_pagamento_nome' => '7/14/21 sem percentual (rápido)',
                    'parcelas' => [
                        ['parcela_dias' => 7, 'parcela_percentual' => 0],
                        ['parcela_dias' => 14, 'parcela_percentual' => 0],
                        ['parcela_dias' => 21, 'parcela_percentual' => 0],
                    ],
                ],
                formName: 'mountedActionSchema0',
            )
            ->assertHasFormComponentActionErrors();

        $this->assertDatabaseMissing('prazos_pagamento', ['prazo_pagamento_nome' => '7/14/21 sem percentual (rápido)']);
    }

    public function test_acao_confirmar_grava_a_data_de_entrada_informada_no_modal(): void
    {
        $compra = $this->compraComItem();

        Livewire::test(ListCompras::class)
            ->callTableAction('confirmar', $compra, data: [
                'data_entrada' => '2026-07-20',
                'gerar_conta_pagar' => false,
            ])
            ->assertHasNoTableActionErrors();

        $compra->refresh();
        $this->assertSame('2026-07-20', $compra->compra_data_entrada->toDateString());
        $this->assertSame('2026-07-20', $compra->movimentacoes()->first()->mov_data->toDateString());
    }

    public function test_acao_corrigir_data_de_entrada_ajusta_compra_confirmada(): void
    {
        $compra = $this->compraComItem();
        app(CompraService::class)->confirmar($compra->fresh('itens'));

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->callAction('corrigirDataEntrada', data: ['data_entrada' => '2026-07-15'])
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet(['compra_data_entrada' => '2026-07-15']);

        $compra->refresh();
        $this->assertSame('2026-07-15', $compra->compra_data_entrada->toDateString());
        $this->assertSame('2026-07-15', $compra->movimentacoes()->first()->mov_data->toDateString());
    }

    public function test_acao_corrigir_data_de_entrada_oculta_sem_permissao_e_em_rascunho(): void
    {
        $rascunho = $this->compraComItem();

        Livewire::test(ListCompras::class)
            ->assertTableActionHidden('corrigirDataEntrada', $rascunho);

        app(CompraService::class)->confirmar($rascunho->fresh('itens'));

        $semPermissao = User::factory()->create(['name_first' => 'Operador']);
        $semPermissao->givePermissionTo(Permission::findOrCreate('view_any:compra'));
        $this->actingAs($semPermissao);

        Livewire::test(ListCompras::class)
            ->assertTableActionHidden('corrigirDataEntrada', $rascunho);
    }

    public function test_ja_pago_em_caixa_pdv_com_varias_sessoes_exige_a_sessao_de_caixa(): void
    {
        $compra = $this->compraComItem();
        $conta = ContaFinanceira::factory()->caixaPdv()->create();
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessoes = collect([1, 2])->map(fn () => SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => auth()->id(),
        ]));

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('confirmar');
        $chave = array_key_first($testable->get('mountedActions.0.data.parcelas'));

        $testable
            ->set("mountedActions.0.data.parcelas.$chave.ja_pago", true)
            ->set("mountedActions.0.data.parcelas.$chave.conta_financeira_id", $conta->id)
            ->callMountedAction()
            ->assertHasErrors(["mountedActions.0.data.parcelas.$chave.sessao_caixa_id" => 'Escolha a sessão de caixa de onde sai o dinheiro.']);

        $this->assertSame(CompraStatusEnum::RASCUNHO, $compra->fresh()->compra_status);
    }

    public function test_erro_de_conta_obrigatoria_some_ao_escolher_a_conta(): void
    {
        $compra = $this->compraComItem();
        $banco = $this->contaParaBaixa();

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('confirmar');
        $chave = array_key_first($testable->get('mountedActions.0.data.parcelas'));
        $caminho = "mountedActions.0.data.parcelas.$chave.conta_financeira_id";

        $testable
            ->set("mountedActions.0.data.parcelas.$chave.ja_pago", true)
            ->callMountedAction()
            ->assertHasErrors([$caminho => 'required'])
            ->set($caminho, (string) $banco->id)
            ->assertHasNoErrors($caminho)
            ->callMountedAction()
            ->assertHasNoErrors();

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->fresh()->compra_status);
    }

    private function fornecedorComCondicao(Compra $compra, array $condicao): void
    {
        FornecedorCondicaoCompra::factory()->create(['fcc_prestador_id' => $compra->compra_prestador_id, ...$condicao]);
    }

    public function test_modal_vem_preenchido_pelo_prazo_do_fornecedor_sem_marcar_ja_pago(): void
    {
        $compra = $this->compraComItem();
        $conta = $this->contaParaBaixa();
        $prazo = $this->prazoAVista();
        $this->fornecedorComCondicao($compra, [
            'fcc_prazo_pagamento_id' => $prazo->id,
            'fcc_forma_pagamento' => FormaPagamento::Pix,
            'fcc_conta_financeira_id' => $conta->id,
        ]);

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('confirmar');

        $this->assertSame($prazo->id, (int) $testable->get('mountedActions.0.data.prazo_pagamento_id'));
        $this->assertSame('prazo_entrada', $testable->get('mountedActions.0.data.vencimento_origem'));
        $parcelas = $testable->get('mountedActions.0.data.parcelas');
        $this->assertCount(1, $parcelas);
        foreach ($parcelas as $linha) {
            foreach (['vencimento', 'valor', 'forma_pagamento', 'ja_pago', 'conta_financeira_id', 'sessao_caixa_id'] as $chave) {
                $this->assertArrayHasKey($chave, $linha);
            }
            $this->assertFalse($linha['ja_pago']);
            $this->assertSame(FormaPagamento::Pix->value, $linha['forma_pagamento']);
            $this->assertSame($conta->id, (int) $linha['conta_financeira_id']);
        }
    }

    public function test_conta_sugerida_sem_ja_pago_nao_gera_pagamento_nem_movimentacao(): void
    {
        $compra = $this->compraComItem();
        $conta = $this->contaParaBaixa();
        $this->fornecedorComCondicao($compra, [
            'fcc_prazo_pagamento_id' => $this->prazoAVista()->id,
            'fcc_conta_financeira_id' => $conta->id,
        ]);

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->mountAction('confirmar')
            ->callMountedAction()
            ->assertHasNoErrors();

        $lancamento = $compra->lancamentos()->sole();
        $this->assertSame(StatusLancamento::Pendente, $lancamento->status);
        $this->assertCount(0, $lancamento->pagamentos);
        $this->assertSame(0, $conta->movimentacoes()->count());
    }

    public function test_marcar_ja_pago_usa_a_conta_sugerida_e_gera_titulo_pago_com_movimentacao(): void
    {
        $compra = $this->compraComItem();
        $conta = $this->contaParaBaixa();
        $this->fornecedorComCondicao($compra, [
            'fcc_prazo_pagamento_id' => $this->prazoAVista()->id,
            'fcc_forma_pagamento' => FormaPagamento::Pix,
            'fcc_conta_financeira_id' => $conta->id,
        ]);

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('confirmar');
        $chave = array_key_first($testable->get('mountedActions.0.data.parcelas'));

        $testable
            ->set("mountedActions.0.data.parcelas.$chave.ja_pago", true)
            ->callMountedAction()
            ->assertHasNoErrors();

        $lancamento = $compra->lancamentos()->sole();
        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertSame($conta->id, $lancamento->pagamentos->first()->conta_financeira_id);
        $this->assertSame(1, $conta->movimentacoes()->count());
    }

    public function test_origem_dos_vencimentos_e_gravada_conforme_a_sugestao_e_a_troca_de_prazo(): void
    {
        $compra = $this->compraComItem();
        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('confirmar')->callMountedAction();
        $this->assertSame(VencimentoOrigemEnum::PrazoEntrada, $compra->fresh()->compra_vencimento_origem);

        $comNota = $this->compraComItem();
        $comNota->update(['compra_duplicatas_nf' => [['nDup' => '1', 'dVenc' => '2026-08-20', 'vDup' => 100]]]);
        $testable = Livewire::test(EditCompra::class, ['record' => $comNota->getKey()])->mountAction('confirmar');
        $this->assertSame('duplicatas_nf', $testable->get('mountedActions.0.data.vencimento_origem'));
        $this->assertSame(['2026-08-20'], array_map(fn ($v) => substr($v, 0, 10), array_values(array_column($testable->get('mountedActions.0.data.parcelas'), 'vencimento'))));
        $testable->callMountedAction();
        $this->assertSame(VencimentoOrigemEnum::DuplicatasNf, $comNota->fresh()->compra_vencimento_origem);

        $trocada = $this->compraComItem();
        $trocada->update(['compra_duplicatas_nf' => [['nDup' => '1', 'dVenc' => '2026-08-20', 'vDup' => 100]]]);
        $this->fornecedorComCondicao($trocada, ['fcc_prazo_base' => 'emissao']);
        $trocada->update(['compra_data_emissao' => '2026-07-01']);
        $testable = Livewire::test(EditCompra::class, ['record' => $trocada->getKey()])->mountAction('confirmar')
            ->set('mountedActions.0.data.prazo_pagamento_id', $this->prazoAVista()->id);
        $this->assertSame('prazo_emissao', $testable->get('mountedActions.0.data.vencimento_origem'));
        $testable->callMountedAction();
        $this->assertSame(VencimentoOrigemEnum::PrazoEmissao, $trocada->fresh()->compra_vencimento_origem);
    }

    // ── Correção da data de entrada: deslocar vencimentos ────────────

    private function compraConfirmadaComTitulo(?VencimentoOrigemEnum $origem): Compra
    {
        $compra = $this->compraComItem();
        $service = app(CompraService::class);
        $service->confirmar($compra->fresh('itens'));
        $service->gerarContaPagar($compra->fresh(), ['parcelas' => [['vencimento' => '2026-08-07', 'valor' => '100.00']]]);
        $compra->forceFill(['compra_vencimento_origem' => $origem])->save();

        return $compra->fresh();
    }

    public function test_correcao_de_data_desloca_vencimentos_por_padrao_em_compra_antiga_ou_prazo_da_entrada(): void
    {
        foreach ([null, VencimentoOrigemEnum::PrazoEntrada] as $origem) {
            $compra = $this->compraConfirmadaComTitulo($origem);

            $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('corrigirDataEntrada');
            $this->assertTrue($testable->get('mountedActions.0.data.deslocar_vencimentos'));
            $testable->callMountedAction()->assertHasNoErrors();
        }
    }

    public function test_correcao_de_data_com_toggle_ligado_desloca_o_vencimento(): void
    {
        $compra = $this->compraConfirmadaComTitulo(null);

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->callAction('corrigirDataEntrada', data: ['data_entrada' => '2026-07-05'])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-08-04', $compra->lancamentos()->sole()->vencimento->toDateString());
    }

    public function test_correcao_de_data_vem_desligada_para_duplicatas_e_prazo_da_emissao_e_nao_desloca(): void
    {
        foreach ([VencimentoOrigemEnum::DuplicatasNf, VencimentoOrigemEnum::PrazoEmissao] as $origem) {
            $compra = $this->compraConfirmadaComTitulo($origem);

            $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('corrigirDataEntrada');
            $this->assertFalse($testable->get('mountedActions.0.data.deslocar_vencimentos'));

            $testable
                ->set('mountedActions.0.data.data_entrada', '2026-07-05')
                ->callMountedAction()
                ->assertHasNoErrors();

            $this->assertSame('2026-07-05', $compra->fresh()->compra_data_entrada->toDateString());
            $this->assertSame('2026-08-07', $compra->lancamentos()->sole()->vencimento->toDateString());
        }
    }

    public function test_usuario_pode_inverter_o_toggle_nos_dois_casos(): void
    {
        $daNota = $this->compraConfirmadaComTitulo(VencimentoOrigemEnum::DuplicatasNf);
        Livewire::test(EditCompra::class, ['record' => $daNota->getKey()])
            ->callAction('corrigirDataEntrada', data: ['data_entrada' => '2026-07-05', 'deslocar_vencimentos' => true]);
        $this->assertSame('2026-08-04', $daNota->lancamentos()->sole()->vencimento->toDateString());

        $daEntrada = $this->compraConfirmadaComTitulo(VencimentoOrigemEnum::PrazoEntrada);
        Livewire::test(EditCompra::class, ['record' => $daEntrada->getKey()])
            ->callAction('corrigirDataEntrada', data: ['data_entrada' => '2026-07-05', 'deslocar_vencimentos' => false]);
        $this->assertSame('2026-08-07', $daEntrada->lancamentos()->sole()->vencimento->toDateString());
    }

    /** Regressão: a data do modal vai pelo cast do DatePicker; string 'Y-m-d' ganhava a hora atual e estourava o maxDate(today()). */
    public function test_confirma_com_data_de_entrada_de_hoje_sem_erro_de_validacao(): void
    {
        $compra = $this->compraComItem();
        $compra->update(['compra_data_entrada' => today()->toDateString()]);

        Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->mountAction('confirmar')
            ->callMountedAction()
            ->assertHasNoErrors();

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->fresh()->compra_status);
    }

    private function prazoAVista(): PrazoPagamento
    {
        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => 'À Vista']);
        $prazo->parcelas()->create(['parcela_numero' => 1, 'parcela_dias' => 0, 'parcela_percentual' => 100]);

        return $prazo;
    }

    /**
     * No navegador, o $entangle do Select de conta exige que a chave exista no estado;
     * Livewire::test()->set() cria a chave sozinho e esconde o bug, então a forma do
     * estado é verificada direto.
     */
    public function test_parcelas_calculadas_pelo_prazo_trazem_os_campos_de_pagamento(): void
    {
        $compra = $this->compraComItem();
        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '7/14']);
        $prazo->parcelas()->createMany([
            ['parcela_numero' => 1, 'parcela_dias' => 7, 'parcela_percentual' => 50],
            ['parcela_numero' => 2, 'parcela_dias' => 14, 'parcela_percentual' => 50],
        ]);

        $parcelas = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->mountAction('confirmar')
            ->set('mountedActions.0.data.prazo_pagamento_id', $prazo->id)
            ->get('mountedActions.0.data.parcelas');

        $this->assertCount(2, $parcelas);
        foreach ($parcelas as $linha) {
            $this->assertArrayHasKey('conta_financeira_id', $linha);
            $this->assertArrayHasKey('sessao_caixa_id', $linha);
        }
    }

    public function test_prazo_a_vista_ja_pago_com_conta_confirma_e_gera_titulo_pago(): void
    {
        $compra = $this->compraComItem();
        $banco = $this->contaParaBaixa();

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])
            ->mountAction('confirmar')
            ->set('mountedActions.0.data.prazo_pagamento_id', $this->prazoAVista()->id);
        $chave = array_key_first($testable->get('mountedActions.0.data.parcelas'));

        $testable
            ->set("mountedActions.0.data.parcelas.$chave.ja_pago", true)
            ->set("mountedActions.0.data.parcelas.$chave.conta_financeira_id", (string) $banco->id)
            ->callMountedAction()
            ->assertHasNoErrors();

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->fresh()->compra_status);
        $lancamento = $compra->lancamentos()->first();
        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertSame($banco->id, $lancamento->pagamentos->first()->conta_financeira_id);
    }

    public function test_compensacao_ja_paga_confirma_sem_conta_de_origem(): void
    {
        $compra = $this->compraComItem();

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('confirmar');
        $chave = array_key_first($testable->get('mountedActions.0.data.parcelas'));

        $testable
            ->set("mountedActions.0.data.parcelas.$chave.ja_pago", true)
            ->set("mountedActions.0.data.parcelas.$chave.forma_pagamento", FormaPagamento::Compensacao->value)
            ->callMountedAction()
            ->assertHasNoErrors();

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->fresh()->compra_status);
    }

    public function test_parcela_ja_paga_nao_aceita_data_de_pagamento_futura(): void
    {
        $compra = $this->compraComItem();
        $banco = $this->contaParaBaixa();

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('confirmar');
        $chave = array_key_first($testable->get('mountedActions.0.data.parcelas'));

        $testable
            ->set("mountedActions.0.data.parcelas.$chave.vencimento", today()->addDays(5)->toDateString())
            ->set("mountedActions.0.data.parcelas.$chave.ja_pago", true)
            ->set("mountedActions.0.data.parcelas.$chave.conta_financeira_id", (string) $banco->id)
            ->callMountedAction()
            ->assertHasErrors(["mountedActions.0.data.parcelas.$chave.vencimento"]);

        $this->assertSame(CompraStatusEnum::RASCUNHO, $compra->fresh()->compra_status);

        $testable
            ->set("mountedActions.0.data.parcelas.$chave.ja_pago", false)
            ->callMountedAction()
            ->assertHasNoErrors();

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $compra->fresh()->compra_status);
    }

    /** Desde as contas financeiras, toda baixa pela tela informa a conta de origem/destino. */
    private function contaParaBaixa(): ContaFinanceira
    {
        return ContaFinanceira::query()->where('nome', 'Banco teste')->first()
            ?? ContaFinanceira::factory()->banco()->permiteNegativo()->create(['nome' => 'Banco teste']);
    }
}
