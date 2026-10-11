<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\FormaTrocoEnum;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoCorrecaoPagamentoEnum;
use App\Enums\TipoLancamento;
use App\Exceptions\CorrecaoPagamentoException;
use App\Filament\Widgets\SessaoCaixaVendasWidget;
use App\Models\Caixa;
use App\Models\CorrecaoPagamento;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\CorrecaoPagamentoService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CorrecaoPagamentoServiceTest extends TestCase
{
    use RefreshDatabase;

    private CorrecaoPagamentoService $service;

    private SessaoCaixa $sessao;

    private FechamentoCaixa $fechamento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $this->service = app(CorrecaoPagamentoService::class);

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(6),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
        $this->fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $this->sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);
    }

    private function opcao(string $nome, string $descNfe, string $tipoTaxa = 'N/A', float $percentualTaxa = 0): OpcoesPagamento
    {
        return OpcoesPagamento::create([
            'opcaopag_nome' => $nome,
            'opcaopag_desc_nfe' => $descNfe,
            'opcaopag_tipo_taxa' => $tipoTaxa,
            'opcaopag_valor_percentual_taxa' => $percentualTaxa,
        ]);
    }

    private function vendaPaga(OpcoesPagamento $opcao, float $valor, float $troco = 0, ?string $statusNfe = null): PagamentosVenda
    {
        $venda = Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => $valor,
            'venda_valor_pago' => $valor,
            'venda_status_nfe' => $statusNfe,
            'venda_datahora_finalizada' => now(),
        ]);

        return PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => $valor,
            'pg_venda_valor_recebido' => $valor,
            'pg_venda_valor_pago_pelo_cliente' => $valor + $troco,
            'pg_venda_valor_troco' => $troco,
            'pg_venda_forma_troco' => $troco > 0 ? FormaTrocoEnum::Dinheiro : null,
        ]);
    }

    public function test_trocar_forma_move_o_valor_no_rascunho_e_grava_a_auditoria(): void
    {
        $debito = $this->opcao('Cartão Débito', 'debitCard');
        $pagamento = $this->vendaPaga($this->opcao('Cartão Crédito', 'creditCard'), 64.45);

        $this->service->trocarForma($pagamento, $debito->id, justificativa: 'Passou no débito');

        $this->fechamento->refresh();
        $this->assertSame([64.45, 0.0], [(float) $this->fechamento->total_esperado_debito, (float) $this->fechamento->total_esperado_credito]);
        $this->assertSame(64.45, (float) $pagamento->venda->fresh()->venda_valor_total);
        $correcao = CorrecaoPagamento::sole();
        $this->assertSame(TipoCorrecaoPagamentoEnum::Forma, $correcao->tipo);
        $this->assertSame(['Cartão Crédito', 'Cartão Débito'], [$correcao->antes['forma'], $correcao->depois['forma']]);
        $this->assertSame(auth()->id(), $correcao->user_id);
    }

    public function test_dividir_mantem_a_soma_e_separa_as_formas(): void
    {
        $credito = $this->opcao('Cartão Crédito', 'creditCard');
        $pagamento = $this->vendaPaga($this->opcao('Pix QRCode', 'InstantPayment'), 201.18);

        $this->service->dividir($pagamento, [
            ['opcao_id' => $pagamento->pg_venda_opcaopagamento_id, 'valor' => 100.59],
            ['opcao_id' => $credito->id, 'valor' => 100.59],
        ]);

        $this->fechamento->refresh();
        $this->assertSame([100.59, 100.59], [(float) $this->fechamento->total_esperado_pix, (float) $this->fechamento->total_esperado_credito]);
        $this->assertSame(2, PagamentosVenda::where('pg_venda_venda_id', $pagamento->pg_venda_venda_id)->count());
        $this->assertSame(TipoCorrecaoPagamentoEnum::Divisao, CorrecaoPagamento::sole()->tipo);
    }

    public function test_dividir_rejeita_partes_que_nao_somam_o_pagamento(): void
    {
        $pagamento = $this->vendaPaga($this->opcao('Pix QRCode', 'InstantPayment'), 100.00);

        $this->expectException(CorrecaoPagamentoException::class);

        try {
            $this->service->dividir($pagamento, [
                ['opcao_id' => $pagamento->pg_venda_opcaopagamento_id, 'valor' => 60],
                ['opcao_id' => $pagamento->pg_venda_opcaopagamento_id, 'valor' => 30],
            ]);
        } finally {
            $this->assertSame(1, PagamentosVenda::count());
            $this->assertSame(0, CorrecaoPagamento::count());
        }
    }

    public function test_trocar_forma_do_troco_move_o_troco_para_o_pix_cnpj(): void
    {
        $pagamento = $this->vendaPaga($this->opcao('Dinheiro', 'cash'), 80.00, 20.00);

        $this->service->trocarFormaTroco($pagamento, FormaTrocoEnum::Pix);

        $this->fechamento->refresh();
        $this->assertSame([100.0, -20.0], [(float) $this->fechamento->total_esperado_dinheiro, (float) $this->fechamento->total_esperado_pix_cnpj]);
        $this->assertSame(TipoCorrecaoPagamentoEnum::FormaTroco, CorrecaoPagamento::sole()->tipo);
    }

    public function test_trocar_forma_do_fiado_recebido_move_o_valor_no_rascunho(): void
    {
        $recebimento = Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'descricao' => 'Fiado',
            'valor' => 86,
            'vencimento' => now()->addDays(7),
            'status' => StatusLancamento::Pendente,
        ])->registrarPagamento(86, forma: FormaPagamento::PixCnpj, sessaoCaixaId: $this->sessao->id);

        $this->service->trocarFormaFiado($recebimento, FormaPagamento::Pix);

        $this->fechamento->refresh();
        $this->assertSame([86.0, 0.0], [(float) $this->fechamento->total_esperado_pix, (float) $this->fechamento->total_esperado_pix_cnpj]);
        $this->assertSame(TipoCorrecaoPagamentoEnum::FormaFiado, CorrecaoPagamento::sole()->tipo);
    }

    /** @return array<string, array{0: ?string}> */
    public static function statusNfeQueBloqueiam(): array
    {
        return [
            'autorizada' => ['Issued'],
            'cancelamento solicitado' => ['CancelamentoSolicitado'],
            'cancelamento recusado' => ['CancelFailed'],
            'em processamento' => ['Processing'],
        ];
    }

    #[DataProvider('statusNfeQueBloqueiam')]
    public function test_bloqueia_venda_com_nfce_que_nao_foi_cancelada(string $statusNfe): void
    {
        $debito = $this->opcao('Cartão Débito', 'debitCard');
        $pagamento = $this->vendaPaga($this->opcao('Cartão Crédito', 'creditCard'), 50.00, statusNfe: $statusNfe);

        $this->expectException(CorrecaoPagamentoException::class);

        $this->service->trocarForma($pagamento, $debito->id);
    }

    public function test_nfce_cancelada_libera_a_correcao(): void
    {
        $debito = $this->opcao('Cartão Débito', 'debitCard');
        $pagamento = $this->vendaPaga($this->opcao('Cartão Crédito', 'creditCard'), 50.00, statusNfe: 'Cancelled');

        $this->service->trocarForma($pagamento, $debito->id);

        $this->assertSame($debito->id, $pagamento->fresh()->pg_venda_opcaopagamento_id);
    }

    public function test_bloqueia_sessao_com_fechamento_confirmado(): void
    {
        $this->fechamento->update(['status' => StatusFechamentoCaixa::Confirmado]);
        $debito = $this->opcao('Cartão Débito', 'debitCard');
        $pagamento = $this->vendaPaga($this->opcao('Cartão Crédito', 'creditCard'), 50.00);

        $this->expectException(CorrecaoPagamentoException::class);

        $this->service->trocarForma($pagamento, $debito->id);
    }

    public function test_bloqueia_pagamento_integrado_a_stone(): void
    {
        $debito = $this->opcao('Cartão Débito', 'debitCard');
        $pagamento = $this->vendaPaga($this->opcao('Cartão Crédito', 'creditCard'), 50.00);
        $pagamento->update(['pg_venda_tipo_integracao' => 'integrated']);

        $this->expectException(CorrecaoPagamentoException::class);

        $this->service->trocarForma($pagamento, $debito->id);
    }

    public function test_bloqueia_forma_nova_com_acrescimo(): void
    {
        $creditoComAcrescimo = $this->opcao('Crédito parcelado', 'creditCard', 'ACRESCENTAR', 3);
        $pagamento = $this->vendaPaga($this->opcao('Cartão Débito', 'debitCard'), 50.00);

        $this->expectException(CorrecaoPagamentoException::class);

        $this->service->trocarForma($pagamento, $creditoComAcrescimo->id);
    }

    public function test_acao_de_corrigir_pela_tabela_troca_a_forma(): void
    {
        $debito = $this->opcao('Cartão Débito', 'debitCard');
        $pagamento = $this->vendaPaga($this->opcao('Cartão Crédito', 'creditCard'), 64.45);

        Livewire::test(SessaoCaixaVendasWidget::class, ['pageFilters' => ['sessoes' => [$this->sessao->id]]])
            ->callAction(TestAction::make('corrigir')->table((string) $pagamento->pg_venda_venda_id), [
                'pagamento_id' => $pagamento->id,
                'tipo' => 'forma',
                'opcao_id' => $debito->id,
            ])
            ->assertHasNoFormErrors();

        $this->assertSame($debito->id, $pagamento->fresh()->pg_venda_opcaopagamento_id);
    }

    public function test_acao_de_corrigir_nao_aparece_sem_permissao(): void
    {
        $pagamento = $this->vendaPaga($this->opcao('Cartão Crédito', 'creditCard'), 64.45);
        $this->actingAs(User::factory()->garcom()->create(['name_first' => 'Garçom']));
        $this->assertFalse(auth()->user()->can('corrigir:pagamento_venda'));

        Livewire::test(SessaoCaixaVendasWidget::class, ['pageFilters' => ['sessoes' => [$this->sessao->id]]])
            ->assertActionHidden(TestAction::make('corrigir')->table((string) $pagamento->pg_venda_venda_id));
    }
}
