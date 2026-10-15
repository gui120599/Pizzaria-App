<?php

namespace Tests\Feature;

use App\Enums\StatusPedidoEnum;
use App\Filament\Pages\PainelPedidos;
use App\Filament\Pages\RelatorioProducao;
use App\Filament\Resources\DispositivosCozinha\DispositivoCozinhaResource;
use App\Models\ChecklistModelo;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Models\User;
use App\Services\Cozinha\ProducaoService;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** cozinha.ativo = false: a loja trabalha como antes dos tablets. */
class CozinhaModuloDesligadoTest extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    private SetorProducao $pizzaria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
        $user = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:pedido', 'guard_name' => 'web']));
        $this->actingAs($user);
    }

    public function test_pedido_aberto_com_o_modulo_desligado_nao_gera_producao(): void
    {
        config(['cozinha.ativo' => false]);
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas), 2);
        $this->abrir($pedido);

        $this->assertSame(0, ProducaoItem::count());
    }

    public function test_painel_marca_pronto_direto_e_encerra_os_itens_que_ja_existiam(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas), 2);
        $this->abrir($pedido);
        app(ProducaoService::class)->iniciar(ProducaoItem::orderBy('id')->first(), $this->operador());
        $this->assertSame(2, app(ProducaoService::class)->pendentesDoPedido($pedido));

        config(['cozinha.ativo' => false]);

        Livewire::test(PainelPedidos::class)
            ->assertDontSee('Cozinha: Em produção')
            ->assertDontSee('/2 prontos')
            ->assertDontSee('Ver produção')
            ->mountAction('avancar', ['pedido' => $pedido->id])
            ->assertActionNotMounted('avancar');

        $this->assertSame(StatusPedidoEnum::PRONTO, $pedido->fresh()->status());
        $this->assertSame(0, app(ProducaoService::class)->pendentesDoPedido($pedido), 'Não podem reaparecer quando a cozinha for ligada.');
    }

    public function test_painel_cozinha_tablets_e_relatorio_fecham_e_checklists_voltam_ao_garcom(): void
    {
        config(['cozinha.ativo' => false, 'cozinha.checklists.exibir_tambem_no_garcom' => false]);

        $this->assertFalse(auth()->user()->canAccessPanel(Filament::getPanel('cozinha')));
        $this->assertFalse(DispositivoCozinhaResource::canAccess());
        $this->assertFalse(RelatorioProducao::canAccess());
        $this->assertTrue(ChecklistModelo::cozinhaTambemNoGarcom());

        config(['cozinha.ativo' => true]);

        $this->assertTrue(auth()->user()->canAccessPanel(Filament::getPanel('cozinha')));
        $this->assertFalse(ChecklistModelo::cozinhaTambemNoGarcom());
    }
}
