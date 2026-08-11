<?php

namespace Tests\Feature;

use App\Enums\SefazNotaRecebidaStatusEnum;
use App\Filament\Resources\SefazNotasRecebidas\Pages\ManageSefazNotasRecebidas;
use App\Filament\Resources\SefazNotasRecebidas\Tables\SefazNotasRecebidasTable;
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
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Support\FakeSefazClient;
use Tests\TestCase;

class SefazNotasRecebidasFilamentSmokeTest extends TestCase
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

    /** Fixture completa (com <dest>, <transp>, <pag>) — a de xmlExemplo() não tem o suficiente pra montar um DANFE. */
    private function xmlDanfeExemplo(): string
    {
        return file_get_contents(base_path('tests/Fixtures/nfe-danfe-exemplo.xml'));
    }

    private function fake(): FakeSefazClient
    {
        $fake = new FakeSefazClient;
        $this->app->bind(SefazClient::class, fn () => $fake);

        return $fake;
    }

    private function notaPendente(): SefazNotaRecebida
    {
        return SefazNotaRecebida::create([
            'snr_chave_acesso' => self::CHAVE,
            'snr_nsu' => 5,
            'snr_status' => SefazNotaRecebidaStatusEnum::PENDENTE,
            'snr_encontrada_em' => now(),
        ]);
    }

    public function test_listagem_monta(): void
    {
        $this->notaPendente();

        Livewire::test(ManageSefazNotasRecebidas::class)->assertOk();
    }

    public function test_acao_sincronizar_busca_novidades(): void
    {
        $this->fake()->comLote(new SefazLoteDistribuicao(
            itens: [new SefazResumoDocumento(nsu: 5, chaveAcesso: self::CHAVE, isEvento: false)],
            ultNsuRetornado: 5,
            maxNsu: 5,
            cStat: '138',
        ));

        Livewire::test(ManageSefazNotasRecebidas::class)
            ->callAction('sincronizar')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sefaz_notas_recebidas', ['snr_chave_acesso' => self::CHAVE]);
    }

    private function gerarHtmlNota(SefazNotaRecebida $nota): string
    {
        $nfe = app(SefazDistribuicaoService::class)->visualizarNota($nota);

        $reflexao = new ReflectionMethod(SefazNotasRecebidasTable::class, 'gerarHtmlNota');
        $reflexao->setAccessible(true);

        return $reflexao->invoke(null, $nfe);
    }

    public function test_verdetalhes_monta_o_modal_sem_erro_e_registra_o_cache_do_xml(): void
    {
        $nota = $this->notaPendente();
        $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());

        Livewire::test(ManageSefazNotasRecebidas::class)
            ->mountTableAction('verDetalhes', $nota)
            ->assertOk();

        $this->assertNotNull($nota->fresh()->snr_xml_path);
    }

    public function test_html_da_nota_mostra_emitente_e_itens(): void
    {
        $nota = $this->notaPendente();
        $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());

        $html = $this->gerarHtmlNota($nota);

        $this->assertStringContainsString('Distribuidora Exemplo LTDA', $html);
        $this->assertStringContainsString('AÇÚCAR REFINADO 1KG', $html);
    }

    public function test_html_da_nota_escapa_conteudo_malicioso_do_fornecedor(): void
    {
        $nota = $this->notaPendente();
        // Dentro do XML, "<" precisa vir como entidade (&lt;) pra não quebrar o
        // parsing — é assim que o texto literal "<script>...</script>" chegaria
        // como valor de <xProd> depois do XML parseado.
        $xmlMalicioso = str_replace(
            'AÇÚCAR REFINADO 1KG',
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $this->xmlExemplo(),
        );
        $this->fake()->comDocumentoCompleto(self::CHAVE, $xmlMalicioso);

        $html = $this->gerarHtmlNota($nota);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_acao_imprimir_danfe_busca_na_sefaz_para_nota_pendente(): void
    {
        $nota = $this->notaPendente();
        $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlDanfeExemplo());

        Livewire::test(ManageSefazNotasRecebidas::class)
            ->callTableAction('imprimirDanfe', $nota)
            ->assertFileDownloaded('DANFE-'.self::CHAVE.'.pdf');
    }

    public function test_acao_imprimir_danfe_reaproveita_xml_da_compra_para_nota_importada(): void
    {
        $compra = Compra::create([
            'compra_chave_nfe' => self::CHAVE,
            'compra_status' => 'rascunho',
            'compra_xml_path' => 'compras_xml/teste-danfe.xml',
        ]);
        Storage::disk('local')->put('compras_xml/teste-danfe.xml', $this->xmlDanfeExemplo());

        $nota = SefazNotaRecebida::create([
            'snr_chave_acesso' => self::CHAVE,
            'snr_nsu' => 5,
            'snr_status' => SefazNotaRecebidaStatusEnum::IMPORTADA,
            'snr_compra_id' => $compra->id,
            'snr_encontrada_em' => now(),
        ]);

        // Fake sem nada configurado pra essa chave: se a action tentasse
        // consultar a SEFAZ em vez de usar o XML da Compra, quebraria aqui.
        $this->fake();

        Livewire::test(ManageSefazNotasRecebidas::class)
            // O filtro padrão da tabela só mostra "pendente" (->default() no
            // SelectFilter) — resetTableFilters() volta pro default, não
            // limpa; precisa setar null explicitamente pra ver a "importada".
            ->filterTable('snr_status', null)
            ->callTableAction('imprimirDanfe', $nota)
            ->assertFileDownloaded('DANFE-'.self::CHAVE.'.pdf');
    }

    public function test_acao_importar_importa_e_redireciona(): void
    {
        $nota = $this->notaPendente();
        $this->fake()->comDocumentoCompleto(self::CHAVE, $this->xmlExemplo());

        Livewire::test(ManageSefazNotasRecebidas::class)
            ->callTableAction('importar', $nota)
            ->assertHasNoTableActionErrors();

        $this->assertSame(SefazNotaRecebidaStatusEnum::IMPORTADA, $nota->fresh()->snr_status);
        $this->assertDatabaseHas('compras', ['compra_chave_nfe' => self::CHAVE]);
    }

    public function test_acao_ignorar_marca_ignorada(): void
    {
        $nota = $this->notaPendente();
        $this->fake();

        Livewire::test(ManageSefazNotasRecebidas::class)
            ->callTableAction('ignorar', $nota)
            ->assertHasNoTableActionErrors();

        $this->assertSame(SefazNotaRecebidaStatusEnum::IGNORADA, $nota->fresh()->snr_status);
    }

    public function test_bulk_action_importa_selecionadas(): void
    {
        $nota1 = $this->notaPendente();
        $nota2 = SefazNotaRecebida::create([
            'snr_chave_acesso' => '35260114200166000166550010000000471123456780',
            'snr_nsu' => 6,
            'snr_status' => SefazNotaRecebidaStatusEnum::PENDENTE,
            'snr_encontrada_em' => now(),
        ]);

        $fake = $this->fake();
        $fake->comDocumentoCompleto($nota1->snr_chave_acesso, $this->xmlExemplo());
        // nota2 fica sem XML configurado no fake -> importarNota lança exceção,
        // e o bulk action deve seguir pra próxima em vez de travar tudo.

        Livewire::test(ManageSefazNotasRecebidas::class)
            ->callTableBulkAction('importarSelecionadas', [$nota1, $nota2])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(SefazNotaRecebidaStatusEnum::IMPORTADA, $nota1->fresh()->snr_status);
        $this->assertSame(SefazNotaRecebidaStatusEnum::PENDENTE, $nota2->fresh()->snr_status);
    }
}
