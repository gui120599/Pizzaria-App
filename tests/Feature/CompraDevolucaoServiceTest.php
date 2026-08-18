<?php

namespace Tests\Feature;

use App\Enums\MovimentacaoOrigemEnum;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\MovimentacaoProduto;
use App\Models\Prestador;
use App\Models\PrestadorCredito;
use App\Models\Produto;
use App\Models\User;
use App\Services\CompraDevolucaoService;
use App\Services\CompraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompraDevolucaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompraDevolucaoService $service;

    private int $categoriaId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CompraDevolucaoService::class);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
        $user = User::factory()->create(['name_first' => 'Comprador']);
        $this->userId = $user->id;
        $this->actingAs($user);
    }

    private function fornecedor(): Prestador
    {
        return Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora X',
            'nome' => 'Distribuidora X',
            'cpf_cnpj' => '12345678000199',
        ]);
    }

    /**
     * @return array{0: Compra, 1: CompraItem, 2: Produto}
     */
    private function compraConfirmadaComItem(Prestador $forn, float $quantidade = 10, float $custoUnitario = 10): array
    {
        $insumo = Produto::create([
            'produto_descricao' => 'Queijo',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
        ]);

        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        $item = CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_descricao_fornecedor' => 'QUEIJO', 'ci_quantidade_compra' => $quantidade,
            'ci_unidade_compra' => 'KG', 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => $custoUnitario,
        ]);

        app(CompraService::class)->confirmar($compra->fresh('itens'));

        return [$compra->fresh(), $item->fresh(), $insumo->fresh()];
    }

    public function test_devolucao_parcial_baixa_estoque_e_cria_credito_com_o_fornecedor(): void
    {
        $forn = $this->fornecedor();
        [$compra, $item, $insumo] = $this->compraConfirmadaComItem($forn, quantidade: 10, custoUnitario: 10);

        $devolucao = $this->service->registrar($compra, [
            ['compra_item_id' => $item->id, 'quantidade' => 3],
        ], 'Produto fora da validade');

        $this->assertEqualsWithDelta(7.0, (float) $insumo->fresh()->produto_saldo_estoque, 0.001);
        $this->assertSame(
            1,
            MovimentacaoProduto::where('mov_produto_id', $insumo->id)
                ->where('mov_origem', MovimentacaoOrigemEnum::DEVOLUCAO_COMPRA)
                ->count()
        );

        $this->assertEqualsWithDelta(30.0, (float) $devolucao->valor_total, 0.01);
        $this->assertSame('Produto fora da validade', $devolucao->motivo);

        $credito = PrestadorCredito::doPrestador($forn->id)->first();
        $this->assertNotNull($credito);
        $this->assertEqualsWithDelta(30.0, (float) $credito->valor, 0.01);
        $this->assertNull($credito->aplicado_em_lancamento_id);
    }

    public function test_devolucao_nao_pode_exceder_a_quantidade_comprada(): void
    {
        $forn = $this->fornecedor();
        [$compra, $item] = $this->compraConfirmadaComItem($forn, quantidade: 10);

        $this->expectException(ValidationException::class);
        $this->service->registrar($compra, [
            ['compra_item_id' => $item->id, 'quantidade' => 11],
        ]);
    }

    public function test_devolucoes_sucessivas_respeitam_o_saldo_ja_devolvido(): void
    {
        $forn = $this->fornecedor();
        [$compra, $item] = $this->compraConfirmadaComItem($forn, quantidade: 10);

        $this->service->registrar($compra, [['compra_item_id' => $item->id, 'quantidade' => 6]]);

        $this->expectException(ValidationException::class);
        $this->service->registrar($compra, [['compra_item_id' => $item->id, 'quantidade' => 5]]);
    }

    public function test_nao_permite_devolucao_de_compra_em_rascunho(): void
    {
        $forn = $this->fornecedor();
        $insumo = Produto::create([
            'produto_descricao' => 'Queijo',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
        ]);
        $compra = Compra::create([
            'compra_prestador_id' => $forn->id,
            'compra_data_entrada' => now()->toDateString(),
            'compra_user_id' => $this->userId,
        ]);
        $item = CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 10, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 10,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->registrar($compra, [['compra_item_id' => $item->id, 'quantidade' => 1]]);
    }
}
