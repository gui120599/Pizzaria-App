<?php

namespace Tests\Feature;

use App\Enums\SefazNotaRecebidaStatusEnum;
use App\Models\Compra;
use App\Models\Empresa;
use App\Models\SefazNotaRecebida;
use App\Models\User;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use App\Services\Sefaz\SefazDistribuicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSefazClient;
use Tests\TestCase;

class SefazSincronizacaoServiceTest extends TestCase
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
            'empresa_sefaz_ultimo_nsu_revisao' => 0,
        ]);
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

    public function test_sincronizar_registra_nota_pendente_sem_manifestar_nem_importar(): void
    {
        $fake = $this->fake()->comLote(new SefazLoteDistribuicao(
            itens: [
                new SefazResumoDocumento(
                    nsu: 5,
                    chaveAcesso: self::CHAVE,
                    isEvento: false,
                    cnpjEmitente: '14200166000166',
                    nomeEmitente: 'Distribuidora Exemplo LTDA',
                    valor: 95.0,
                ),
            ],
            ultNsuRetornado: 5,
            maxNsu: 5,
            cStat: '138',
        ));

        $resultado = app(SefazDistribuicaoService::class)->sincronizarNotasRecebidas();

        $this->assertSame(1, $resultado->novas);
        $this->assertSame([], $fake->chavesManifestadas);
        $this->assertDatabaseCount('compras', 0);

        $nota = SefazNotaRecebida::where('snr_chave_acesso', self::CHAVE)->firstOrFail();
        $this->assertSame(SefazNotaRecebidaStatusEnum::PENDENTE, $nota->snr_status);
        $this->assertSame('Distribuidora Exemplo LTDA', $nota->snr_nome_emitente);
    }

    public function test_sincronizar_marca_importada_quando_compra_ja_existe(): void
    {
        $compra = Compra::create(['compra_chave_nfe' => self::CHAVE, 'compra_status' => 'rascunho']);

        $this->fake()->comLote(new SefazLoteDistribuicao(
            itens: [new SefazResumoDocumento(nsu: 5, chaveAcesso: self::CHAVE, isEvento: false)],
            ultNsuRetornado: 5,
            maxNsu: 5,
            cStat: '138',
        ));

        app(SefazDistribuicaoService::class)->sincronizarNotasRecebidas();

        $nota = SefazNotaRecebida::where('snr_chave_acesso', self::CHAVE)->firstOrFail();
        $this->assertSame(SefazNotaRecebidaStatusEnum::IMPORTADA, $nota->snr_status);
        $this->assertSame($compra->id, $nota->snr_compra_id);
    }

    public function test_sincronizar_pula_eventos(): void
    {
        $this->fake()->comLote(new SefazLoteDistribuicao(
            itens: [new SefazResumoDocumento(nsu: 5, chaveAcesso: self::CHAVE, isEvento: true)],
            ultNsuRetornado: 5,
            maxNsu: 5,
            cStat: '138',
        ));

        $resultado = app(SefazDistribuicaoService::class)->sincronizarNotasRecebidas();

        $this->assertSame(1, $resultado->puladas);
        $this->assertDatabaseCount('sefaz_notas_recebidas', 0);
    }

    public function test_sincronizar_usa_cursor_proprio_sem_tocar_no_cursor_do_polling(): void
    {
        $this->fake()->comLote(new SefazLoteDistribuicao(
            itens: [new SefazResumoDocumento(nsu: 5, chaveAcesso: self::CHAVE, isEvento: false)],
            ultNsuRetornado: 7,
            maxNsu: 7,
            cStat: '138',
        ));

        app(SefazDistribuicaoService::class)->sincronizarNotasRecebidas();

        $empresa = Empresa::first();
        $this->assertSame(7, $empresa->empresa_sefaz_ultimo_nsu_revisao);
        $this->assertSame(0, $empresa->empresa_sefaz_ultimo_nsu);
    }

    public function test_importar_nota_transiciona_para_importada_e_linka_compra(): void
    {
        $nota = SefazNotaRecebida::create([
            'snr_chave_acesso' => self::CHAVE,
            'snr_nsu' => 5,
            'snr_status' => SefazNotaRecebidaStatusEnum::PENDENTE,
            'snr_encontrada_em' => now(),
        ]);

        $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());

        $compra = app(SefazDistribuicaoService::class)->importarNota($nota);

        $nota->refresh();
        $this->assertSame(SefazNotaRecebidaStatusEnum::IMPORTADA, $nota->snr_status);
        $this->assertSame($compra->id, $nota->snr_compra_id);
    }

    public function test_obter_xml_da_nota_reaproveita_xml_da_compra_quando_ja_importada(): void
    {
        $compra = Compra::create([
            'compra_chave_nfe' => self::CHAVE,
            'compra_status' => 'rascunho',
            'compra_xml_path' => 'compras_xml/teste.xml',
        ]);
        Storage::disk('local')->put('compras_xml/teste.xml', $this->xmlExemplo());

        $nota = SefazNotaRecebida::create([
            'snr_chave_acesso' => self::CHAVE,
            'snr_nsu' => 5,
            'snr_status' => SefazNotaRecebidaStatusEnum::IMPORTADA,
            'snr_compra_id' => $compra->id,
            'snr_encontrada_em' => now(),
        ]);

        // Fake sem nada configurado pra essa chave: se obterXmlDaNota tentasse
        // consultar a SEFAZ em vez de usar o XML da Compra, isso quebraria aqui.
        $this->fake();

        $xml = app(SefazDistribuicaoService::class)->obterXmlDaNota($nota);

        $this->assertSame($this->xmlExemplo(), $xml);
    }

    public function test_visualizar_nota_parseia_emitente_e_itens_sem_criar_compra(): void
    {
        $nota = SefazNotaRecebida::create([
            'snr_chave_acesso' => self::CHAVE,
            'snr_nsu' => 5,
            'snr_status' => SefazNotaRecebidaStatusEnum::PENDENTE,
            'snr_encontrada_em' => now(),
        ]);

        $fake = $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());

        $nfe = app(SefazDistribuicaoService::class)->visualizarNota($nota);

        $this->assertSame('Distribuidora Exemplo LTDA', $nfe->emitente->razaoSocial);
        $this->assertCount(2, $nfe->itens);
        $this->assertSame('AÇÚCAR REFINADO 1KG', $nfe->itens[0]->descricao);
        $this->assertSame([], $fake->chavesManifestadas);
        $this->assertDatabaseCount('compras', 0);
        $this->assertNotNull($nota->fresh()->snr_xml_path);
    }

    public function test_visualizar_nota_reaproveita_xml_em_cache_sem_consultar_a_sefaz_de_novo(): void
    {
        $nota = SefazNotaRecebida::create([
            'snr_chave_acesso' => self::CHAVE,
            'snr_nsu' => 5,
            'snr_status' => SefazNotaRecebidaStatusEnum::PENDENTE,
            'snr_encontrada_em' => now(),
        ]);

        $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());

        $service = app(SefazDistribuicaoService::class);
        $service->visualizarNota($nota);

        // Troca o fake por um que derruba qualquer chamada — se visualizarNota()
        // tentasse consultar a SEFAZ de novo, o teste falharia aqui.
        $this->app->bind(SefazClient::class, fn () => (new FakeSefazClient)->lancarIndisponivel());

        $nfe = $service->visualizarNota($nota->fresh());

        $this->assertCount(2, $nfe->itens);
    }

    public function test_importar_nota_reaproveita_xml_ja_visualizado(): void
    {
        $nota = SefazNotaRecebida::create([
            'snr_chave_acesso' => self::CHAVE,
            'snr_nsu' => 5,
            'snr_status' => SefazNotaRecebidaStatusEnum::PENDENTE,
            'snr_encontrada_em' => now(),
        ]);

        $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());

        $service = app(SefazDistribuicaoService::class);
        $service->visualizarNota($nota);

        $this->app->bind(SefazClient::class, fn () => (new FakeSefazClient)->lancarIndisponivel());

        $compra = $service->importarNota($nota->fresh());

        $this->assertSame(self::CHAVE, $compra->compra_chave_nfe);
    }

    public function test_ignorar_nota_muda_status_sem_chamar_a_sefaz(): void
    {
        $nota = SefazNotaRecebida::create([
            'snr_chave_acesso' => self::CHAVE,
            'snr_nsu' => 5,
            'snr_status' => SefazNotaRecebidaStatusEnum::PENDENTE,
            'snr_encontrada_em' => now(),
        ]);

        $fake = $this->fake();

        app(SefazDistribuicaoService::class)->ignorarNota($nota);

        $nota->refresh();
        $this->assertSame(SefazNotaRecebidaStatusEnum::IGNORADA, $nota->snr_status);
        $this->assertSame([], $fake->chavesManifestadas);
    }
}
