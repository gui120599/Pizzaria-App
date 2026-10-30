<?php

namespace Tests\Feature;

use App\Enums\TipoAtendimentoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Atender Pedido: tipo de atendimento no topo, retirada sem taxa de entrega e somente leitura sem travar a tela. */
class AtenderPedidoTipoAtendimentoTest extends TestCase
{
    use RefreshDatabase;

    private OpcoesEntregas $retirada;

    private OpcoesEntregas $entrega;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada na loja', 'opcaoentrega_requer_endereco' => false, 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $this->entrega = OpcoesEntregas::create(['opcaoentrega_nome' => 'Entregar', 'opcaoentrega_requer_endereco' => true, 'opcaoentrega_valor_frete' => 6, 'opcaoentrega_min_valor_frete' => 0]);
    }

    private function pedido(OpcoesEntregas $opcao, string $status = 'ABERTO'): Pedido
    {
        return Pedido::create(['pedido_status' => $status, 'pedido_opcaoentrega_id' => $opcao->id]);
    }

    public function test_retirada_mostra_o_tipo_e_nao_mostra_a_taxa_de_entrega(): void
    {
        $tela = Livewire::test(AtenderPedido::class, ['pedido' => $this->pedido($this->retirada)]);

        $tela->assertSee('Tipo de atendimento')
            ->assertSee('Retirada')
            ->assertDontSee('Taxa de entrega');

        $this->assertSame(TipoAtendimentoEnum::RETIRADA, $tela->instance()->tipoAtendimento());
    }

    public function test_entrega_mostra_o_tipo_e_a_taxa_de_entrega(): void
    {
        $tela = Livewire::test(AtenderPedido::class, ['pedido' => $this->pedido($this->entrega)]);

        $tela->assertSee('Entrega')->assertSee('Taxa de entrega');

        $this->assertSame(TipoAtendimentoEnum::DELIVERY, $tela->instance()->tipoAtendimento());
    }

    public function test_trocar_a_opcao_na_tela_troca_o_tipo(): void
    {
        $tela = Livewire::test(AtenderPedido::class, ['pedido' => $this->pedido($this->retirada)])
            ->call('onEntregaPagamentoAtualizado', ['opcaoEntregaId' => $this->entrega->id, 'pagamentos' => [], 'requerEndereco' => true]);

        $this->assertSame(TipoAtendimentoEnum::DELIVERY, $tela->instance()->tipoAtendimento());
        $tela->assertSee('Taxa de entrega');
    }

    public function test_pedido_de_mesa_aparece_como_mesa(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'ABERTO', 'pedido_opcaoentrega_id' => $this->retirada->id]);
        $pedido->pedido_sessao_mesa_id = 1;

        // Só a leitura do atributo importa para o tipo; sem persistir a sessão.
        $this->assertSame(TipoAtendimentoEnum::MESA, $pedido->tipoAtendimento());
    }

    public function test_somente_leitura_desabilita_os_campos_sem_pointer_events_inline(): void
    {
        Livewire::test(AtenderPedido::class, ['pedido' => $this->pedido($this->retirada, 'FINALIZADO')])
            ->assertSet('somenteLeitura', true)
            ->assertSeeHtml('<fieldset disabled')
            ->assertDontSeeHtml('pointer-events: none')
            ->assertDontSeeHtml('opacity: .6');
    }

    public function test_pedido_editavel_nao_desabilita_os_campos(): void
    {
        Livewire::test(AtenderPedido::class, ['pedido' => $this->pedido($this->retirada)])
            ->assertDontSeeHtml('<fieldset disabled');
    }
}
