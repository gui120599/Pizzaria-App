<?php

namespace Tests\Feature;

use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PedidosTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $user = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $user->givePermissionTo([Permission::findOrCreate('view_any:pedido'), Permission::findOrCreate('view:pedido')]);
        $this->actingAs($user);
    }

    private function pedido(string $status, array $extra = []): Pedido
    {
        return Pedido::create(['pedido_status' => $status, 'pedido_valor_total' => 25.0, 'pedido_datahora_abertura' => now()] + $extra);
    }

    public function test_aba_padrao_nao_lista_rascunhos_e_a_aba_rascunhos_lista(): void
    {
        $aberto = $this->pedido('ABERTO');
        $rascunho = $this->pedido('INICIADO');

        Livewire::test(ListPedidos::class)
            ->assertCanSeeTableRecords([$aberto])
            ->assertCanNotSeeTableRecords([$rascunho])
            ->set('activeTab', 'rascunhos')
            ->assertCanSeeTableRecords([$rascunho])
            ->assertCanNotSeeTableRecords([$aberto]);
    }

    public function test_busca_por_numero_e_por_celular(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Maria Souza', 'cliente_tipo' => 'PF', 'cliente_celular' => '62998765432']);
        $alvo = $this->pedido('ABERTO', ['pedido_cliente_id' => $cliente->id]);
        $outro = $this->pedido('ABERTO');

        Livewire::test(ListPedidos::class)
            ->searchTable((string) $alvo->id)
            ->assertCanSeeTableRecords([$alvo])
            ->assertCanNotSeeTableRecords([$outro])
            ->searchTable('99876543')
            ->assertCanSeeTableRecords([$alvo])
            ->assertCanNotSeeTableRecords([$outro]);
    }

    public function test_status_aparece_com_rotulo_legivel(): void
    {
        $this->pedido('EM TRANSPORTE');

        Livewire::test(ListPedidos::class)->assertSee('Em transporte');
    }

    public function test_nenhuma_celula_mostra_os_valores_padrao_das_relacoes(): void
    {
        $this->pedido('ABERTO');

        Livewire::test(ListPedidos::class)
            ->assertDontSee('S/G')
            ->assertDontSee('S/E')
            ->assertDontSee('S/OPE')
            ->assertDontSee('S/M');
    }

    public function test_pedido_sem_data_de_abertura_mostra_a_data_de_criacao(): void
    {
        $rascunho = Pedido::create(['pedido_status' => 'INICIADO']);

        Livewire::test(ListPedidos::class)
            ->set('activeTab', 'rascunhos')
            ->assertSee($rascunho->created_at->format('d/m/Y H:i'));
    }
}
