<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Resources\Lancamentos\Pages\ListLancamentos;
use App\Models\Cliente;
use App\Models\Lancamento;
use App\Models\Prestador;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LancamentoCompensacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function fornecedorComCliente(): array
    {
        $cliente = Cliente::create(['cliente_nome' => 'Agência XPTO', 'cliente_tipo' => 'PJ']);
        $prestador = Prestador::create([
            'tipo' => 'pj', 'categoria' => 'fornecedor',
            'razao_social' => 'Agência XPTO Publicidade', 'nome' => 'Agência XPTO', 'cliente_id' => $cliente->id,
        ]);

        return [$prestador, $cliente];
    }

    private function lancamentoPagar(Prestador $prestador, float $valor = 1000): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Pagar, 'favorecido_id' => $prestador->id,
            'descricao' => 'Repasse mensal de publicidade', 'valor' => $valor,
            'vencimento' => now()->addDays(15), 'status' => StatusLancamento::Pendente,
        ]);
    }

    private function lancamentoReceberFiado(Cliente $cliente, float $valor): Lancamento
    {
        $venda = Venda::create(['venda_status' => 'FINALIZADA', 'venda_cliente_id' => $cliente->id, 'venda_valor_total' => $valor]);

        return Lancamento::create([
            'tipo' => TipoLancamento::Receber, 'venda_id' => $venda->id, 'cliente_id' => $cliente->id,
            'descricao' => "Venda #{$venda->id} - saldo fiado", 'valor' => $valor,
            'vencimento' => now()->addDays(7), 'status' => StatusLancamento::Pendente,
        ]);
    }

    public function test_acao_so_aparece_quando_prestador_tem_cliente_vinculado(): void
    {
        [$prestador] = $this->fornecedorComCliente();
        $pagarComVinculo = $this->lancamentoPagar($prestador);

        $prestadorSemVinculo = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => 'Sem vínculo', 'nome' => 'Sem vínculo']);
        $pagarSemVinculo = $this->lancamentoPagar($prestadorSemVinculo);

        $component = Livewire::test(ListLancamentos::class);
        $component->assertTableActionVisible('compensar', $pagarComVinculo);
        $component->assertTableActionHidden('compensar', $pagarSemVinculo);
    }

    public function test_compensar_registra_pagamento_nos_dois_lancamentos(): void
    {
        [$prestador, $cliente] = $this->fornecedorComCliente();
        $pagar = $this->lancamentoPagar($prestador, 1000);
        $receber = $this->lancamentoReceberFiado($cliente, 300);

        Livewire::test(ListLancamentos::class)
            ->callTableAction('compensar', $pagar, data: [
                'compensacoes' => [
                    ['lancamento_receber_id' => $receber->id, 'valor' => '300,00'],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $pagarFresh = $pagar->fresh();
        $receberFresh = $receber->fresh();

        $this->assertSame(StatusLancamento::Parcial, $pagarFresh->status);
        $this->assertEqualsWithDelta(700.0, $pagarFresh->valorRestante, 0.01);
        $this->assertSame(StatusLancamento::Pago, $receberFresh->status);
        $this->assertSame(FormaPagamento::Compensacao, $pagarFresh->pagamentos->first()->forma_pagamento);
        $this->assertSame(FormaPagamento::Compensacao, $receberFresh->pagamentos->first()->forma_pagamento);
    }

    public function test_compensar_com_mais_de_um_recebivel_de_uma_vez(): void
    {
        [$prestador, $cliente] = $this->fornecedorComCliente();
        $pagar = $this->lancamentoPagar($prestador, 1000);
        $receber1 = $this->lancamentoReceberFiado($cliente, 200);
        $receber2 = $this->lancamentoReceberFiado($cliente, 150);

        Livewire::test(ListLancamentos::class)
            ->callTableAction('compensar', $pagar, data: [
                'compensacoes' => [
                    ['lancamento_receber_id' => $receber1->id, 'valor' => '200,00'],
                    ['lancamento_receber_id' => $receber2->id, 'valor' => '150,00'],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsWithDelta(650.0, $pagar->fresh()->valorRestante, 0.01);
        $this->assertSame(StatusLancamento::Pago, $receber1->fresh()->status);
        $this->assertSame(StatusLancamento::Pago, $receber2->fresh()->status);
    }

    public function test_compensar_rejeita_soma_maior_que_o_restante_do_pagar(): void
    {
        [$prestador, $cliente] = $this->fornecedorComCliente();
        $pagar = $this->lancamentoPagar($prestador, 100);
        $receber = $this->lancamentoReceberFiado($cliente, 300);

        Livewire::test(ListLancamentos::class)
            ->callTableAction('compensar', $pagar, data: [
                'compensacoes' => [
                    ['lancamento_receber_id' => $receber->id, 'valor' => '300,00'],
                ],
            ])
            ->assertHasTableActionErrors();

        $this->assertSame(StatusLancamento::Pendente, $pagar->fresh()->status);
        $this->assertSame(StatusLancamento::Pendente, $receber->fresh()->status);
    }
}
