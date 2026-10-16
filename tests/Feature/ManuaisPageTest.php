<?php

namespace Tests\Feature;

use App\Filament\Pages\Manuais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManuaisPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_qualquer_usuario_do_painel_ve_a_lista_de_manuais(): void
    {
        $this->actingAs(User::factory()->caixa()->create(['name_first' => 'Caixa']));

        $this->get(Manuais::getUrl())
            ->assertOk()
            ->assertSee('Integração Connect Stone')
            ->assertSee(route('filament.admin.manuais.arquivo', ['arquivo' => 'Instrucoes-Integracao-Stone.pdf']), escape: false);
    }

    public function test_abre_o_pdf_no_navegador_ou_baixa(): void
    {
        $this->actingAs(User::factory()->caixa()->create(['name_first' => 'Caixa']));
        $url = route('filament.admin.manuais.arquivo', ['arquivo' => 'Instrucoes-Integracao-Stone.pdf']);

        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="Instrucoes-Integracao-Stone.pdf"');

        $this->get($url.'?baixar=1')
            ->assertOk()
            ->assertDownload('Instrucoes-Integracao-Stone.pdf');
    }

    public function test_nao_serve_arquivo_fora_do_catalogo(): void
    {
        $this->actingAs(User::factory()->caixa()->create(['name_first' => 'Caixa']));

        $this->get(route('filament.admin.manuais.arquivo', ['arquivo' => 'modulo-cozinha.md']))->assertNotFound();
        $this->get('/admin/manuais/..%2F.env')->assertNotFound();
    }

    public function test_visitante_nao_acessa_o_pdf(): void
    {
        $this->get(route('filament.admin.manuais.arquivo', ['arquivo' => 'Instrucoes-Integracao-Stone.pdf']))
            ->assertRedirect();
    }
}
