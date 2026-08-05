<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RotasProtegidasTest extends TestCase
{
    use RefreshDatabase;

    public static function rotasDePdf(): array
    {
        // pedido.imprimir fica de fora: PedidoPDFQrCodeTest confirma que essa
        // rota precisa continuar pública (impressão do ticket sem sessão).
        return [
            ['sessaoMesa.imprimir', ['id' => 1]],
            ['sessaoCaixa.imprimir', ['id' => 1]],
            ['pedidosEntreguesFinalizadosCanceladosPDF.imprimir', ['datahora_abertura' => 1]],
            ['pedidosEntregasPDF.imprimir', ['datahora_abertura' => 1]],
        ];
    }

    #[DataProvider('rotasDePdf')]
    public function test_rota_de_pdf_exige_login(string $rota, array $params): void
    {
        $response = $this->get(route($rota, $params));

        $response->assertRedirect(route('login'));
    }

    public function test_sessao_caixa_exige_role_operacional(): void
    {
        $atendente = User::factory()->create(['name_first' => 'Atendente']);
        $atendente->assignRole('Atendente');

        $this->actingAs($atendente)->get(route('sessao_caixa'))->assertForbidden();
    }

    public function test_role_caixa_acessa_sessao_caixa(): void
    {
        $caixa = User::factory()->create(['name_first' => 'Caixa']);
        $caixa->assignRole('Caixa');

        $this->actingAs($caixa)->get(route('sessao_caixa'))->assertOk();
    }

    public function test_aceitar_pedido_exige_permission_de_negocio(): void
    {
        $cliente = User::factory()->create(['name_first' => 'Cliente']);
        $cliente->assignRole('Cliente');

        $this->actingAs($cliente)->post(route('aceitar_pedido'), ['id' => 1])->assertForbidden();
    }
}
