<?php

namespace Tests\Feature;

use App\Models\Mesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Código impresso no QR da mesa (/mesa/{codigo}): aleatório, único e
 * regerável para invalidar um QR vazado.
 */
class MesaCodigoQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_mesa_nova_recebe_um_codigo_unico_que_nao_e_o_id(): void
    {
        $mesa1 = Mesa::factory()->create();
        $mesa2 = Mesa::factory()->create();

        $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $mesa1->mesa_codigo_qr);
        $this->assertNotSame($mesa1->mesa_codigo_qr, $mesa2->mesa_codigo_qr);
        $this->assertNotSame((string) $mesa1->id, $mesa1->mesa_codigo_qr);
    }

    public function test_regenerar_o_codigo_invalida_o_anterior(): void
    {
        $mesa = Mesa::factory()->create();
        $anterior = $mesa->mesa_codigo_qr;

        $mesa->regenerarCodigoQr();

        $this->assertNotSame($anterior, $mesa->fresh()->mesa_codigo_qr);
        $this->assertFalse(Mesa::where('mesa_codigo_qr', $anterior)->exists());
    }

    public function test_pedido_pelo_celular_vem_ligado_na_mesa_nova(): void
    {
        $this->assertTrue(Mesa::factory()->create()->fresh()->mesa_pedido_cliente_ativo);
    }
}
