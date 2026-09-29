<?php

namespace Tests\Feature;

use App\Livewire\VendasSemSessaoCaixa;
use App\Models\Caixa;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendasSemSessaoCaixaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function sessaoCaixaAberta(): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
    }

    private function vendaOrfa(float $valor = 50.0): Venda
    {
        return Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_sessao_caixa_id' => null,
            'venda_valor_total' => $valor,
            'venda_valor_pago' => $valor,
            'venda_datahora_finalizada' => now(),
        ]);
    }

    public function test_lista_apenas_vendas_orfas_finalizada(): void
    {
        $orfa = $this->vendaOrfa(50.0);
        $comSessao = $this->vendaOrfa(30.0);
        $comSessao->update(['venda_sessao_caixa_id' => $this->sessaoCaixaAberta()->id]);
        $iniciadaSemSessao = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => null]);

        $sessao = $this->sessaoCaixaAberta();

        Livewire::test(VendasSemSessaoCaixa::class, ['sessaoCaixaId' => $sessao->id])
            ->call('abrirModal')
            ->assertSee("#{$orfa->id}")
            ->assertDontSee("#{$comSessao->id}")
            ->assertDontSee("#{$iniciadaSemSessao->id}");
    }

    public function test_auto_abrir_marca_todas_por_padrao_quando_ha_orfas(): void
    {
        $sessao = $this->sessaoCaixaAberta();
        $orfa1 = $this->vendaOrfa(50.0);
        $orfa2 = $this->vendaOrfa(30.0);

        Livewire::test(VendasSemSessaoCaixa::class, ['sessaoCaixaId' => $sessao->id, 'autoAbrir' => true])
            ->assertSet('modalAberta', true)
            ->assertSet('selecionadas', [$orfa1->id, $orfa2->id]);
    }

    public function test_auto_abrir_nao_abre_modal_sem_orfas(): void
    {
        $sessao = $this->sessaoCaixaAberta();

        Livewire::test(VendasSemSessaoCaixa::class, ['sessaoCaixaId' => $sessao->id, 'autoAbrir' => true])
            ->assertSet('modalAberta', false);
    }

    public function test_vincula_apenas_as_marcadas_e_ignora_as_desmarcadas(): void
    {
        $sessao = $this->sessaoCaixaAberta();
        $orfa1 = $this->vendaOrfa(50.0);
        $orfa2 = $this->vendaOrfa(30.0);

        Livewire::test(VendasSemSessaoCaixa::class, ['sessaoCaixaId' => $sessao->id])
            ->call('abrirModal')
            ->set('selecionadas', [$orfa1->id])
            ->call('vincular')
            ->assertSet('modalAberta', false);

        $this->assertSame($sessao->id, $orfa1->fresh()->venda_sessao_caixa_id);
        $this->assertNull($orfa2->fresh()->venda_sessao_caixa_id);
        $this->assertSame(50.0, (float) $sessao->fresh()->sessaocaixa_saldo_final);
    }
}
