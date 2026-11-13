<?php

namespace Tests\Feature;

use App\Enums\BaseVencimentoEnum;
use App\Enums\Compras\RegraBaixaCompra;
use App\Enums\Compras\StatusPendencia;
use App\Enums\Compras\TipoPendenciaCompra;
use App\Enums\CompraStatusEnum;
use App\Enums\FormaPagamento;
use App\Enums\RegraDataEntradaEnum;
use App\Enums\StatusLancamento;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\FornecedorCondicaoCompra;
use App\Models\FornecedorProduto;
use App\Models\Lancamento;
use App\Models\Prestador;
use App\Models\Produto;
use App\Services\Compras\CompraImportacaoService;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use App\Services\Sefaz\SefazDistribuicaoService;
use App\Services\Sefaz\SefazRespostaDecoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSefazClient;
use Tests\TestCase;

class ComprasAutomatizadasCancelamentoEComandoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE_PIX = '52261122333444000155550010000002011000002010';

    private const CHAVE_BOLETO = '52261133444555000166550010000003021000003020';

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste LTDA', 'empresa_cnpj' => '11222333000181', 'empresa_sefaz_ultimo_nsu' => 0]);
        config(['sefaz.intervalo_pos_manifestacao' => [0, 0, 0]]);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Insumos'])->id;
    }

    public function test_criterio_de_aceite_tres_notas_pelo_comando_e_reimportacao_sem_duplicar(): void
    {
        $stone = ContaFinanceira::factory()->create(['nome' => 'Stone']);
        $laticinios = $this->fornecedor('22333444000155', [
            'fcc_forma_pagamento' => FormaPagamento::Pix,
            'fcc_conta_financeira_id' => $stone->id,
            'fcc_regra_baixa' => RegraBaixaCompra::AutomaticaNaEmissao,
        ]);
        $this->dePara($laticinios, 'MUS-4KG', 4);
        $this->dePara($laticinios, 'ORE-1KG', 1);
        $moinho = $this->fornecedor('33444555000166', ['fcc_forma_pagamento' => FormaPagamento::Boleto, 'fcc_regra_baixa' => RegraBaixaCompra::Manual]);
        $this->dePara($moinho, 'FAR-25', 25);

        foreach (['pix-a-vista', 'boleto-3-duplicatas', 'item-desconhecido'] as $fixture) {
            $this->artisan('compras:importar-xml', ['arquivo' => $this->caminho($fixture)])->assertSuccessful();
        }

        $pix = Compra::where('compra_chave_nfe', self::CHAVE_PIX)->sole();
        $this->assertSame([StatusLancamento::Pago], $pix->lancamentos->pluck('status')->all());

        $boleto = Compra::where('compra_chave_nfe', self::CHAVE_BOLETO)->sole();
        $this->assertSame(3, $boleto->lancamentos()->where('status', StatusLancamento::Pendente)->count());

        $desconhecido = Compra::where('compra_prestador_id', '!=', $laticinios->id)->where('compra_prestador_id', '!=', $moinho->id)->sole();
        $this->assertTrue($desconhecido->pendencias()->doTipo(TipoPendenciaCompra::ProdutoNaoIdentificado)->exists());

        $contagem = [Compra::count(), Lancamento::count()];
        foreach (['pix-a-vista', 'boleto-3-duplicatas', 'item-desconhecido'] as $fixture) {
            $this->artisan('compras:importar-xml', ['arquivo' => $this->caminho($fixture)])->assertSuccessful();
        }
        $this->assertSame($contagem, [Compra::count(), Lancamento::count()]);
    }

    public function test_comando_recusa_arquivo_inexistente(): void
    {
        $this->artisan('compras:importar-xml', ['arquivo' => '/nao/existe.xml'])->assertFailed();
    }

    public function test_cancelamento_de_nota_nao_conferida_cancela_compra_titulos_e_pendencias(): void
    {
        $compra = app(CompraImportacaoService::class)->importar(file_get_contents($this->caminho('item-desconhecido')));
        $this->assertTrue($compra->pendencias()->emAberto()->exists());

        $this->pollingCom(new SefazResumoDocumento(nsu: 20, chaveAcesso: $compra->compra_chave_nfe, isEvento: true, tipoEvento: '110111'));

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::CANCELADA, $compra->compra_status);
        $this->assertTrue($compra->lancamentos->every(fn (Lancamento $t) => $t->status === StatusLancamento::Cancelado));
        $this->assertFalse($compra->pendencias()->emAberto()->exists());
    }

    public function test_cancelamento_de_nota_paga_ou_conferida_vira_pendencia_sem_estorno(): void
    {
        $stone = ContaFinanceira::factory()->create();
        $laticinios = $this->fornecedor('22333444000155', [
            'fcc_conta_financeira_id' => $stone->id,
            'fcc_forma_pagamento' => FormaPagamento::Pix,
            'fcc_regra_baixa' => RegraBaixaCompra::AutomaticaNaEmissao,
        ]);
        $this->dePara($laticinios, 'MUS-4KG', 4);
        $this->dePara($laticinios, 'ORE-1KG', 1);
        $compra = app(CompraImportacaoService::class)->importar(file_get_contents($this->caminho('pix-a-vista')));

        $this->pollingCom(new SefazResumoDocumento(nsu: 21, chaveAcesso: self::CHAVE_PIX, isEvento: true, tipoEvento: '110111'));

        $compra->refresh();
        $this->assertSame(CompraStatusEnum::RASCUNHO, $compra->compra_status);
        $this->assertSame(StatusLancamento::Pago, $compra->lancamentos()->sole()->status);
        $pendencia = $compra->pendencias()->doTipo(TipoPendenciaCompra::NfeCancelada)->sole();
        $this->assertSame(StatusPendencia::Aberta, $pendencia->status);
    }

    public function test_outros_eventos_continuam_ignorados(): void
    {
        $compra = app(CompraImportacaoService::class)->importar(file_get_contents($this->caminho('item-desconhecido')));

        $this->pollingCom(new SefazResumoDocumento(nsu: 22, chaveAcesso: $compra->compra_chave_nfe, isEvento: true, tipoEvento: '210210'));

        $this->assertSame(CompraStatusEnum::RASCUNHO, $compra->fresh()->compra_status);
    }

    public function test_polling_importa_pela_automacao(): void
    {
        $fake = new FakeSefazClient;
        $fake->comDocumentoCompleto(self::CHAVE_BOLETO, file_get_contents($this->caminho('boleto-3-duplicatas')))
            ->comLote(new SefazLoteDistribuicao(
                itens: [new SefazResumoDocumento(nsu: 30, chaveAcesso: self::CHAVE_BOLETO, isEvento: false)],
                ultNsuRetornado: 30,
                maxNsu: 30,
                cStat: '138',
            ));
        $this->app->bind(SefazClient::class, fn () => $fake);

        $resultado = app(SefazDistribuicaoService::class)->executarPolling();

        $this->assertSame(1, $resultado->importadas);
        $compra = Compra::where('compra_chave_nfe', self::CHAVE_BOLETO)->sole();
        $this->assertTrue($compra->compra_importacao_automatica);
        $this->assertSame(3, $compra->lancamentos()->count());
        $this->assertNotNull($compra->compra_user_id);
    }

    public function test_decoder_le_o_tipo_do_evento(): void
    {
        $resEvento = '<resEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01"><cOrgao>52</cOrgao><CNPJ>22333444000155</CNPJ>'
            .'<chNFe>'.self::CHAVE_PIX.'</chNFe><dhEvento>2026-11-11T10:00:00-03:00</dhEvento><tpEvento>110111</tpEvento><nSeqEvento>1</nSeqEvento></resEvento>';
        $resposta = '<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01"><tpAmb>1</tpAmb><cStat>138</cStat><xMotivo>Documento localizado</xMotivo>'
            .'<ultNSU>000000000000040</ultNSU><maxNSU>000000000000040</maxNSU><loteDistDFeInt>'
            .'<docZip NSU="000000000000040" schema="resEvento_v1.01.xsd">'.base64_encode(gzencode($resEvento)).'</docZip>'
            .'</loteDistDFeInt></retDistDFeInt>';

        $item = app(SefazRespostaDecoder::class)->decodeLote($resposta)->itens[0];

        $this->assertTrue($item->ehCancelamento());
    }

    private function pollingCom(SefazResumoDocumento $evento): void
    {
        $fake = (new FakeSefazClient)->comLote(new SefazLoteDistribuicao(itens: [$evento], ultNsuRetornado: $evento->nsu, maxNsu: $evento->nsu, cStat: '138'));
        $this->app->bind(SefazClient::class, fn () => $fake);

        app(SefazDistribuicaoService::class)->executarPolling();
    }

    private function caminho(string $fixture): string
    {
        return base_path("tests/Fixtures/nfe-entrada/automacao/{$fixture}.xml");
    }

    private function fornecedor(string $cnpj, array $condicao): Prestador
    {
        $fornecedor = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => "Fornecedor {$cnpj}", 'cpf_cnpj' => $cnpj]);
        FornecedorCondicaoCompra::create([
            'fcc_prestador_id' => $fornecedor->id,
            'fcc_prazo_base' => BaseVencimentoEnum::Entrada,
            'fcc_data_entrada_regra' => RegraDataEntradaEnum::Hoje,
            ...$condicao,
        ]);

        return $fornecedor;
    }

    private function dePara(Prestador $fornecedor, string $codigo, float $fator): void
    {
        $produto = Produto::create([
            'produto_descricao' => "Insumo {$codigo}",
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
        ]);

        FornecedorProduto::create([
            'fp_prestador_id' => $fornecedor->id,
            'fp_produto_id' => $produto->id,
            'fp_codigo_fornecedor' => $codigo,
            'fp_fator_conversao' => $fator,
        ]);
    }
}
