<?php

namespace Tests\Feature;

use App\Exceptions\SefazDocumentoAindaNaoDisponivelException;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\SefazDocumentoPendente;
use App\Models\User;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use App\Services\Sefaz\SefazDistribuicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSefazClient;
use Tests\TestCase;

class SefazDistribuicaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35260114200166000166550010000000461123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_sefaz_ultimo_nsu' => 0,
        ]);

        config(['sefaz.intervalo_pos_manifestacao' => [0, 0, 0]]);
    }

    private function xmlExemplo(): string
    {
        return file_get_contents(base_path('tests/Fixtures/nfe-compra-exemplo.xml'));
    }

    private function fake(): FakeSefazClient
    {
        $fake = new FakeSefazClient;
        $this->app->bind(SefazClient::class, fn () => $fake);

        return $fake;
    }

    public function test_buscar_por_chave_com_documento_ja_completo(): void
    {
        $fake = $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());

        $compra = app(SefazDistribuicaoService::class)->buscarPorChave(self::CHAVE);

        $this->assertSame(self::CHAVE, $compra->compra_chave_nfe);
        $this->assertSame([], $fake->chavesManifestadas);
    }

    public function test_buscar_por_chave_manifesta_e_espera_liberar(): void
    {
        $fake = $this->fake()
            ->comResumoPendente(self::CHAVE)
            ->comDocumentoAposManifestacao(self::CHAVE, $this->xmlExemplo());

        $compra = app(SefazDistribuicaoService::class)->buscarPorChave(self::CHAVE);

        $this->assertSame(self::CHAVE, $compra->compra_chave_nfe);
        $this->assertSame([self::CHAVE], $fake->chavesManifestadas);
    }

    public function test_buscar_por_chave_lanca_excecao_se_nunca_liberar(): void
    {
        $this->fake()->comResumoPendente(self::CHAVE);

        $this->expectException(SefazDocumentoAindaNaoDisponivelException::class);
        app(SefazDistribuicaoService::class)->buscarPorChave(self::CHAVE);
    }

    public function test_buscar_por_chave_manifesta_quando_cstat_137_e_espera_liberar(): void
    {
        $fake = $this->fake()->comDocumentoAposManifestacao(self::CHAVE, $this->xmlExemplo());

        $compra = app(SefazDistribuicaoService::class)->buscarPorChave(self::CHAVE);

        $this->assertSame(self::CHAVE, $compra->compra_chave_nfe);
        $this->assertSame([self::CHAVE], $fake->chavesManifestadas);
    }

    public function test_buscar_por_chave_com_cstat_137_permanente_lanca_excecao_apos_manifestar(): void
    {
        $fake = $this->fake()->comNaoLocalizado(self::CHAVE);

        $this->expectException(SefazDocumentoAindaNaoDisponivelException::class);

        try {
            app(SefazDistribuicaoService::class)->buscarPorChave(self::CHAVE);
        } finally {
            $this->assertSame([self::CHAVE], $fake->chavesManifestadas);
        }
    }

    public function test_polling_importa_novo_e_pula_ja_existente_e_evento(): void
    {
        $chaveJaImportada = '35260114200166000166550010000000471123456780';
        Compra::create(['compra_chave_nfe' => $chaveJaImportada, 'compra_status' => 'rascunho']);

        $fake = $this->fake()
            ->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo())
            ->comLote(new SefazLoteDistribuicao(
                itens: [
                    new SefazResumoDocumento(nsu: 10, chaveAcesso: self::CHAVE, isEvento: false),
                    new SefazResumoDocumento(nsu: 11, chaveAcesso: $chaveJaImportada, isEvento: false),
                    new SefazResumoDocumento(nsu: 12, chaveAcesso: 'qualquerchavedeevento00000000000000000000', isEvento: true),
                ],
                ultNsuRetornado: 12,
                maxNsu: 12,
                cStat: '138',
            ));

        $resultado = app(SefazDistribuicaoService::class)->executarPolling();

        $this->assertSame(1, $resultado->importadas);
        $this->assertSame(2, $resultado->puladas);
        $this->assertSame([self::CHAVE], $fake->chavesManifestadas);

        $empresa = Empresa::first();
        $this->assertSame(12, $empresa->empresa_sefaz_ultimo_nsu);
    }

    public function test_polling_registra_pendente_quando_nao_libera_na_hora(): void
    {
        $this->fake()
            ->comResumoPendente(self::CHAVE)
            ->comLote(new SefazLoteDistribuicao(
                itens: [new SefazResumoDocumento(nsu: 5, chaveAcesso: self::CHAVE, isEvento: false)],
                ultNsuRetornado: 5,
                maxNsu: 5,
                cStat: '138',
            ));

        $resultado = app(SefazDistribuicaoService::class)->executarPolling();

        $this->assertSame(0, $resultado->importadas);
        $this->assertSame(1, $resultado->pendentes);
        $this->assertDatabaseHas('sefaz_documentos_pendentes', ['sdp_chave_acesso' => self::CHAVE]);
    }

    public function test_polling_reprocessa_pendente_e_remove_da_fila_ao_conseguir(): void
    {
        SefazDocumentoPendente::create([
            'sdp_chave_acesso' => self::CHAVE,
            'sdp_manifestado_em' => now(),
        ]);

        $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());

        $resultado = app(SefazDistribuicaoService::class)->executarPolling();

        $this->assertSame(1, $resultado->importadas);
        $this->assertDatabaseMissing('sefaz_documentos_pendentes', ['sdp_chave_acesso' => self::CHAVE]);
        $this->assertDatabaseHas('compras', ['compra_chave_nfe' => self::CHAVE]);
    }

    public function test_polling_erro_pontual_nao_interrompe_o_lote(): void
    {
        $chaveComErro = '35260114200166000166550010000000481123456781';

        $fake = new FakeSefazClient;
        // A chave com erro não está registrada no fake -> consultarPorChave lança SefazIndisponivelException.
        $fake->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());
        $fake->comLote(new SefazLoteDistribuicao(
            itens: [
                new SefazResumoDocumento(nsu: 1, chaveAcesso: $chaveComErro, isEvento: false),
                new SefazResumoDocumento(nsu: 2, chaveAcesso: self::CHAVE, isEvento: false),
            ],
            ultNsuRetornado: 2,
            maxNsu: 2,
            cStat: '138',
        ));
        $this->app->bind(SefazClient::class, fn () => $fake);

        $resultado = app(SefazDistribuicaoService::class)->executarPolling();

        $this->assertSame(1, $resultado->erros);
        $this->assertSame(1, $resultado->importadas);
        $this->assertSame(2, Empresa::first()->empresa_sefaz_ultimo_nsu);
    }
}
