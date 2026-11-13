<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Empresa;
use App\Models\FornecedorProduto;
use App\Models\MovimentacaoProduto;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\User;
use App\Services\Compras\CustoAquisicaoCalculator;
use App\Services\CompraService;
use App\Services\Nfe\NfeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustoAquisicaoCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste LTDA', 'empresa_cnpj' => '11222333000181']);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Insumos'])->id;
    }

    public function test_com_a_chave_desligada_o_custo_e_o_mesmo_de_sempre(): void
    {
        config(['compras.custo.incluir_tributos_nao_recuperaveis' => false]);

        $compra = $this->confirmarNotaComAcrescimos();

        // vProd − desconto do item (90 e 300) + frete rateado pelo valor; ST, IPI e seguro fora.
        $this->assertEquals(410.00, $compra->compra_valor_total);
        $molho = $compra->itens->firstWhere('ci_codigo_fornecedor', 'MOL-1');
        $this->assertEquals(4.6154, $molho->ci_valor_rateio);
        $this->assertEquals(94.62, $molho->ci_custo_aquisicao_total);
        $this->assertEquals(9.4615, round((float) $this->movimentacao($molho)->mov_custo_unitario, 4));
    }

    public function test_com_a_chave_ligada_entram_frete_ipi_e_st_do_proprio_item_e_a_soma_bate_com_a_nota(): void
    {
        config(['compras.custo.incluir_tributos_nao_recuperaveis' => true]);

        $compra = $this->confirmarNotaComAcrescimos();

        $molho = $compra->itens->firstWhere('ci_codigo_fornecedor', 'MOL-1');
        $azeitona = $compra->itens->firstWhere('ci_codigo_fornecedor', 'AZE-1');

        // Molho: 100 − 10 desconto + 5 frete + 4,50 IPI + 8 ST. Azeitona: 300 + 15 frete.
        $this->assertEquals(107.50, $molho->ci_custo_aquisicao_total);
        $this->assertEquals(10.75, $molho->ci_custo_unitario_aquisicao);
        $this->assertEquals(315.00, $azeitona->ci_custo_aquisicao_total);
        $this->assertEqualsWithDelta(422.50, $molho->ci_custo_aquisicao_total + $azeitona->ci_custo_aquisicao_total, 0.01);
        $this->assertEquals(422.50, $compra->compra_valor_total);
        $this->assertEquals(10.75, round((float) $this->movimentacao($molho)->mov_custo_unitario, 4));
    }

    public function test_frete_so_no_total_da_nota_e_rateado_pelo_valor_dos_produtos(): void
    {
        config(['compras.custo.incluir_tributos_nao_recuperaveis' => true]);
        $compra = Compra::create(['compra_valor_frete' => 30]);
        $itens = collect([$this->item($compra, 100), $this->item($compra, 200)]);

        $custos = app(CustoAquisicaoCalculator::class)->calcular($compra, $itens);

        $this->assertSame([110.0, 220.0], array_column(array_values($custos), 'total'));
    }

    public function test_residuo_do_rateio_vai_para_o_item_de_maior_valor(): void
    {
        config(['compras.custo.incluir_tributos_nao_recuperaveis' => true]);
        $compra = Compra::create(['compra_valor_frete' => 10]);
        $itens = collect([$this->item($compra, 100), $this->item($compra, 100), $this->item($compra, 100.01)]);

        $custos = array_values(app(CustoAquisicaoCalculator::class)->calcular($compra, $itens));

        $this->assertEqualsWithDelta(10.00, array_sum(array_column($custos, 'rateio')), 0.0001);
        $this->assertEquals(3.34, $custos[2]['rateio']);
    }

    private function confirmarNotaComAcrescimos(): Compra
    {
        $fornecedor = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => 'Atacado', 'cpf_cnpj' => '55666777000188']);
        foreach (['MOL-1' => 'Molho de tomate', 'AZE-1' => 'Azeitona'] as $codigo => $nome) {
            FornecedorProduto::create([
                'fp_prestador_id' => $fornecedor->id,
                'fp_produto_id' => $this->insumo($nome)->id,
                'fp_codigo_fornecedor' => $codigo,
                'fp_fator_conversao' => 1,
            ]);
        }

        $xml = file_get_contents(base_path('tests/Fixtures/nfe-entrada/automacao/custo-acrescimos.xml'));
        $usuario = User::factory()->create(['name_first' => 'Comprador']);
        $compra = app(NfeImportService::class)->importar($xml, $usuario->id);

        return app(CompraService::class)->confirmar($compra)->load('itens');
    }

    private function movimentacao(CompraItem $item): MovimentacaoProduto
    {
        return MovimentacaoProduto::query()->where('mov_produto_id', $item->ci_produto_id)->sole();
    }

    private function insumo(string $nome): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'UN',
        ]);
    }

    private function item(Compra $compra, float $valor): CompraItem
    {
        return CompraItem::create([
            'ci_compra_id' => $compra->id,
            'ci_quantidade_compra' => 1,
            'ci_custo_unitario_compra' => $valor,
            'ci_fator_conversao' => 1,
        ]);
    }
}
