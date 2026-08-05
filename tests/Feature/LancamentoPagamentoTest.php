<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\FormaPagamento;
use App\Enums\Periodicidade;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Filament\Resources\Lancamentos\Pages\CreateLancamento;
use App\Filament\Resources\Lancamentos\Pages\EditLancamento;
use App\Models\Cliente;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class LancamentoPagamentoTest extends TestCase
{
    use RefreshDatabase;

    private function lancamento(float $valor = 1000): Lancamento
    {
        $plano = PlanoDespesa::create([
            'nome' => 'Aluguel',
            'comportamento' => Comportamento::Fixo,
            'periodicidade' => Periodicidade::Mensal,
        ]);

        return Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'plano_despesa_id' => $plano->id,
            'descricao' => 'Título com múltiplos pagamentos',
            'valor' => $valor,
            'vencimento' => '2026-07-10',
            'status' => StatusLancamento::Pendente,
        ]);
    }

    public function test_pagamentos_parciais_movem_status_pendente_parcial_pago(): void
    {
        $lancamento = $this->lancamento(1000);

        $this->assertSame(StatusLancamento::Pendente, $lancamento->status);
        $this->assertSame(0.0, $lancamento->valorPago);
        $this->assertSame(1000.0, $lancamento->valorRestante);

        $lancamento->registrarPagamento(400, Carbon::parse('2026-07-05'), FormaPagamento::Pix);
        $lancamento->refresh();
        $this->assertSame(StatusLancamento::Parcial, $lancamento->status);
        $this->assertSame(400.0, $lancamento->valorPago);
        $this->assertSame(600.0, $lancamento->valorRestante);

        $lancamento->registrarPagamento(600, Carbon::parse('2026-07-08'), FormaPagamento::Boleto);
        $lancamento->refresh();
        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertSame(0.0, $lancamento->valorRestante);
        // data_pagamento/forma_pagamento do cabeçalho refletem o pagamento mais recente.
        $this->assertSame('2026-07-08', $lancamento->data_pagamento->toDateString());
        $this->assertSame(FormaPagamento::Boleto, $lancamento->forma_pagamento);
        $this->assertCount(2, $lancamento->pagamentos);
    }

    public function test_estornar_pagamento_apaga_todos_os_pagamentos_e_volta_pendente(): void
    {
        $lancamento = $this->lancamento(500);
        $lancamento->registrarPagamento(200);
        $lancamento->registrarPagamento(300);
        $lancamento->refresh();
        $this->assertSame(StatusLancamento::Pago, $lancamento->status);

        $lancamento->estornarPagamento();
        $lancamento->refresh();

        $this->assertSame(StatusLancamento::Pendente, $lancamento->status);
        $this->assertNull($lancamento->data_pagamento);
        $this->assertNull($lancamento->forma_pagamento);
        $this->assertSame(0, $lancamento->pagamentos()->count());
    }

    public function test_recalcular_status_nao_sobrescreve_cancelado(): void
    {
        $lancamento = $this->lancamento(500);
        $lancamento->update(['status' => StatusLancamento::Cancelado]);

        $lancamento->registrarPagamento(100);
        $lancamento->refresh();

        $this->assertSame(StatusLancamento::Cancelado, $lancamento->status);
    }

    public function test_lancamento_pago_fica_travado_mesmo_apos_pagamento_parcial_completar(): void
    {
        $lancamento = $this->lancamento(300);
        $lancamento->registrarPagamento(300);
        $lancamento->refresh();

        $this->assertFalse(LancamentoResource::canEdit($lancamento));
        $this->assertFalse(LancamentoResource::canDelete($lancamento));
    }

    public function test_conta_a_receber_tambem_suporta_multiplos_pagamentos(): void
    {
        $planoReceita = PlanoReceita::create(['nome' => 'Serviços prestados']);
        $cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_tipo' => 'pf']);

        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'plano_receita_id' => $planoReceita->id,
            'cliente_id' => $cliente->id,
            'descricao' => 'Recebimento em 2x',
            'valor' => 800,
            'vencimento' => '2026-07-10',
            'status' => StatusLancamento::Pendente,
        ]);

        $lancamento->registrarPagamento(300, Carbon::parse('2026-07-05'), FormaPagamento::Pix);
        $lancamento->refresh();
        $this->assertSame(StatusLancamento::Parcial, $lancamento->status);
        $this->assertSame(500.0, $lancamento->valorRestante);

        $lancamento->registrarPagamento(500, Carbon::parse('2026-07-10'), FormaPagamento::Transferencia);
        $lancamento->refresh();
        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertSame(FormaPagamento::Transferencia, $lancamento->forma_pagamento);
        $this->assertCount(2, $lancamento->pagamentos);
    }

    public function test_repeater_de_pagamentos_no_form_registra_pagamento_parcial(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $lancamento = $this->lancamento(1000);

        Livewire::test(EditLancamento::class, ['record' => $lancamento->getKey()])
            ->fillForm([
                'pagamentos' => [
                    // Money::dehydrateStateUsing() espera o valor já em formato BR
                    // (vírgula decimal) — ver feedback_filament_ptbr_form_fields.
                    ['data_pagamento' => '2026-07-06', 'valor' => '250,00', 'forma_pagamento' => 'pix'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $lancamento->refresh();
        $this->assertSame(1, $lancamento->pagamentos()->count());
        $this->assertSame(250.0, $lancamento->valorPago);
        $this->assertSame(StatusLancamento::Parcial, $lancamento->status);
    }

    /**
     * O repeater "pagamentos" não precisa esperar o registro existir — dá pra lançar o
     * pagamento já na criação do título (mesmo padrão do Repeater::relationship() usado
     * nos resources de Aluguéis/Sessão de Caixa do LocSilva2).
     */
    public function test_repeater_de_pagamentos_funciona_na_criacao_e_quita_o_titulo(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $plano = PlanoDespesa::create([
            'nome' => 'Fornecedor pago à vista',
            'comportamento' => Comportamento::Variavel,
            'periodicidade' => Periodicidade::Eventual,
        ]);

        Livewire::test(CreateLancamento::class)
            ->fillForm([
                'tipo' => 'pagar',
                'plano_despesa_id' => $plano->id,
                'descricao' => 'Compra à vista já paga na hora',
                'valor' => '500,00',
                'vencimento' => '2026-07-20',
                'status' => 'pendente',
                'pagamentos' => [
                    ['data_pagamento' => '2026-07-20', 'valor' => '500,00', 'forma_pagamento' => 'dinheiro'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lancamento = Lancamento::where('descricao', 'Compra à vista já paga na hora')->firstOrFail();

        $this->assertSame(1, $lancamento->pagamentos()->count());
        $this->assertSame(500.0, $lancamento->valorPago);
        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertSame(FormaPagamento::Dinheiro, $lancamento->forma_pagamento);
    }
}
