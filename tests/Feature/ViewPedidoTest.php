<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ViewPedidoTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->produto = Produto::create([
            'produto_descricao' => 'Pizza Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
        ]);
    }

    private function usuario(string $role): User
    {
        $user = User::factory()->{$role}()->create(['name_first' => ucfirst($role)]);
        $user->givePermissionTo([Permission::findOrCreate('view_any:pedido'), Permission::findOrCreate('view:pedido')]);

        return $user;
    }

    private function pedido(string $status = 'ABERTO', int $itens = 1): Pedido
    {
        $pedido = Pedido::create(['pedido_status' => $status, 'pedido_valor_total' => 30.0, 'pedido_datahora_abertura' => now()]);

        for ($i = 0; $i < $itens; $i++) {
            ItensPedido::create([
                'item_pedido_pedido_id' => $pedido->id,
                'item_pedido_produto_id' => $this->produto->id,
                'item_pedido_quantidade' => 1,
                'item_pedido_valor_unitario' => 30,
                'item_pedido_valor' => 30,
                'item_pedido_status' => 'INSERIDO',
            ]);
        }

        return $pedido;
    }

    public function test_caixa_abre_o_pedido_e_ve_itens_e_linha_do_tempo(): void
    {
        $this->actingAs($this->usuario('caixa'));
        $pedido = $this->pedido('PREPARANDO');
        $pedido->update(['pedido_status' => 'PRONTO']);

        Livewire::test(ViewPedido::class, ['record' => $pedido->id])
            ->assertSuccessful()
            ->assertSee('Pizza Calabresa')
            ->assertSee('Linha do tempo')
            ->assertSee('Pronto')
            ->assertSee('Valor do pedido');
    }

    public function test_finalizado_nao_mostra_editar(): void
    {
        $this->actingAs($this->usuario('gerente'));

        Livewire::test(ViewPedido::class, ['record' => $this->pedido('FINALIZADO')->id])
            ->assertActionHidden('editar');
    }

    public function test_pedido_aberto_mostra_editar_para_quem_pode_atualizar(): void
    {
        $this->actingAs($this->usuario('gerente'));

        Livewire::test(ViewPedido::class, ['record' => $this->pedido('ABERTO')->id])
            ->assertActionVisible('editar');
    }

    public function test_pedido_excluido_abre_para_gerente_e_da_404_para_atendente(): void
    {
        $pedido = $this->pedido('CANCELADO');
        $pedido->update(['pedido_motivo_exclusao' => 'duplicado', 'pedido_exclusao_observacao' => 'Lançado duas vezes']);
        $pedido->delete();

        $this->actingAs($this->usuario('gerente'));
        Livewire::test(ViewPedido::class, ['record' => $pedido->id])
            ->assertSee('Pedido excluído')
            ->assertSee('Lançado duas vezes')
            ->assertActionVisible('restore');

        $this->actingAs($this->usuario('atendente'));
        $this->get(ViewPedido::getUrl(['record' => $pedido->id]))->assertNotFound();
    }

    public function test_quantidade_de_consultas_nao_cresce_com_os_itens(): void
    {
        $this->actingAs($this->usuario('gerente'));
        $pequeno = $this->pedido(itens: 1);
        $grande = $this->pedido(itens: 10);

        $contar = function (Pedido $pedido): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ViewPedido::class, ['record' => $pedido->id])->assertSuccessful();

            return count(DB::getQueryLog());
        };

        $contar($pequeno); // aquece caches (permissões, etc.)

        $this->assertSame($contar($pequeno), $contar($grande));
    }
}
