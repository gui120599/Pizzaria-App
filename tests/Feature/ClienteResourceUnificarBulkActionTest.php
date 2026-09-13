<?php

namespace Tests\Feature;

use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Clientes\Pages\ListClientes;
use App\Filament\Resources\Clientes\RelationManagers\PedidosRelationManager;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Cobre a integração Filament da unificação de clientes (UnificarClientesBulkAction
 * + ClienteMergeService, já testado isoladamente em ClienteMergeServiceTest) e o
 * novo PedidosRelationManager no ClienteResource.
 */
class ClienteResourceUnificarBulkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_unifica_clientes_pela_bulk_action(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $principal = Cliente::create(['cliente_nome' => 'Maria Principal', 'cliente_celular' => '11988887777', 'cliente_tipo' => 'Física']);
        $duplicado = Cliente::create(['cliente_nome' => 'Maria Duplicada', 'cliente_cpf' => '98765432100', 'cliente_tipo' => 'Física']);
        $pedido = Pedido::create(['pedido_status' => 'FINALIZADO', 'pedido_cliente_id' => $duplicado->id]);

        Livewire::test(ListClientes::class)
            ->callTableBulkAction('unificarClientes', [$principal, $duplicado], [
                'cliente_principal_id' => $principal->id,
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame($principal->id, $pedido->fresh()->pedido_cliente_id);
        $this->assertSame('98765432100', $principal->fresh()->cliente_cpf);
        $this->assertSoftDeleted('clientes', ['id' => $duplicado->id]);
    }

    public function test_bulk_action_de_unificar_fica_oculta_para_usuario_sem_role_admin(): void
    {
        // RefreshDatabase não roda os seeders de permissão do painel; para exercitar
        // o ->visible(hasRole('Admin')) da bulk action com um usuário que ENXERGA a
        // tela de Clientes mas não é Admin, concede só a permission necessária pra
        // isso (view_any:cliente, conforme App\Policies\ClientePolicy::viewAny).
        $user = User::factory()->create(['name_first' => 'Gerente']);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:cliente', 'guard_name' => 'web']));
        $this->actingAs($user);

        Cliente::create(['cliente_nome' => 'Cliente 1', 'cliente_tipo' => 'Física']);
        Cliente::create(['cliente_nome' => 'Cliente 2', 'cliente_tipo' => 'Física']);

        Livewire::test(ListClientes::class)
            ->assertOk()
            ->assertTableBulkActionHidden('unificarClientes');
    }

    public function test_pedidos_relation_manager_monta_e_lista_pedidos_do_cliente(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $cliente = Cliente::create(['cliente_nome' => 'Cliente Com Pedidos', 'cliente_tipo' => 'Física']);
        $pedido = Pedido::create([
            'pedido_status' => 'FINALIZADO',
            'pedido_cliente_id' => $cliente->id,
            'pedido_valor_total' => 42.5,
        ]);

        Livewire::test(PedidosRelationManager::class, [
            'ownerRecord' => $cliente,
            'pageClass' => EditCliente::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords([$pedido]);
    }
}
