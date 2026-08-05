<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSefazClient;
use Tests\TestCase;

class SefazImportarNovasNotasCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35260114200166000166550010000000461123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function xmlExemplo(): string
    {
        return file_get_contents(base_path('tests/Fixtures/nfe-compra-exemplo.xml'));
    }

    public function test_nao_falha_sem_empresa_configurada(): void
    {
        $this->artisan('sefaz:importar-novas-notas')
            ->assertExitCode(0);
    }

    public function test_nao_falha_com_auto_importacao_desativada(): void
    {
        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_sefaz_auto_importacao_ativa' => false,
        ]);

        $this->artisan('sefaz:importar-novas-notas')
            ->assertExitCode(0);
    }

    public function test_falha_com_amabilidade_sem_certificado_configurado(): void
    {
        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_sefaz_auto_importacao_ativa' => true,
        ]);

        $this->artisan('sefaz:importar-novas-notas')
            ->assertExitCode(1);
    }

    public function test_importa_notas_novas_com_certificado_configurado(): void
    {
        $empresa = Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_sefaz_auto_importacao_ativa' => true,
            'empresa_certificado_path' => 'certificados/fake.pfx',
            'empresa_certificado_senha' => 'qualquer',
            'empresa_certificado_validade' => now()->addYear(),
        ]);

        $fake = new FakeSefazClient;
        $fake->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());
        $fake->comLote(new SefazLoteDistribuicao(
            itens: [new SefazResumoDocumento(nsu: 1, chaveAcesso: self::CHAVE, isEvento: false)],
            ultNsuRetornado: 1,
            maxNsu: 1,
            cStat: '138',
        ));
        $this->app->bind(SefazClient::class, fn () => $fake);

        $this->artisan('sefaz:importar-novas-notas')
            ->assertExitCode(0);

        $this->assertDatabaseHas('compras', ['compra_chave_nfe' => self::CHAVE]);
    }
}
