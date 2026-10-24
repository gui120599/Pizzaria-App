<?php

namespace Tests\Feature;

use App\Enums\Financeiro\TipoContaFinanceira;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Enums\TipoMovimentoCarteiraEnum;
use App\Exceptions\CarteiraInfluenciadorException;
use App\Filament\Influenciador\Pages\MinhaCarteira;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\CodigoResgate;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\FechamentoCaixa;
use App\Models\Influenciador;
use App\Models\ItensVenda;
use App\Models\Lancamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\CarteiraInfluenciadorService;
use App\Services\CodigoResgateService;
use App\Services\ConsumoCarteiraService;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** F2.4: código de resgate, consumo em pizza no PDV, estorno e contabilização sem efeito no caixa. */
class ConsumoCarteiraTest extends TestCase
{
    use RefreshDatabase;

    private Influenciador $influenciador;

    private OpcoesPagamento $opcaoCarteira;

    private Venda $venda;

    private SessaoCaixa $sessao;

    private ContaFinanceira $contaCompensacao;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $this->contaCompensacao = ContaFinanceira::factory()->banco()->create(['nome' => 'Carteira de influenciadores']);
        $this->opcaoCarteira = OpcoesPagamento::create([
            'opcaopag_nome' => 'Carteira de influenciador',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_carteira_influenciador' => true,
            'conta_financeira_id' => $this->contaCompensacao->id,
            'plano_receita_id' => PlanoReceita::create(['nome' => 'Balcão'])->id,
        ]);

        $this->influenciador = Influenciador::create([
            'influenciador_nome' => 'Maria Souza',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);
        $this->influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();
        app(CarteiraInfluenciadorService::class)->lancar($this->influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 50);

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $produto = Produto::create([
            'produto_descricao' => 'Pizza Grande',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 80,
        ]);
        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_valor_itens' => 80,
            'venda_valor_total' => 80,
        ]);
        ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $this->venda->id,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 80,
            'item_venda_desconto' => 0,
            'item_venda_valor' => 80,
            'item_venda_valor_base_calculo' => 80,
            'item_venda_status' => 'INSERIDO',
        ]);
    }

    private function codigo(?Influenciador $influenciador = null): string
    {
        return app(CodigoResgateService::class)->gerar($influenciador ?? $this->influenciador)['codigo'];
    }

    public function test_codigo_tem_6_digitos_guarda_so_o_hash_e_o_novo_invalida_o_anterior(): void
    {
        $primeiro = $this->codigo();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $primeiro);
        $this->assertSame(1, CodigoResgate::count());
        $this->assertStringNotContainsString($primeiro, (string) CodigoResgate::sole()->cr_codigo_hash);

        $segundo = $this->codigo();

        $service = app(CodigoResgateService::class);
        $this->assertSame($this->influenciador->id, $service->consultar($segundo)->cr_influenciador_id);

        if ($primeiro !== $segundo) {
            $this->expectException(CarteiraInfluenciadorException::class);
            $service->consultar($primeiro);
        }
    }

    public function test_codigo_invalido_vencido_ou_usado_e_recusado(): void
    {
        $service = app(CodigoResgateService::class);

        $this->assertRecusado(fn () => $service->consultar('000000'));
        $this->assertRecusado(fn () => $service->consultar('abc'));

        $codigo = $this->codigo();
        $this->travel(16)->minutes();
        $this->assertRecusado(fn () => $service->consultar($codigo));
        $this->travelBack();

        $codigo = $this->codigo();
        CodigoResgate::latest('id')->first()->update(['cr_usado_em' => now()]);
        $this->assertRecusado(fn () => $service->consultar($codigo));
    }

    public function test_consulta_tem_limite_de_tentativas_por_operador(): void
    {
        $service = app(CodigoResgateService::class);

        for ($i = 0; $i < 8; $i++) {
            $this->assertRecusado(fn () => $service->consultar('11111'.$i, 99));
        }

        $codigo = $this->codigo();
        try {
            $service->consultar($codigo, 99);
            $this->fail('Deveria estar bloqueado por excesso de tentativas.');
        } catch (CarteiraInfluenciadorException $e) {
            $this->assertStringContainsString('Muitas tentativas', $e->getMessage());
        }
    }

    public function test_so_o_influenciador_ativo_usa_o_codigo(): void
    {
        $codigo = $this->codigo();
        $this->influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::INATIVO])->save();

        $this->assertRecusado(fn () => app(CodigoResgateService::class)->consultar($codigo));
    }

    private function assertRecusado(callable $acao): void
    {
        try {
            $acao();
            $this->fail('Deveria ser recusado.');
        } catch (CarteiraInfluenciadorException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_pdv_consome_a_carteira_com_o_codigo_e_debita_uma_vez(): void
    {
        $codigo = $this->codigo();

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcaoCarteira->id)
            ->set('codigoCarteira', $codigo)
            ->call('verificarCodigoCarteira')
            ->assertSet('carteiraInfo.nome', 'Maria Souza')
            ->assertSet('carteiraInfo.saldo', 50.0)
            // O limite é o menor entre o saldo (50) e o restante a pagar (80).
            ->assertSet('carteiraInfo.limite', 50.0)
            ->call('registrarPagamento')
            ->assertSet('modalPagamentoAberta', false);

        $pagamento = PagamentosVenda::sole();
        $this->assertSame('50.00', $pagamento->pg_venda_valor_pagamento);
        $this->assertSame(0.0, $this->influenciador->fresh()->saldoCarteira());

        $movimento = $this->influenciador->movimentosCarteira()->where('cm_tipo', TipoMovimentoCarteiraEnum::CONSUMO)->sole();
        $this->assertSame('-50.00', $movimento->cm_valor);
        $this->assertSame($pagamento->id, $movimento->cm_pagamento_venda_id);
        $this->assertNotNull(CodigoResgate::sole()->cr_usado_em);
        $this->assertSame(50.0, (float) $this->venda->fresh()->venda_valor_pago);
    }

    public function test_pdv_recusa_valor_acima_do_limite_sem_codigo_e_codigo_reutilizado(): void
    {
        $codigo = $this->codigo();

        // Sem verificar o código: recusado.
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcaoCarteira->id)
            ->set('valorPagamento', 10)
            ->call('registrarPagamento')
            ->assertHasErrors('codigoCarteira');

        // Valor acima do saldo: recusado.
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcaoCarteira->id)
            ->set('codigoCarteira', $codigo)
            ->call('verificarCodigoCarteira')
            ->set('valorPagamento', 60)
            ->call('registrarPagamento')
            ->assertHasErrors('valorPagamento');

        $this->assertSame(0, PagamentosVenda::count());
        $this->assertSame(50.0, $this->influenciador->fresh()->saldoCarteira());

        // Código já usado não vale de novo.
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcaoCarteira->id)
            ->set('codigoCarteira', $codigo)
            ->call('verificarCodigoCarteira')
            ->set('valorPagamento', 20)
            ->call('registrarPagamento');
        Livewire::test(OperarVenda::class, ['venda' => $this->venda->fresh()])
            ->set('opcaoPagamentoSelecionadaId', $this->opcaoCarteira->id)
            ->set('codigoCarteira', $codigo)
            ->call('verificarCodigoCarteira')
            ->assertHasErrors('codigoCarteira');

        $this->assertSame(1, PagamentosVenda::count());
    }

    public function test_remover_o_pagamento_devolve_o_saldo(): void
    {
        $codigo = $this->codigo();

        $tela = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('opcaoPagamentoSelecionadaId', $this->opcaoCarteira->id)
            ->set('codigoCarteira', $codigo)
            ->call('verificarCodigoCarteira')
            ->set('valorPagamento', 30)
            ->call('registrarPagamento');
        $this->assertSame(20.0, $this->influenciador->fresh()->saldoCarteira());

        $tela->call('removerPagamento', PagamentosVenda::sole()->id);

        $this->assertSame(50.0, $this->influenciador->fresh()->saldoCarteira());
        $this->assertSame(
            [TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, TipoMovimentoCarteiraEnum::CONSUMO, TipoMovimentoCarteiraEnum::ESTORNO_CONSUMO],
            $this->influenciador->movimentosCarteira()->orderBy('id')->get()->pluck('cm_tipo')->all(),
        );
    }

    public function test_venda_cancelada_devolve_o_consumo_uma_unica_vez(): void
    {
        $codigo = $this->codigo();
        $codigoModel = app(CodigoResgateService::class)->consultar($codigo);
        $pagamento = PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id,
            'pg_venda_opcaopagamento_id' => $this->opcaoCarteira->id,
            'pg_venda_valor_pagamento' => 40,
        ]);
        app(ConsumoCarteiraService::class)->consumir($codigoModel, $pagamento, 40);
        $this->assertSame(10.0, $this->influenciador->fresh()->saldoCarteira());

        app(ConsumoCarteiraService::class)->estornarDaVenda($this->venda);
        app(ConsumoCarteiraService::class)->estornarDaVenda($this->venda);
        $this->assertSame(50.0, $this->influenciador->fresh()->saldoCarteira());

        // Apagar o pagamento depois do estorno não devolve em dobro.
        $pagamento->delete();
        $this->assertSame(50.0, $this->influenciador->fresh()->saldoCarteira());
    }

    public function test_carteira_nao_e_aceita_no_pdv_legado_nem_no_cardapio_nem_no_pedido(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Outro Admin']))->postJson(route('pagamento_venda.store'), [
            'venda_id' => $this->venda->id,
            'pg_venda_opcaopagamento_id' => $this->opcaoCarteira->id,
            'pg_venda_valor_pagamento' => 10,
        ])->assertStatus(422);
        $this->assertSame(0, PagamentosVenda::count());

        $this->assertSame(0, OpcoesPagamento::semCarteira()->where('id', $this->opcaoCarteira->id)->count());
    }

    public function test_fechamento_lanca_receita_e_despesa_de_marketing_sem_efeito_no_caixa(): void
    {
        $caixaPdv = ContaFinanceira::factory()->caixaPdv()->comSaldo(0)->create();
        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_influ_plano_despesa_id' => PlanoDespesa::create(['nome' => 'Marketing — Comissões', 'comportamento' => 'variavel', 'periodicidade' => 'mensal'])->id,
        ]);

        $venda = Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => 50,
            'venda_datahora_finalizada' => today()->setTimeFromTimeString('12:00'),
        ]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $this->opcaoCarteira->id,
            'pg_venda_valor_pagamento' => 50,
        ]);
        $this->sessao->update(['sessaocaixa_status' => 'FECHADA', 'sessaocaixa_data_hora_fechamento' => now()]);
        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $this->sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Confirmado,
            'confirmado_em' => now(),
            'valor_troco_gaveta' => 0,
        ]);

        $lancamentos = app(ContabilizacaoFechamentoService::class)->contabilizar($this->sessao->fresh());

        $receita = $lancamentos->firstWhere('tipo', TipoLancamento::Receber);
        $despesa = $lancamentos->firstWhere('tipo', TipoLancamento::Pagar);
        $this->assertSame('50.00', $receita->valor);
        $this->assertSame('50.00', $despesa->valor);
        $this->assertSame(StatusLancamento::Pago, $despesa->status);

        // Receita da venda + despesa de marketing de mesmo valor: a conta de compensação não muda e o caixa não é tocado.
        $this->assertSame(0.0, (float) $this->contaCompensacao->fresh()->saldo_atual);
        $this->assertSame(0.0, (float) $caixaPdv->fresh()->saldo_atual);
        $this->assertSame(2, MovimentacaoFinanceira::where('conta_financeira_id', $this->contaCompensacao->id)->count());
        $this->assertSame(TipoContaFinanceira::Banco, $this->contaCompensacao->tipo);

        // Estornar a contabilização desfaz os dois lados.
        app(ContabilizacaoFechamentoService::class)->estornar($this->sessao->fresh(), 'Teste de estorno');
        $this->assertSame(0.0, (float) $this->contaCompensacao->fresh()->saldo_atual);
        $this->assertTrue(Lancamento::where('sessao_caixa_id', $this->sessao->id)->where('status', '!=', StatusLancamento::Cancelado)->doesntExist());
    }

    public function test_fechamento_sem_plano_de_marketing_bloqueia_com_mensagem(): void
    {
        ContaFinanceira::factory()->caixaPdv()->comSaldo(0)->create();
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste LTDA']);
        $venda = Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => 50,
            'venda_datahora_finalizada' => today()->setTimeFromTimeString('12:00'),
        ]);
        PagamentosVenda::create(['pg_venda_venda_id' => $venda->id, 'pg_venda_opcaopagamento_id' => $this->opcaoCarteira->id, 'pg_venda_valor_pagamento' => 50]);

        $pendencias = app(ContabilizacaoFechamentoService::class)->pendenciasDeConfiguracao($this->sessao);

        $this->assertTrue(collect($pendencias)->contains(fn (string $p): bool => str_contains($p, 'plano de despesa de marketing')));
    }

    public function test_pagina_minha_carteira_mostra_saldo_e_gera_codigo(): void
    {
        $this->actingAs($this->influenciador, 'influenciador');
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        Livewire::test(MinhaCarteira::class)
            ->assertSee('50,00')
            ->callAction('gerarCodigo')
            ->assertSet('codigoGerado', fn ($codigo) => preg_match('/^\d{6}$/', (string) $codigo) === 1);

        $this->assertSame(1, CodigoResgate::count());
    }
}
