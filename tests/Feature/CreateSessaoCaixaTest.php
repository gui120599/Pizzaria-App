<?php

namespace Tests\Feature;

use App\Filament\Resources\SessoesCaixa\Pages\CreateSessaoCaixa;
use App\Models\Caixa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\NotaMoeda;
use App\Models\SessaoCaixa;
use App\Models\SessaoCaixaNota;
use App\Models\User;
use App\Services\SessaoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Testa SessaoCaixaService::finalizarAbertura() diretamente (mesma lógica que
 * CreateSessaoCaixa::afterCreate() roda depois que o Repeater 'notas' já
 * persistiu as linhas), além de um teste end-to-end via Livewire cobrindo o
 * Repeater em si (ver test_create_via_livewire_persiste_notas_e_saldo_inicial).
 */
class CreateSessaoCaixaTest extends TestCase
{
    use RefreshDatabase;

    private function sessaoAberta(): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    public function test_finalizar_abertura_soma_notas_para_saldo_inicial(): void
    {
        $sessao = $this->sessaoAberta();
        $notaCem = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);
        $notaCinquenta = NotaMoeda::create(['descricao' => 'R$ 50,00', 'valor' => 50, 'tipo' => 'cedula', 'ordem_exibicao' => 2]);

        SessaoCaixaNota::create(['sessao_caixa_id' => $sessao->id, 'nota_moeda_id' => $notaCem->id, 'quantidade' => 2]);
        SessaoCaixaNota::create(['sessao_caixa_id' => $sessao->id, 'nota_moeda_id' => $notaCinquenta->id, 'quantidade' => 1]);

        app(SessaoCaixaService::class)->finalizarAbertura($sessao);
        $sessao->refresh();

        // 2x100 + 1x50 = 250
        $this->assertEqualsWithDelta(250.0, (float) $sessao->sessaocaixa_saldo_inicial, 0.01);
        $this->assertEqualsWithDelta(250.0, (float) $sessao->sessaocaixa_saldo_final, 0.01);
    }

    /**
     * Débito/crédito/Pix da abertura ficam só como carryover por maquininha
     * (ver FechamentoCaixa::totalPorCategoria) — não viram movimento/esperado.
     * Só o dinheiro (fungível, sem como distinguir troco de venda) entra como
     * movimento de sessão.
     */
    public function test_finalizar_abertura_so_registra_movimento_de_dinheiro(): void
    {
        $sessao = $this->sessaoAberta();
        $nota = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);
        SessaoCaixaNota::create(['sessao_caixa_id' => $sessao->id, 'nota_moeda_id' => $nota->id, 'quantidade' => 1]);

        app(SessaoCaixaService::class)->finalizarAbertura($sessao);

        $movimentos = MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)->get();

        $this->assertCount(1, $movimentos);
        $this->assertSame('dinheiro', $movimentos->first()->mov_forma_pagamento->value);
        $this->assertEqualsWithDelta(100.0, (float) $movimentos->first()->mov_valor, 0.01);
    }

    public function test_finalizar_abertura_nao_registra_movimento_quando_dinheiro_zerado(): void
    {
        $sessao = $this->sessaoAberta();
        $nota = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);
        SessaoCaixaNota::create(['sessao_caixa_id' => $sessao->id, 'nota_moeda_id' => $nota->id, 'quantidade' => 0]);

        app(SessaoCaixaService::class)->finalizarAbertura($sessao);

        $this->assertSame(0, MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)->count());
    }

    /**
     * Regressão: o Repeater 'notas' (ContagemNotasSchema) e o Repeater
     * 'maquininhas' ficam com ->disabled() amarrado ao mesmo record
     * (SessaoCaixaForm) — o Filament liga isSaved() ao inverso dessa mesma
     * condição, que vira true assim que o registro é criado, no meio do
     * próprio saveRelationships() (que roda depois de handleRecordCreation()).
     * Sem ->saveRelationshipsWhenDisabled() nos dois Repeaters/Sections, as
     * linhas nunca eram persistidas e sessaocaixa_saldo_inicial ficava
     * sempre 0 — ver ContagemNotasSchema::make() e SessaoCaixaForm.
     */
    public function test_create_via_livewire_persiste_notas_e_saldo_inicial(): void
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);
        $user->assignRole('Admin');
        $notaCem = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);

        $this->actingAs($user);

        $component = Livewire::test(CreateSessaoCaixa::class)
            ->set('data.sessaocaixa_caixa_id', $caixa->id);

        $notaKey = collect($component->get('data')['notas'])
            ->search(fn (array $row): bool => $row['nota_moeda_id'] === $notaCem->id);

        $component->set("data.notas.$notaKey.quantidade", 2)
            ->call('create')
            ->assertHasNoErrors();

        $sessao = SessaoCaixa::latest('id')->first();

        $this->assertSame(1, $sessao->notas()->count());
        $this->assertEqualsWithDelta(200.0, (float) $sessao->sessaocaixa_saldo_inicial, 0.01);
    }
}
