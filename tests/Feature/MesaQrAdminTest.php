<?php

namespace Tests\Feature;

use App\Filament\Resources\Mesas\Pages\EditMesa;
use App\Filament\Resources\Mesas\Pages\ListMesas;
use App\Models\Mesa;
use App\Models\User;
use App\Services\MesaCliente\QrCodeMesaService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Admin do QR das mesas: imprimir, gerar novo código e ligar/desligar o
 * pedido pelo celular.
 */
class MesaQrAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_imprime_o_qr_de_uma_mesa_e_de_varias_em_pdf(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        [$mesa1, $mesa2] = Mesa::factory()->count(2)->create();

        Livewire::test(ListMesas::class)
            ->callAction(TestAction::make('imprimirQr')->table($mesa1))
            ->assertFileDownloaded('qr-mesas.pdf');

        Livewire::test(ListMesas::class)
            ->callTableBulkAction('imprimirQrs', [$mesa1, $mesa2])
            ->assertFileDownloaded('qr-mesas.pdf');
    }

    public function test_novo_codigo_invalida_o_qr_impresso(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $mesa = Mesa::factory()->create();
        $codigoAntigo = $mesa->mesa_codigo_qr;

        Livewire::test(EditMesa::class, ['record' => $mesa->id])->callAction('regerarQr');

        $this->assertNotSame($codigoAntigo, $mesa->fresh()->mesa_codigo_qr);
        $this->get('/mesa/'.$codigoAntigo)->assertNotFound();
        $this->get('/mesa/'.$mesa->fresh()->mesa_codigo_qr)->assertOk();
    }

    public function test_liga_e_desliga_o_pedido_pelo_qr_em_lote(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $mesas = Mesa::factory()->count(2)->create();

        Livewire::test(ListMesas::class)->callTableBulkAction('desligarPedidoQr', $mesas);
        $this->assertSame(0, Mesa::where('mesa_pedido_cliente_ativo', true)->count());

        Livewire::test(ListMesas::class)->callTableBulkAction('ligarPedidoQr', $mesas);
        $this->assertSame(2, Mesa::where('mesa_pedido_cliente_ativo', true)->count());
    }

    public function test_link_do_qr_usa_o_endereco_publico_e_nao_o_de_quem_imprime(): void
    {
        config(['app.url' => 'https://pizzaria.exemplo.com.br']);
        $mesa = Mesa::factory()->create();

        $this->assertSame(
            'https://pizzaria.exemplo.com.br/mesa/'.$mesa->mesa_codigo_qr,
            app(QrCodeMesaService::class)->url($mesa),
        );
    }

    public function test_quem_so_ve_mesas_nao_gera_codigo_novo(): void
    {
        $usuario = User::factory()->create(['name_first' => 'Caixa']);
        foreach (['view_any:mesa', 'view:mesa'] as $permissao) {
            $usuario->givePermissionTo(Permission::firstOrCreate(['name' => $permissao, 'guard_name' => 'web']));
        }
        $this->actingAs($usuario);
        $mesa = Mesa::factory()->create();

        Livewire::test(ListMesas::class)
            ->assertActionVisible(TestAction::make('imprimirQr')->table($mesa))
            ->assertActionHidden(TestAction::make('regerarQr')->table($mesa));
    }
}
