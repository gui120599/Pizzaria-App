<?php

namespace Tests\Feature;

use App\Enums\PrestadorCategoriaEnum;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\FornecedorProduto;
use App\Models\MovimentacaoProduto;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\CompraService;
use App\Services\Nfe\NfeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportarXmlCompraTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ_EMITENTE = '14200166000166';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function xmlExemplo(): string
    {
        return file_get_contents(base_path('tests/Fixtures/nfe-compra-exemplo.xml'));
    }

    private function categoria(): Categoria
    {
        return Categoria::create(['categoria_nome' => 'Insumos']);
    }

    private function produto(array $atributos): Produto
    {
        return Produto::create(array_merge(['produto_tipo' => 'insumo'], $atributos));
    }

    public function test_importa_cria_rascunho_com_item_mapeado_por_ean_e_item_pendente(): void
    {
        $produto = $this->produto([
            'produto_descricao' => 'Açúcar refinado',
            'produto_categoria_id' => $this->categoria()->id,
            'produto_unidade_estoque' => 'UN',
            'produto_codigo_EAN' => '7891234567890',
        ]);

        $compra = app(NfeImportService::class)->importar($this->xmlExemplo(), auth()->id());

        $this->assertSame('rascunho', $compra->compra_status->value);
        $this->assertSame('xml', $compra->compra_origem);
        $this->assertSame('35260114200166000166550010000000461123456789', $compra->compra_chave_nfe);
        $this->assertNotNull($compra->compra_xml_path);
        $this->assertSame('Distribuidora Exemplo', $compra->prestador->nome_fantasia);

        $itens = $compra->itens()->orderBy('id')->get();
        $this->assertCount(2, $itens);

        $this->assertSame($produto->id, $itens[0]->ci_produto_id);
        $this->assertEqualsWithDelta(10.0, (float) $itens[0]->ci_quantidade_compra, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $itens[0]->ci_custo_unitario_compra, 0.0001);
        $this->assertSame('L2026A', $itens[0]->ci_lote_codigo);

        $this->assertNull($itens[1]->ci_produto_id);
        $this->assertSame('TEMPERO COMPOSTO XYZ 500G', $itens[1]->ci_descricao_fornecedor);

        $this->assertEqualsWithDelta(90.0, (float) $compra->compra_valor_produtos, 0.001);
        $this->assertEqualsWithDelta(95.0, (float) $compra->compra_valor_total, 0.001);
    }

    public function test_importa_cria_fornecedor_novo_quando_cnpj_nao_cadastrado(): void
    {
        $this->assertSame(0, Prestador::count());

        $compra = app(NfeImportService::class)->importar($this->xmlExemplo());

        $this->assertSame(1, Prestador::count());
        $this->assertSame(self::CNPJ_EMITENTE, $compra->prestador->cpf_cnpj);
        $this->assertTrue($compra->prestador->categoria === PrestadorCategoriaEnum::FORNECEDOR);
    }

    public function test_importa_reaproveita_fornecedor_existente_por_cnpj(): void
    {
        $existente = Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Fornecedor Já Cadastrado',
            'cpf_cnpj' => self::CNPJ_EMITENTE,
        ]);

        $compra = app(NfeImportService::class)->importar($this->xmlExemplo());

        $this->assertSame(1, Prestador::count());
        $this->assertSame($existente->id, $compra->compra_prestador_id);
        // Não sobrescreve o cadastro existente com o que veio no XML.
        $this->assertSame('Fornecedor Já Cadastrado', $existente->fresh()->razao_social);
    }

    public function test_bloqueia_reimportacao_da_mesma_chave_de_acesso(): void
    {
        app(NfeImportService::class)->importar($this->xmlExemplo());

        $this->expectException(ValidationException::class);
        app(NfeImportService::class)->importar($this->xmlExemplo());
    }

    public function test_resolve_produto_pelo_depara_do_fornecedor_com_prioridade_sobre_ean(): void
    {
        $categoria = $this->categoria();

        $produtoPorEan = $this->produto([
            'produto_descricao' => 'Produto que bateria por EAN',
            'produto_categoria_id' => $categoria->id,
            'produto_unidade_estoque' => 'UN',
            'produto_codigo_EAN' => '7891234567890',
        ]);

        $produtoDePara = $this->produto([
            'produto_descricao' => 'Produto correto (de-para do fornecedor)',
            'produto_categoria_id' => $categoria->id,
            'produto_unidade_estoque' => 'UN',
        ]);

        $prestador = Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora Exemplo LTDA',
            'cpf_cnpj' => self::CNPJ_EMITENTE,
        ]);

        FornecedorProduto::create([
            'fp_prestador_id' => $prestador->id,
            'fp_produto_id' => $produtoDePara->id,
            'fp_codigo_fornecedor' => '001',
        ]);

        $compra = app(NfeImportService::class)->importar($this->xmlExemplo());

        $item1 = $compra->itens()->orderBy('id')->first();
        $this->assertSame($produtoDePara->id, $item1->ci_produto_id);
        $this->assertNotSame($produtoPorEan->id, $item1->ci_produto_id);
    }

    public function test_fluxo_completo_import_mapear_pendente_e_confirmar_gera_estoque(): void
    {
        $categoria = $this->categoria();

        $produtoAcucar = $this->produto([
            'produto_descricao' => 'Açúcar refinado',
            'produto_categoria_id' => $categoria->id,
            'produto_unidade_estoque' => 'UN',
            'produto_codigo_EAN' => '7891234567890',
            'produto_saldo_estoque' => 0,
            'produto_custo_medio' => 0,
        ]);

        $produtoTempero = $this->produto([
            'produto_descricao' => 'Tempero composto',
            'produto_categoria_id' => $categoria->id,
            'produto_unidade_estoque' => 'CX',
            'produto_saldo_estoque' => 0,
            'produto_custo_medio' => 0,
        ]);

        $compra = app(NfeImportService::class)->importar($this->xmlExemplo());

        // Mapeia manualmente o item pendente (simula a revisão do usuário na tela).
        $itemPendente = $compra->itens()->whereNull('ci_produto_id')->firstOrFail();
        $itemPendente->update(['ci_produto_id' => $produtoTempero->id]);

        $compra = app(CompraService::class)->confirmar($compra->fresh());

        $this->assertSame('confirmada', $compra->compra_status->value);

        $produtoAcucar->refresh();
        $produtoTempero->refresh();

        $this->assertEqualsWithDelta(10.0, (float) $produtoAcucar->produto_saldo_estoque, 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $produtoTempero->produto_saldo_estoque, 0.001);
        $this->assertGreaterThan(0, (float) $produtoAcucar->produto_custo_medio);
        $this->assertGreaterThan(0, (float) $produtoTempero->produto_custo_medio);

        $this->assertSame(2, MovimentacaoProduto::where('mov_referencia_type', Compra::class)
            ->where('mov_referencia_id', $compra->id)
            ->count());
    }
}
