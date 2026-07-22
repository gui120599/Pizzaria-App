<?php

namespace Tests\Feature;

use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EntregaScanControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mesmo motivo do PainelEntregadorTest: AUTO_INCREMENT não é
        // transacional no MySQL, reseta pra opcao "Entrega" cair no id 3
        // (checagem hardcoded reaproveitada do Kanban).
        DB::statement('ALTER TABLE opcoes_entregas AUTO_INCREMENT = 1');
    }

    private function entregador(string $nome = 'Entregador'): User
    {
        $user = User::factory()->create(['name_first' => $nome]);
        $user->assignRole('Entregador');

        return $user;
    }

    private function pedidoPronto(): Pedido
    {
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada']);
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Comer no Local']);
        $entrega = OpcoesEntregas::create(['opcaoentrega_nome' => 'Entrega']);

        return Pedido::create([
            'pedido_opcaoentrega_id' => $entrega->id,
            'pedido_status' => 'PRONTO',
            'pedido_datahora_pronto' => now(),
            'pedido_valor_total' => 50.0,
        ]);
    }

    public function test_get_scan_mostra_confirmacao_de_saida_para_pedido_pronto(): void
    {
        $pedido = $this->pedidoPronto();
        $entregador = $this->entregador();

        $this->actingAs($entregador)
            ->get($pedido->linkScanEntrega())
            ->assertOk()
            ->assertSee('Confirmar Saída para Entrega');
    }

    public function test_post_scan_confirma_saida_e_atribui_entregador(): void
    {
        $pedido = $this->pedidoPronto();
        $entregador = $this->entregador();

        $this->actingAs($entregador)
            ->post(route('entregador.scan.confirmar', $pedido))
            ->assertOk()
            ->assertSee('Saída confirmada');

        $pedido->refresh();
        $this->assertSame('EM TRANSPORTE', $pedido->pedido_status);
        $this->assertSame($entregador->id, $pedido->pedido_usuario_entrega_id);
    }

    public function test_get_scan_mostra_confirmacao_de_entrega_para_pedido_proprio(): void
    {
        $pedido = $this->pedidoPronto();
        $entregador = $this->entregador();
        $pedido->update([
            'pedido_status' => 'EM TRANSPORTE',
            'pedido_datahora_transporte' => now(),
            'pedido_usuario_entrega_id' => $entregador->id,
        ]);

        $this->actingAs($entregador)
            ->get($pedido->linkScanEntrega())
            ->assertOk()
            ->assertSee('Confirmar Entrega ao Cliente');
    }

    public function test_post_scan_confirma_entrega(): void
    {
        $pedido = $this->pedidoPronto();
        $entregador = $this->entregador();
        $pedido->update([
            'pedido_status' => 'EM TRANSPORTE',
            'pedido_datahora_transporte' => now(),
            'pedido_usuario_entrega_id' => $entregador->id,
        ]);

        $this->actingAs($entregador)
            ->post(route('entregador.scan.confirmar', $pedido))
            ->assertOk()
            ->assertSee('Entrega confirmada');

        $pedido->refresh();
        $this->assertSame('ENTREGUE', $pedido->pedido_status);
    }

    public function test_scan_de_pedido_de_outro_entregador_mostra_indisponivel(): void
    {
        $pedido = $this->pedidoPronto();
        $dono = $this->entregador('Dono');
        $outro = $this->entregador('Outro');
        $pedido->update([
            'pedido_status' => 'EM TRANSPORTE',
            'pedido_datahora_transporte' => now(),
            'pedido_usuario_entrega_id' => $dono->id,
        ]);

        $this->actingAs($outro)
            ->get($pedido->linkScanEntrega())
            ->assertOk()
            ->assertSee('já está com outro entregador');
    }

    public function test_scan_com_assinatura_invalida_e_rejeitado(): void
    {
        $pedido = $this->pedidoPronto();
        $entregador = $this->entregador();

        $this->actingAs($entregador)
            ->get(route('entregador.scan', $pedido)) // sem assinatura
            ->assertForbidden();
    }

    public function test_scan_exige_role_entregador(): void
    {
        $pedido = $this->pedidoPronto();
        $semRole = User::factory()->create(['name_first' => 'SemRole']);

        $this->actingAs($semRole)
            ->get($pedido->linkScanEntrega())
            ->assertForbidden();
    }

    public function test_scan_sem_login_redireciona_para_o_login(): void
    {
        $pedido = $this->pedidoPronto();

        $this->get($pedido->linkScanEntrega())
            ->assertRedirect(route('login'));
    }
}
