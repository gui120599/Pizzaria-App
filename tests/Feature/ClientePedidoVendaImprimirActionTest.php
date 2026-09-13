<?php

namespace Tests\Feature;

use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Clientes\RelationManagers\PedidosRelationManager;
use App\Filament\Resources\Clientes\RelationManagers\VendasRelationManager;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre os botões "Imprimir" (Pedidos e Vendas) e "DANFE" (Vendas) adicionados
 * aos RelationManagers do ClienteResource — a lógica de emissão em si
 * (pedido.imprimir, venda.imprimir, venda.imprimir_NFE) já é coberta em outros
 * lugares (ex.: PedidoPDFQrCodeTest); aqui só garantimos que os botões existem
 * e que "DANFE" aparece só quando a venda tem NF-e emitida.
 */
class ClientePedidoVendaImprimirActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_pedidos_relation_manager_tem_botao_de_imprimir(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_tipo' => 'Física']);
        $pedido = Pedido::create(['pedido_status' => 'FINALIZADO', 'pedido_cliente_id' => $cliente->id]);

        Livewire::test(PedidosRelationManager::class, [
            'ownerRecord' => $cliente,
            'pageClass' => EditCliente::class,
        ])
            ->assertOk()
            ->assertTableActionExists('imprimir')
            ->assertTableActionVisible('imprimir', $pedido);
    }

    public function test_vendas_relation_manager_tem_botao_de_imprimir_e_danfe_condicional(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Cliente Teste', 'cliente_tipo' => 'Física']);
        $vendaSemNfe = Venda::create(['venda_cliente_id' => $cliente->id, 'venda_status' => 'FINALIZADA']);
        $vendaComNfe = Venda::create([
            'venda_cliente_id' => $cliente->id,
            'venda_status' => 'FINALIZADA',
            'venda_id_nfe' => 'nfe-123',
        ]);

        Livewire::test(VendasRelationManager::class, [
            'ownerRecord' => $cliente,
            'pageClass' => EditCliente::class,
        ])
            ->assertOk()
            ->assertTableActionVisible('imprimir', $vendaSemNfe)
            ->assertTableActionHidden('imprimirDanfe', $vendaSemNfe)
            ->assertTableActionVisible('imprimirDanfe', $vendaComNfe);
    }
}
