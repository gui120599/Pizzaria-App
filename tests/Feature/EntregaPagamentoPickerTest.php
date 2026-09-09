<?php

namespace Tests\Feature;

use App\Livewire\EntregaPagamentoPicker;
use App\Models\OpcoesPagamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O total é #[Reactive] de propósito (ver docblock do componente) — nunca
 * testar mutando isoladamente via ->set('total', x) (lança
 * CannotMutateReactivePropException); o teste sempre recria o componente
 * já com o total certo, passado no construtor do Livewire::test(), como se
 * fosse a Page pai renderizando de novo com um novo total.
 */
class EntregaPagamentoPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_uma_linha_fixa_no_total(): void
    {
        Livewire::test(EntregaPagamentoPicker::class, ['total' => 150.0])
            ->assertSet('pagamentos.0.valor', '150,00');
    }

    public function test_segunda_linha_preenche_com_o_restante(): void
    {
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
        $cartao = OpcoesPagamento::create(['opcaopag_nome' => 'Cartão', 'opcaopag_desc_nfe' => 'creditCard']);

        Livewire::test(EntregaPagamentoPicker::class, ['total' => 100.0])
            ->set('pagamentos.0.opcaoPagamentoId', $dinheiro->id)
            ->set('pagamentos.0.valor', '40,00')
            ->call('adicionarLinhaPagamento')
            ->set('pagamentos.1.opcaoPagamentoId', $cartao->id)
            ->assertSet('pagamentos.1.valor', '60,00');
    }

    /**
     * O reflow "total mudou depois que as linhas já estavam preenchidas" só
     * acontece de verdade quando a MESMA instância do componente recebe um
     * novo valor reativo entre requests — Livewire::test() não tem como
     * simular isso sem recriar o componente (o que reseta
     * ultimoTotalSincronizado junto, mascarando o cenário). Coberto de
     * verdade em AtenderPedidoCriacaoTest, via integração com a Page pai.
     */
    public function test_todas_preenchidas_ultima_absorve_a_diferenca_na_soma(): void
    {
        Livewire::test(EntregaPagamentoPicker::class, ['total' => 100.0])
            ->call('adicionarLinhaPagamento')
            ->set('pagamentos.0.valor', '30,00')
            ->assertSet('pagamentos.1.valor', '70,00');
    }

    public function test_campo_de_troco_so_aparece_para_forma_de_pagamento_em_dinheiro(): void
    {
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
        $cartao = OpcoesPagamento::create(['opcaopag_nome' => 'Cartão', 'opcaopag_desc_nfe' => 'creditCard']);

        $component = Livewire::test(EntregaPagamentoPicker::class, ['total' => 100.0])
            ->set('pagamentos.0.opcaoPagamentoId', $dinheiro->id);

        $this->assertTrue($component->instance()->linhaEhDinheiro(0));

        $component->set('pagamentos.0.opcaoPagamentoId', $cartao->id);
        $this->assertFalse($component->instance()->linhaEhDinheiro(0));
    }

    public function test_remover_linha_nao_deixa_o_array_vazio(): void
    {
        Livewire::test(EntregaPagamentoPicker::class, ['total' => 100.0])
            ->call('adicionarLinhaPagamento')
            ->call('removerLinhaPagamento', 0)
            ->assertCount('pagamentos', 1);
    }
}
