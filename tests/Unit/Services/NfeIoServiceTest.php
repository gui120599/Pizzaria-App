<?php

namespace Tests\Unit\Services;

use App\Enums\ProdutoTipoEnum;
use App\Exceptions\NfeIoException;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\ItensVenda;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Produto;
use App\Models\Venda;
use App\Services\NfeIoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NfeIoServiceTest extends TestCase
{
    use RefreshDatabase;

    private Venda $venda;

    protected function setUp(): void
    {
        parent::setUp();

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
        ]);

        $this->venda = Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => 50.00,
            'venda_datahora_finalizada' => now(),
        ]);

        ItensVenda::create([
            'item_numero' => 1,
            'item_venda_venda_id' => $this->venda->id,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario' => 50.00,
            'item_venda_desconto' => 0,
            'item_venda_valor' => 50.00,
            'item_venda_valor_base_calculo' => 50.00,
            'item_venda_status' => 'INSERIDO',
        ]);
    }

    public function test_emitir_com_sucesso_grava_venda_id_nfe(): void
    {
        Http::fake([
            'api.nfse.io/v2/companies/company-teste/consumerinvoices' => Http::response(['id' => 'inv-123'], 200),
        ]);

        $data = app(NfeIoService::class)->emitir($this->venda->fresh());

        $this->assertSame('inv-123', $data['id']);
        $this->assertSame('inv-123', $this->venda->fresh()->venda_id_nfe);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'chave-teste')
                && $request->url() === 'https://api.nfse.io/v2/companies/company-teste/consumerinvoices';
        });
    }

    public function test_emitir_com_erro_lanca_excecao_e_nao_grava_id(): void
    {
        Http::fake([
            'api.nfse.io/v2/companies/company-teste/consumerinvoices' => Http::response(['message' => 'CNPJ inválido'], 400),
        ]);

        $this->expectException(NfeIoException::class);

        try {
            app(NfeIoService::class)->emitir($this->venda->fresh());
        } finally {
            $this->assertNull($this->venda->fresh()->venda_id_nfe);
        }
    }

    public function test_consultar_status_decodifica_resposta(): void
    {
        Http::fake([
            'api.nfse.io/v2/companies/company-teste/consumerinvoices/inv-123' => Http::response(['id' => 'inv-123', 'status' => 'Issued'], 200),
        ]);

        $data = app(NfeIoService::class)->consultarStatus('inv-123');

        $this->assertSame('Issued', $data['status']);
    }

    public function test_sincronizar_status_local_persiste_status_na_venda(): void
    {
        $this->venda->update(['venda_id_nfe' => 'inv-123']);

        Http::fake([
            'api.nfse.io/v2/companies/company-teste/consumerinvoices/inv-123' => Http::response(['id' => 'inv-123', 'status' => 'Issued'], 200),
        ]);

        $status = app(NfeIoService::class)->sincronizarStatusLocal($this->venda);

        $this->assertSame('Issued', $status);
        $this->assertSame('Issued', $this->venda->fresh()->venda_status_nfe);
    }

    public function test_cancelar_nao_lanca_excecao_em_204(): void
    {
        Http::fake([
            'api.nfse.io/v2/companies/company-teste/consumerinvoices/inv-123' => Http::response('', 204),
        ]);

        app(NfeIoService::class)->cancelar('inv-123');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_consultar_pdf_url_extrai_uri(): void
    {
        Http::fake([
            'api.nfse.io/v2/companies/company-teste/consumerinvoices/inv-123/pdf' => Http::response(['uri' => 'https://nfse.io/danfe/inv-123.pdf'], 200),
        ]);

        $uri = app(NfeIoService::class)->consultarPdfUrl('inv-123');

        $this->assertSame('https://nfse.io/danfe/inv-123.pdf', $uri);
    }

    public function test_sem_empresa_configurada_lanca_excecao(): void
    {
        Empresa::query()->delete();

        $this->expectException(NfeIoException::class);

        app(NfeIoService::class)->consultarStatus('inv-123');
    }

    public function test_payload_de_venda_totalmente_fiado_declara_saldo_como_sem_pagamento(): void
    {
        // Venda do setUp: total 50, pago 0 (default), sem PagamentosVenda — 100% fiado.
        $payload = app(NfeIoService::class)->payloadPreview($this->venda->fresh());

        $detalhe = $payload['payment'][0]['paymentDetail'];

        $this->assertCount(1, $detalhe);
        $this->assertSame('withoutPayment', $detalhe[0]['method']);
        $this->assertEqualsWithDelta(50.0, (float) $detalhe[0]['amount'], 0.01);
    }

    public function test_payload_de_venda_parcialmente_fiado_declara_pago_mais_saldo_sem_pagamento(): void
    {
        $opcaoPix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_desc_nfe' => 'InstantPayment']);
        $this->venda->update(['venda_valor_total' => 100.00, 'venda_valor_pago' => 40.00]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id,
            'pg_venda_opcaopagamento_id' => $opcaoPix->id,
            'pg_venda_valor_pagamento' => 40.00,
            'pg_venda_valor_pago_pelo_cliente' => 40.00,
        ]);

        $payload = app(NfeIoService::class)->payloadPreview($this->venda->fresh());
        $detalhe = $payload['payment'][0]['paymentDetail'];

        $this->assertCount(2, $detalhe);
        $this->assertSame('InstantPayment', $detalhe[0]['method']);
        $this->assertEqualsWithDelta(40.0, (float) $detalhe[0]['amount'], 0.01);
        $this->assertSame('withoutPayment', $detalhe[1]['method']);
        $this->assertEqualsWithDelta(60.0, (float) $detalhe[1]['amount'], 0.01);
    }

    public function test_payload_de_venda_totalmente_paga_nao_declara_sem_pagamento(): void
    {
        $opcaoDinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
        $this->venda->update(['venda_valor_pago' => 50.00]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id,
            'pg_venda_opcaopagamento_id' => $opcaoDinheiro->id,
            'pg_venda_valor_pagamento' => 50.00,
            'pg_venda_valor_pago_pelo_cliente' => 50.00,
        ]);

        $payload = app(NfeIoService::class)->payloadPreview($this->venda->fresh());
        $detalhe = $payload['payment'][0]['paymentDetail'];

        $this->assertCount(1, $detalhe);
        $this->assertSame('cash', $detalhe[0]['method']);
    }
}
