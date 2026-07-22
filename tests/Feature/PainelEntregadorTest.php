<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\PainelEntregador;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PainelEntregadorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // AUTO_INCREMENT do MySQL não é transacional: mesmo com o rollback
        // do RefreshDatabase entre testes, o contador continua subindo.
        // Reseta pra cada teste conseguir prever o id 3 (checagem hardcoded
        // de "é delivery" no componente).
        DB::statement('ALTER TABLE opcoes_entregas AUTO_INCREMENT = 1');
    }

    private function opcaoDelivery(): OpcoesEntregas
    {
        // O componente identifica pedido delivery por opcaoentrega_id === 3
        // (mesma checagem hardcoded já usada no Kanban) — cria duas opções de
        // preenchimento antes pra a opção de delivery cair no id 3.
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada']);
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Comer no Local']);

        return OpcoesEntregas::create(['opcaoentrega_nome' => 'Entrega']);
    }

    private function entregador(string $nome = 'Entregador'): User
    {
        return User::factory()->create(['name_first' => $nome]);
    }

    private function pedidoPronto(): Pedido
    {
        $opcao = $this->opcaoDelivery();

        return Pedido::create([
            'pedido_opcaoentrega_id' => $opcao->id,
            'pedido_status' => 'PRONTO',
            'pedido_datahora_pronto' => now(),
            'pedido_valor_total' => 50.0,
        ]);
    }

    public function test_aceitar_atribui_entregador_e_avanca_para_em_transporte(): void
    {
        $entregador = $this->entregador();
        $pedido = $this->pedidoPronto();

        $this->actingAs($entregador);

        Livewire::test(PainelEntregador::class)
            ->call('aceitar', $pedido->id)
            ->assertSet('erroAceite', null);

        $pedido->refresh();

        $this->assertSame('EM TRANSPORTE', $pedido->pedido_status);
        $this->assertSame($entregador->id, $pedido->pedido_usuario_entrega_id);
        $this->assertNotNull($pedido->pedido_datahora_transporte);
    }

    public function test_aceitar_falha_quando_pedido_ja_foi_aceito_por_outro_entregador(): void
    {
        $primeiroEntregador = $this->entregador('Primeiro');
        $segundoEntregador = $this->entregador('Segundo');
        $pedido = $this->pedidoPronto();

        // Simula o primeiro entregador já tendo aceitado o pedido antes do
        // segundo clicar em "Aceitar".
        $pedido->update([
            'pedido_status' => 'EM TRANSPORTE',
            'pedido_datahora_transporte' => now(),
            'pedido_usuario_entrega_id' => $primeiroEntregador->id,
        ]);

        $this->actingAs($segundoEntregador);

        Livewire::test(PainelEntregador::class)
            ->call('aceitar', $pedido->id)
            ->assertSet('erroAceite', fn ($msg) => ! empty($msg));

        $pedido->refresh();
        $this->assertSame($primeiroEntregador->id, $pedido->pedido_usuario_entrega_id);
    }

    public function test_marcar_entregue_e_restrito_ao_entregador_atribuido(): void
    {
        $dono = $this->entregador('Dono');
        $outroEntregador = $this->entregador('Outro');
        $pedido = $this->pedidoPronto();

        $pedido->update([
            'pedido_status' => 'EM TRANSPORTE',
            'pedido_datahora_transporte' => now(),
            'pedido_usuario_entrega_id' => $dono->id,
        ]);

        // Outro entregador não consegue marcar como entregue um pedido que
        // não é dele.
        $this->actingAs($outroEntregador);
        Livewire::test(PainelEntregador::class)->call('marcarEntregue', $pedido->id);

        $pedido->refresh();
        $this->assertSame('EM TRANSPORTE', $pedido->pedido_status);

        // O dono do pedido consegue.
        $this->actingAs($dono);
        Livewire::test(PainelEntregador::class)->call('marcarEntregue', $pedido->id);

        $pedido->refresh();
        $this->assertSame('ENTREGUE', $pedido->pedido_status);
        $this->assertNotNull($pedido->pedido_datahora_entrega);
    }

    public function test_render_lista_disponiveis_e_minhas_entregas(): void
    {
        $entregador = $this->entregador();
        $disponivel = $this->pedidoPronto();

        $minhaEntrega = $this->pedidoPronto();
        $minhaEntrega->update([
            'pedido_status' => 'EM TRANSPORTE',
            'pedido_datahora_transporte' => now(),
            'pedido_usuario_entrega_id' => $entregador->id,
        ]);

        $this->actingAs($entregador);

        Livewire::test(PainelEntregador::class)
            ->assertViewHas('disponiveis', fn ($disponiveis) => $disponiveis->pluck('id')->contains($disponivel->id))
            ->assertViewHas('minhasEntregas', fn ($minhas) => $minhas->pluck('id')->contains($minhaEntrega->id));
    }

    public function test_card_mostra_dropdown_de_itens_e_qrcode(): void
    {
        $entregador = $this->entregador();
        $pedido = $this->pedidoPronto();

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 45.0,
        ]);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 45.0,
            'item_pedido_valor' => 45.0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $this->actingAs($entregador);

        Livewire::test(PainelEntregador::class)
            ->assertSee('Calabresa')
            ->assertSee('1 item do pedido')
            ->assertSee('Ver QR Code do pedido');
    }
}
