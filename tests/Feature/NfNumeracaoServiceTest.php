<?php

namespace Tests\Feature;

use App\Exceptions\NfeIoException;
use App\Models\Empresa;
use App\Models\NfEmissao;
use App\Models\NfNumeracao;
use App\Models\Venda;
use App\Services\Nfe\NfNumeracaoService;
use App\Services\NfeIoService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class NfNumeracaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ = '11222333000181';

    private const URL_EMISSAO = 'api.nfse.io/v2/companies/company-teste/consumerinvoices';

    private function criarEmpresa(string $ambiente = 'production'): Empresa
    {
        return Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11.222.333/0001-81',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
            'empresa_api_nfeio_ambiente' => $ambiente,
        ]);
    }

    private function criarSequencia(int $ultimoNumero, string $ambiente = 'production'): NfNumeracao
    {
        return NfNumeracao::create([
            'emitente_cnpj' => self::CNPJ,
            'ambiente' => $ambiente,
            'modelo' => 65,
            'serie' => 1,
            'ultimo_numero' => $ultimoNumero,
        ]);
    }

    private function criarVenda(): Venda
    {
        return Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => 0,
            'venda_datahora_finalizada' => now(),
        ]);
    }

    /** @return list<int> números de NF enviados à NFe.io, na ordem */
    private function numerosEnviados(): array
    {
        return Http::recorded()
            ->filter(fn ($par) => $par[0]->method() === 'POST')
            ->map(fn ($par) => $par[0]->data()['number'])
            ->values()
            ->all();
    }

    public function test_vendas_sem_nf_intercaladas_nao_abrem_lacuna_na_sequencia(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL_EMISSAO => Http::response(['id' => 'inv-x', 'status' => 'Processing'], 200)]);
        $this->criarEmpresa();
        $this->criarSequencia(100);

        $vendaComNf = $this->criarVenda();
        $this->criarVenda(); // finalizada sem NF
        $outraVendaComNf = $this->criarVenda();

        app(NfeIoService::class)->emitir($vendaComNf);
        app(NfeIoService::class)->emitir($outraVendaComNf);

        $this->assertSame([101, 102], $this->numerosEnviados());
        $this->assertSame(102, NfNumeracao::first()->ultimo_numero);
    }

    public function test_reenvio_apos_rejeicao_reaproveita_o_mesmo_numero(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL_EMISSAO => Http::sequence()
            ->push(['id' => 'inv-1', 'status' => 'Processing'])
            ->push(['id' => 'inv-2', 'status' => 'Processing']),
        ]);
        $this->criarEmpresa();
        $this->criarSequencia(100);
        $venda = $this->criarVenda();

        app(NfeIoService::class)->emitir($venda);
        // Rejeição chega pelo webhook; o operador corrige e limpa o id (rota venda.removerIdNfe).
        NfEmissao::espelharStatus('inv-1', 'Error');
        $venda->update(['venda_id_nfe' => null]);

        app(NfeIoService::class)->emitir($venda->fresh());

        $this->assertSame([101, 101], $this->numerosEnviados());
        $this->assertSame(1, NfEmissao::count());
        $this->assertSame('inv-2', NfEmissao::first()->nfeio_id);
        $this->assertSame(101, NfNumeracao::first()->ultimo_numero);
    }

    public function test_falha_no_envio_mantem_o_numero_reservado_para_a_proxima_tentativa(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL_EMISSAO => Http::sequence()
            ->push(['message' => 'Serviço indisponível'], 503)
            ->push(['id' => 'inv-1', 'status' => 'Processing']),
        ]);
        $this->criarEmpresa();
        $this->criarSequencia(100);
        $venda = $this->criarVenda();

        try {
            app(NfeIoService::class)->emitir($venda);
            $this->fail('Era esperada NfeIoException na primeira tentativa.');
        } catch (NfeIoException) {
            $this->assertSame(NfEmissao::STATUS_FALHA_ENVIO, NfEmissao::first()->status);
        }

        app(NfeIoService::class)->emitir($venda->fresh());

        $this->assertSame([101, 101], $this->numerosEnviados());
        $this->assertSame('Processing', NfEmissao::first()->status);
    }

    public function test_venda_com_nf_autorizada_nao_reserva_novo_numero(): void
    {
        $this->criarEmpresa();
        $this->criarSequencia(100);
        $venda = $this->criarVenda();
        $service = app(NfNumeracaoService::class);
        $service->reservarPara($venda, NfEmissao::DECISAO_MANUAL)->atualizarStatus('Issued');

        $this->expectException(NfeIoException::class);

        try {
            $service->reservarPara($venda, NfEmissao::DECISAO_MANUAL);
        } finally {
            $this->assertSame(101, NfNumeracao::first()->ultimo_numero);
        }
    }

    public function test_venda_com_nf_cancelada_recebe_numero_novo(): void
    {
        $this->criarEmpresa();
        $this->criarSequencia(100);
        $venda = $this->criarVenda();
        $service = app(NfNumeracaoService::class);
        $service->reservarPara($venda, NfEmissao::DECISAO_MANUAL)->atualizarStatus('Cancelled');

        $nova = $service->reservarPara($venda, NfEmissao::DECISAO_MANUAL);

        $this->assertSame(102, $nova->numero);
    }

    public function test_reserva_grava_numero_serie_e_decisao_e_autorizacao_grava_data(): void
    {
        $this->freezeTime();
        $this->criarEmpresa();
        $this->criarSequencia(100);
        $venda = $this->criarVenda();

        $emissao = app(NfNumeracaoService::class)->reservarPara($venda, NfEmissao::DECISAO_AUTOMATICA);
        $emissao->atualizarStatus('Issued');

        $this->assertDatabaseHas('nf_emissoes', [
            'venda_id' => $venda->id,
            'modelo' => 65,
            'serie' => 1,
            'numero' => 101,
            'decisao_envio' => 'automatica',
            'status' => 'Issued',
            'autorizada_em' => now()->toDateTimeString(),
        ]);
    }

    public function test_sequencia_e_independente_por_ambiente(): void
    {
        $empresa = $this->criarEmpresa('production');
        $this->criarSequencia(500, 'production');
        $empresa->update(['empresa_api_nfeio_ambiente' => 'test']);

        $emissao = app(NfNumeracaoService::class)->reservarPara($this->criarVenda(), NfEmissao::DECISAO_MANUAL);

        $this->assertSame(1, $emissao->numero);
        $this->assertSame(500, NfNumeracao::where('ambiente', 'production')->value('ultimo_numero'));
    }

    public function test_sem_cnpj_do_emitente_nao_reserva_numero(): void
    {
        Empresa::create(['empresa_razao_social' => 'Sem CNPJ']);

        $this->expectException(NfeIoException::class);

        app(NfNumeracaoService::class)->reservarPara($this->criarVenda(), NfEmissao::DECISAO_MANUAL);
    }

    public function test_definir_ultimo_numero_recusa_valor_abaixo_de_numero_ja_reservado(): void
    {
        $this->criarEmpresa();
        $this->criarSequencia(100);
        $service = app(NfNumeracaoService::class);
        $service->reservarPara($this->criarVenda(), NfEmissao::DECISAO_MANUAL);

        $this->expectException(InvalidArgumentException::class);

        try {
            $service->definirUltimoNumero(100);
        } finally {
            $this->assertSame(101, NfNumeracao::first()->ultimo_numero);
        }
    }

    public function test_comando_define_ultimo_numero_e_mostra_o_proximo(): void
    {
        $this->criarEmpresa();

        $this->artisan('nfe:numeracao-definir', ['ultimo_numero' => 31140])
            ->expectsOutputToContain('Próxima NFC-e da série 1: nº 31141')
            ->assertSuccessful();

        $this->assertSame(31140, NfNumeracao::first()->ultimo_numero);
    }

    public function test_pendentes_de_inutilizacao_lista_so_numeros_antigos_nunca_autorizados(): void
    {
        $this->criarEmpresa();
        $this->criarSequencia(100);
        $service = app(NfNumeracaoService::class);

        $this->travel(-2)->days();
        $rejeitada = $service->reservarPara($this->criarVenda(), NfEmissao::DECISAO_MANUAL);
        $rejeitada->atualizarStatus('Error');
        $service->reservarPara($this->criarVenda(), NfEmissao::DECISAO_MANUAL)->atualizarStatus('Issued');
        $this->travelBack();
        $service->reservarPara($this->criarVenda(), NfEmissao::DECISAO_MANUAL); // recente, ainda pode ser reenviada

        $this->assertSame([$rejeitada->id], NfEmissao::pendentesInutilizacao()->pluck('id')->all());
    }

    /**
     * Dois caixas emitindo ao mesmo tempo: enquanto a reserva do caixa A não
     * comita, a do caixa B (outra conexão) fica bloqueada no lock da
     * sequência; depois do commit, B recebe o número seguinte, nunca o mesmo.
     * Usa conexões próprias, fora da transação do RefreshDatabase, para que
     * os commits sejam reais e visíveis entre elas.
     */
    public function test_emissoes_simultaneas_nao_recebem_o_mesmo_numero(): void
    {
        $conexaoPadrao = DB::getDefaultConnection();
        foreach (['nf_caixa_a', 'nf_caixa_b'] as $nome) {
            config(["database.connections.{$nome}" => config("database.connections.{$conexaoPadrao}")]);
        }

        $caixaA = DB::connection('nf_caixa_a');
        $caixaB = DB::connection('nf_caixa_b');
        $empresaId = $caixaA->table('empresas')->insertGetId([
            'empresa_razao_social' => 'Pizzaria Concorrência', 'empresa_cnpj' => self::CNPJ, 'empresa_api_nfeio_ambiente' => 'production',
        ]);
        $numeracaoId = $caixaA->table('nf_numeracoes')->insertGetId([
            'emitente_cnpj' => self::CNPJ, 'ambiente' => 'production', 'modelo' => 65, 'serie' => 1, 'ultimo_numero' => 100,
        ]);
        $vendaA = $caixaA->table('vendas')->insertGetId(['venda_status' => 'FINALIZADA']);
        $vendaB = $caixaA->table('vendas')->insertGetId(['venda_status' => 'FINALIZADA']);

        try {
            $service = app(NfNumeracaoService::class);

            DB::setDefaultConnection('nf_caixa_a');
            $caixaA->beginTransaction();
            $numeroA = $service->reservarPara(Venda::find($vendaA), NfEmissao::DECISAO_MANUAL)->numero;

            DB::setDefaultConnection('nf_caixa_b');
            $caixaB->statement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $service->reservarPara(Venda::find($vendaB), NfEmissao::DECISAO_MANUAL);
                $this->fail('A reserva do caixa B deveria esperar o lock do caixa A.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
            }

            $caixaA->commit();
            $numeroB = $service->reservarPara(Venda::find($vendaB), NfEmissao::DECISAO_MANUAL)->numero;

            $this->assertSame([101, 102], [$numeroA, $numeroB]);
        } finally {
            if ($caixaA->transactionLevel() > 0) {
                $caixaA->rollBack();
            }
            DB::setDefaultConnection($conexaoPadrao);
            $caixaA->table('nf_emissoes')->where('numeracao_id', $numeracaoId)->delete();
            $caixaA->table('nf_numeracoes')->where('id', $numeracaoId)->delete();
            $caixaA->table('vendas')->whereIn('id', [$vendaA, $vendaB])->delete();
            $caixaA->table('empresas')->where('id', $empresaId)->delete();
            DB::purge('nf_caixa_a');
            DB::purge('nf_caixa_b');
        }
    }
}
