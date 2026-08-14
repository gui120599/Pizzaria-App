<?php

namespace Tests\Feature;

use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperarVendaSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function sessaoCaixaAberta(User $user): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    public function test_bloqueia_usuario_sem_permissao_operar_venda(): void
    {
        $this->actingAs(User::factory()->create(['name_first' => 'Sem Permissão']));

        $this->get('/admin/vendas/operar')->assertForbidden();
    }

    public function test_redireciona_para_sessao_caixa_quando_nao_ha_caixa_aberto(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(OperarVenda::class)->assertRedirect(route('sessao_caixa'));
    }

    public function test_monta_sem_erro_com_sessao_de_caixa_aberta_sem_criar_venda(): void
    {
        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);
        $sessaoCaixa = $this->sessaoCaixaAberta($user);

        Livewire::test(OperarVenda::class)
            ->assertOk()
            ->assertSet('sessaoCaixaId', $sessaoCaixa->id)
            ->assertSet('vendaId', null);

        // Só abrir a página não pode criar registro nenhum — a Venda é lazy,
        // criada só no primeiro lançamento real (ver iniciarVendaSeNecessario()),
        // pra não queimar o autoincrement usado como número da NF-e.
        $this->assertSame(0, Venda::count());
    }

    public function test_pagamentos_orfaos_sem_venda_nao_aparecem_antes_da_venda_existir(): void
    {
        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);
        $this->sessaoCaixaAberta($user);

        $opcao = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
        // Registro órfão (pg_venda_venda_id nulo) — pode existir na base por
        // dados legados. where('pg_venda_venda_id', null) do Eloquent vira
        // whereNull, então sem essa proteção ele apareceria como se fosse
        // pagamento da venda ainda nem criada.
        PagamentosVenda::create([
            'pg_venda_venda_id' => null,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => 100,
            'pg_venda_valor_recebido' => 100,
            'pg_venda_valor_pago_pelo_cliente' => 100,
            'pg_venda_valor_troco' => 0,
            'pg_venda_valor_acrescimo' => 0,
            'pg_venda_valor_desconto' => 0,
        ]);

        $component = Livewire::test(OperarVenda::class)->assertSet('vendaId', null);

        $this->assertCount(0, $component->instance()->pagamentosLancados);
        $this->assertCount(0, $component->instance()->itensCarrinho);
    }

    public function test_alternar_padrao_dos_cards_persiste_em_sessao(): void
    {
        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);
        $this->sessaoCaixaAberta($user);

        Livewire::test(OperarVenda::class)
            ->assertSet('abrirCardsPorPadrao', false)
            ->call('alternarPadraoCards')
            ->assertSet('abrirCardsPorPadrao', true);

        // Uma nova montagem da página (ex.: reload) deve lembrar a preferência.
        Livewire::test(OperarVenda::class)
            ->assertSet('abrirCardsPorPadrao', true);
    }

    public function test_monta_com_venda_existente_via_parametro_de_rota(): void
    {
        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);
        $sessaoCaixa = $this->sessaoCaixaAberta($user);

        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
        ]);

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->assertOk()
            ->assertSet('vendaId', $venda->id);
    }
}
