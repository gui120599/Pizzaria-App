<?php

namespace Tests\Feature;

use App\Filament\Resources\SessoesCaixa\Pages\ListSessoesCaixa;
use App\Models\Caixa;
use App\Models\ContaFinanceira;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrarSaidaCaixaActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->caixaPdv = ContaFinanceira::factory()->caixaPdv()->comSaldo(100)->create();
        $this->cofre = ContaFinanceira::factory()->cofre()->comSaldo(200)->create();
    }

    private ContaFinanceira $caixaPdv;

    private ContaFinanceira $cofre;

    private function sessao(string $status): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);

        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => $status,
            'sessaocaixa_data_hora_abertura' => now()->subHour(),
            'sessaocaixa_saldo_inicial' => 100,
            'sessaocaixa_saldo_final' => 100,
            'sessaocaixa_user_id' => $user->id,
        ]);

        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $sessao->id,
            'mov_descricao' => 'Saldo inicial de abertura',
            'mov_tipo' => 'ENTRADA',
            'mov_forma_pagamento' => 'dinheiro',
            'mov_valor' => 100,
        ]);

        return $sessao;
    }

    public function test_acao_so_aparece_para_sessao_aberta(): void
    {
        $aberta = $this->sessao('ABERTA');
        $fechada = $this->sessao('FECHADA');

        $component = Livewire::test(ListSessoesCaixa::class);
        $component->assertTableActionVisible('registrarSaidaCaixa', $aberta);
        $component->assertTableActionHidden('registrarSaidaCaixa', $fechada);
        $component->assertTableActionVisible('registrarSuprimentoCaixa', $aberta);
        $component->assertTableActionHidden('registrarSuprimentoCaixa', $fechada);
    }

    public function test_registrar_saida_pela_tabela_cria_movimento_e_atualiza_saldo(): void
    {
        $sessao = $this->sessao('ABERTA');

        Livewire::test(ListSessoesCaixa::class)
            ->callTableAction('registrarSaidaCaixa', $sessao, data: [
                'motivo' => 'sangria',
                'conta_destino_id' => $this->cofre->id,
                'valor' => '30,00',
                'descricao' => 'Depósito no banco',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)->where('mov_tipo', 'SAIDA')->count());
        $this->assertEqualsWithDelta(70.0, (float) $sessao->fresh()->sessaocaixa_saldo_final, 0.01);
        $this->assertSame(2, MovimentacaoFinanceira::count());
        $this->assertSame(70.0, (float) $this->caixaPdv->fresh()->saldo_atual);
        $this->assertSame(230.0, (float) $this->cofre->fresh()->saldo_atual);
        $this->assertNotNull(MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)->where('mov_tipo', 'SAIDA')->first()->movimentacao_financeira_id);
    }

    public function test_sangria_sem_conta_de_destino_e_recusada(): void
    {
        $sessao = $this->sessao('ABERTA');

        Livewire::test(ListSessoesCaixa::class)
            ->callTableAction('registrarSaidaCaixa', $sessao, data: [
                'motivo' => 'sangria',
                'valor' => '30,00',
                'descricao' => 'Depósito no banco',
            ])
            ->assertHasTableActionErrors(['conta_destino_id']);

        $this->assertSame(0, MovimentacoesSessaoCaixa::where('mov_tipo', 'SAIDA')->count());
        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_registrar_suprimento_pela_tabela_cria_movimento_e_atualiza_saldo(): void
    {
        $sessao = $this->sessao('ABERTA');

        Livewire::test(ListSessoesCaixa::class)
            ->callTableAction('registrarSuprimentoCaixa', $sessao, data: [
                'conta_origem_id' => $this->cofre->id,
                'valor' => '50,00',
                'descricao' => 'Reforço de troco',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)->where('mov_tipo', 'ENTRADA')->where('mov_motivo', 'suprimento')->count());
        $this->assertEqualsWithDelta(150.0, (float) $sessao->fresh()->sessaocaixa_saldo_final, 0.01);
        $this->assertSame(150.0, (float) $this->caixaPdv->fresh()->saldo_atual);
        $this->assertSame(150.0, (float) $this->cofre->fresh()->saldo_atual);
    }
}
