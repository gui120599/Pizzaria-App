<?php

namespace Tests\Feature;

use App\Enums\MotivoExclusaoPedidoEnum;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Widgets\MotivosCancelamentoChart;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PedidosTableExclusaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    private function pedido(string $status = 'CANCELADO'): Pedido
    {
        return Pedido::create(['pedido_status' => $status, 'pedido_valor_total' => 30.0, 'pedido_datahora_abertura' => now()]);
    }

    /** view_any/view vêm do Shield em produção; o PermissionSeeder só cobre as de negócio. */
    private function usuario(string $role): User
    {
        $user = User::factory()->{$role}()->create(['name_first' => ucfirst($role)]);
        $user->givePermissionTo([Permission::findOrCreate('view_any:pedido'), Permission::findOrCreate('view:pedido')]);

        return $user;
    }

    private function gerente(): User
    {
        return $this->usuario('gerente');
    }

    public function test_gerente_exclui_pela_tabela_e_o_pedido_vai_para_a_lixeira(): void
    {
        $gerente = $this->gerente();
        $pedido = $this->pedido();

        Livewire::actingAs($gerente)->test(ListPedidos::class)
            ->callAction(TestAction::make('excluir')->table($pedido), [
                'motivo' => MotivoExclusaoPedidoEnum::TESTE_TREINAMENTO->value,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Pedido #'.$pedido->id.' excluído');

        $this->assertSoftDeleted('pedidos', ['id' => $pedido->id, 'pedido_usuario_excluiu_id' => $gerente->id]);
    }

    public function test_outro_sem_observacao_gera_erro_de_validacao(): void
    {
        $pedido = $this->pedido();

        Livewire::actingAs($this->gerente())->test(ListPedidos::class)
            ->callAction(TestAction::make('excluir')->table($pedido), ['motivo' => MotivoExclusaoPedidoEnum::OUTRO->value])
            ->assertHasFormErrors(['observacao']);

        $this->assertNotSoftDeleted('pedidos', ['id' => $pedido->id]);
    }

    public function test_atendente_e_caixa_nao_veem_a_acao_de_excluir(): void
    {
        $pedido = $this->pedido();

        foreach (['atendente', 'caixa'] as $role) {
            $user = $this->usuario($role);

            Livewire::actingAs($user)->test(ListPedidos::class)
                ->assertActionHidden(TestAction::make('excluir')->table($pedido));
        }
    }

    public function test_acao_nao_aparece_para_pedido_em_preparo(): void
    {
        $pedido = $this->pedido('PREPARANDO');

        Livewire::actingAs($this->gerente())->test(ListPedidos::class)
            ->assertActionHidden(TestAction::make('excluir')->table($pedido));
    }

    public function test_exclusao_em_lote_informa_excluidos_e_ignorados(): void
    {
        $cancelado = $this->pedido('CANCELADO');
        $preparando = $this->pedido('PREPARANDO');

        Livewire::actingAs($this->gerente())->test(ListPedidos::class)
            ->selectTableRecords([$cancelado->id, $preparando->id])
            ->callAction(TestAction::make('excluirEmLote')->table()->bulk(), [
                'motivo' => MotivoExclusaoPedidoEnum::DUPLICADO->value,
            ])
            ->assertNotified(Notification::make()->title('1 excluído(s), 1 ignorado(s)')->color('warning')->body('#'.$preparando->id.': O pedido '.$preparando->id.' está em "Preparando". Cancele o pedido antes de excluir: envolve estoque e cozinha.'));

        $this->assertSoftDeleted('pedidos', ['id' => $cancelado->id]);
        $this->assertNotSoftDeleted('pedidos', ['id' => $preparando->id]);
    }

    public function test_restaurar_so_aparece_na_lixeira_e_devolve_cancelado(): void
    {
        $gerente = $this->gerente();
        $pedido = $this->pedido();
        $pedido->delete();

        Livewire::actingAs($gerente)->test(ListPedidos::class)
            ->assertCanNotSeeTableRecords([$pedido])
            ->set('activeTab', 'lixeira')
            ->assertCanSeeTableRecords([$pedido])
            ->callAction(TestAction::make('restore')->table($pedido))
            ->assertNotified();

        $this->assertNotSoftDeleted('pedidos', ['id' => $pedido->id, 'pedido_status' => 'CANCELADO']);
    }

    public function test_nenhuma_acao_de_exclusao_fisica_na_tabela_nem_para_admin(): void
    {
        $pedido = $this->pedido();
        $pedido->delete();

        Livewire::actingAs(User::factory()->admin()->create(['name_first' => 'Admin']))->test(ListPedidos::class)
            ->set('activeTab', 'lixeira')
            ->assertActionDoesNotExist(TestAction::make('forceDelete')->table($pedido))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($pedido))
            ->assertTableBulkActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('delete');
    }

    public function test_grafico_de_motivos_nao_conta_pedido_excluido(): void
    {
        $contado = $this->pedido();
        $contado->update(['pedido_motivo_cancelamento' => 'demora', 'pedido_datahora_cancelado' => now()]);
        $excluido = $this->pedido();
        $excluido->update(['pedido_motivo_cancelamento' => 'demora', 'pedido_datahora_cancelado' => now()]);
        $excluido->delete();

        $grafico = Livewire::actingAs($this->gerente())->test(MotivosCancelamentoChart::class)->instance();
        $dados = (new ReflectionMethod($grafico, 'getData'))->invoke($grafico);

        $this->assertSame([1], $dados['datasets'][0]['data']);
    }
}
