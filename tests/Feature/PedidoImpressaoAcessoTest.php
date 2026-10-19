<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * O ticket fica fora do grupo auth (impressão sem sessão), mas só abre com
 * link assinado dentro do prazo ou com usuário logado que possa ver o pedido.
 */
class PedidoImpressaoAcessoTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(): Pedido
    {
        return Pedido::create(['pedido_status' => 'ABERTO', 'pedido_valor_total' => 40.0]);
    }

    public function test_sem_sessao_e_sem_assinatura_e_proibido(): void
    {
        $pedido = $this->pedido();

        $this->get(route('pedido.imprimir', ['id' => $pedido->id]))->assertForbidden();
    }

    public function test_sem_sessao_com_assinatura_valida_abre(): void
    {
        $pedido = $this->pedido();

        $this->get($pedido->linkImpressao())->assertOk();
    }

    public function test_assinatura_vencida_e_proibida(): void
    {
        $pedido = $this->pedido();
        $link = $pedido->linkImpressao();

        $this->travel(25)->hours();

        $this->get($link)->assertForbidden();
    }

    public function test_validade_configurada_na_empresa_e_respeitada(): void
    {
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste', 'empresa_validade_link_ticket_horas' => 2]);
        $pedido = $this->pedido();
        $link = $pedido->linkImpressao();

        $this->travel(1)->hours();
        $this->get($link)->assertOk();

        $this->travel(2)->hours();
        $this->get($link)->assertForbidden();
    }

    public function test_assinatura_adulterada_e_proibida(): void
    {
        $pedido = $this->pedido();
        $outro = $this->pedido();

        $this->get(str_replace("/Pedido/{$pedido->id}/", "/Pedido/{$outro->id}/", $pedido->linkImpressao()))->assertForbidden();
    }

    public function test_logado_com_view_pedido_abre_sem_assinatura(): void
    {
        $pedido = $this->pedido();
        $user = User::factory()->caixa()->create(['name_first' => 'Caixa']);
        $user->givePermissionTo(Permission::findOrCreate('view:pedido'));

        $this->actingAs($user)->get(route('pedido.imprimir', ['id' => $pedido->id]))->assertOk();
    }

    public function test_logado_sem_view_pedido_e_proibido_sem_assinatura(): void
    {
        $this->seed(PermissionSeeder::class);
        $pedido = $this->pedido();

        $this->actingAs(User::factory()->entregador()->create(['name_first' => 'Entregador']))
            ->get(route('pedido.imprimir', ['id' => $pedido->id]))
            ->assertForbidden();
    }

    public function test_pedido_inexistente_devolve_404(): void
    {
        $this->get(URL::temporarySignedRoute('pedido.imprimir', now()->addHour(), ['id' => 999999]))->assertNotFound();
    }

    public function test_pedido_excluido_devolve_404(): void
    {
        $pedido = $this->pedido();
        $link = $pedido->linkImpressao();
        $pedido->delete();

        $this->get($link)->assertNotFound();
    }
}
