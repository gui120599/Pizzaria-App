<?php

namespace Tests\Feature;

use App\Filament\Resources\Clientes\Actions\DuplicidadesCelularAction;
use App\Filament\Resources\Clientes\Pages\ListClientes;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Modal "Duplicidades por celular" da lista de Clientes. */
class DuplicidadesCelularActionTest extends TestCase
{
    use RefreshDatabase;

    /** Cadastro de antes da normalização, gravado do jeito que veio. */
    private function clienteAntigo(string $nome, string $celularGravado): Cliente
    {
        $id = DB::table('clientes')->insertGetId([
            'cliente_nome' => $nome,
            'cliente_tipo' => 'Física',
            'cliente_celular' => $celularGravado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->artisan('clientes:normalizar-telefones')->assertSuccessful();

        return Cliente::findOrFail($id);
    }

    public function test_agrupa_so_cadastros_do_mesmo_numero_em_formatos_diferentes(): void
    {
        $this->clienteAntigo('Maria', '(62) 8123-4567');
        $this->clienteAntigo('Maria S.', '+55 62 98123-4567');
        $this->clienteAntigo('Outra DDD', '64981234567');
        $this->clienteAntigo('Sozinha', '62999990000');

        $this->assertSame(
            ['62981234567' => '(62) 98123-4567 — 2 cadastros: Maria, Maria S.'],
            DuplicidadesCelularAction::opcoesGrupos(),
        );
    }

    public function test_admin_unifica_os_cadastros_marcados_e_os_pedidos_passam_para_o_que_permanece(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $principal = $this->clienteAntigo('Maria', '(62) 8123-4567');
        $duplicado = $this->clienteAntigo('Maria S.', '62981234567');
        $mesmoNumeroOutraPessoa = $this->clienteAntigo('Filha da Maria', '5562981234567');
        $pedidoDoDuplicado = Pedido::create(['pedido_status' => 'FINALIZADO', 'pedido_cliente_id' => $duplicado->id]);

        Livewire::test(ListClientes::class)
            ->callAction('duplicidadesCelular', [
                'grupo' => '62981234567',
                'clientes' => [(string) $principal->id, (string) $duplicado->id],
                'cliente_principal_id' => $principal->id,
                'confirmacao' => true,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Clientes unificados com sucesso');

        $this->assertSame($principal->id, $pedidoDoDuplicado->fresh()->pedido_cliente_id);
        $this->assertSoftDeleted('clientes', ['id' => $duplicado->id]);
        $this->assertNotSoftDeleted('clientes', ['id' => $mesmoNumeroOutraPessoa->id]);
    }

    public function test_sem_confirmacao_nada_e_unificado(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $principal = $this->clienteAntigo('Maria', '(62) 8123-4567');
        $duplicado = $this->clienteAntigo('Maria S.', '62981234567');

        Livewire::test(ListClientes::class)
            ->callAction('duplicidadesCelular', [
                'grupo' => '62981234567',
                'clientes' => [(string) $principal->id, (string) $duplicado->id],
                'cliente_principal_id' => $principal->id,
                'confirmacao' => false,
            ])
            ->assertHasActionErrors(['confirmacao' => 'accepted']);

        $this->assertNotSoftDeleted('clientes', ['id' => $duplicado->id]);
    }

    public function test_cliente_de_outro_numero_enviado_junto_e_recusado_e_nada_e_unificado(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $principal = $this->clienteAntigo('Maria', '(62) 8123-4567');
        $duplicado = $this->clienteAntigo('Maria S.', '62981234567');
        $deOutroNumero = $this->clienteAntigo('João', '64999990000');

        Livewire::test(ListClientes::class)
            ->callAction('duplicidadesCelular', [
                'grupo' => '62981234567',
                'clientes' => [(string) $principal->id, (string) $duplicado->id, (string) $deOutroNumero->id],
                'cliente_principal_id' => $principal->id,
                'confirmacao' => true,
            ])
            ->assertHasActionErrors(['clientes.2']);

        $this->assertNotSoftDeleted('clientes', ['id' => $duplicado->id]);
        $this->assertNotSoftDeleted('clientes', ['id' => $deOutroNumero->id]);
    }

    public function test_modal_fica_oculto_para_usuario_sem_role_admin(): void
    {
        $user = User::factory()->create(['name_first' => 'Gerente']);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:cliente', 'guard_name' => 'web']));
        $this->actingAs($user);

        Livewire::test(ListClientes::class)->assertActionHidden('duplicidadesCelular');
    }
}
