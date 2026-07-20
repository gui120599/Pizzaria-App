<?php

namespace Tests\Feature;

use App\Enums\MovimentacaoTipoEnum;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Produto;
use App\Models\User;
use App\Services\BalancoEstoqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalancoEstoqueServiceTest extends TestCase
{
    use RefreshDatabase;

    private BalancoEstoqueService $service;

    private int $categoriaId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BalancoEstoqueService::class);
        $this->categoriaId = Categoria::create(['categoria_nome' => 'Teste'])->id;
        // mov_user_id não é passado pelo BalancoEstoqueService — cai no fallback
        // Auth::check() do booted() de MovimentacaoProduto, daí a autenticação aqui.
        $this->actingAs(User::factory()->create(['name_first' => 'Operador']));
    }

    private function produto(array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => 'Queijo Mussarela',
            'produto_categoria_id' => $this->categoriaId,
            'produto_tipo' => 'insumo',
            'produto_controla_estoque' => true,
            'produto_saldo_estoque' => 10,
            'produto_custo_medio' => 5,
        ], $attrs));
    }

    public function test_sem_diferenca_nao_gera_movimentacao(): void
    {
        $produto = $this->produto();

        $balanco = $this->service->realizarBalanco($produto, 10);

        $this->assertNull($balanco);
    }

    public function test_sobra_gera_entrada_e_falta_gera_saida(): void
    {
        $produto = $this->produto();

        $sobra = $this->service->realizarBalanco($produto->fresh(), 15);
        $this->assertSame(MovimentacaoTipoEnum::ENTRADA, $sobra->mbal_tipo_movimentacao);
        $this->assertEqualsWithDelta(5.0, (float) $sobra->mbal_quantidade_ajuste, 0.001);

        $falta = $this->service->realizarBalanco($produto->fresh(), 8);
        $this->assertSame(MovimentacaoTipoEnum::SAIDA, $falta->mbal_tipo_movimentacao);
        $this->assertEqualsWithDelta(7.0, (float) $falta->mbal_quantidade_ajuste, 0.001);
    }

    public function test_sobra_com_produto_controla_lote_cria_lote_com_marca_e_validade(): void
    {
        $produto = $this->produto(['produto_controla_lote' => true]);
        $marca = Marca::create(['marca_nome' => 'Tirolez']);

        $this->service->realizarBalanco(
            produto: $produto->fresh(),
            quantidadeFisica: 18,
            loteCodigo: 'L-BAL-1',
            marcaId: $marca->id,
            validade: '2026-09-01',
        );

        $lote = $produto->lotes()->where('lote_codigo', 'L-BAL-1')->first();
        $this->assertNotNull($lote);
        $this->assertSame('Tirolez', $lote->marca->marca_nome);
        $this->assertSame('2026-09-01', $lote->lote_validade->toDateString());
        $this->assertEqualsWithDelta(8.0, (float) $lote->lote_qtd_atual, 0.001);
    }

    public function test_falta_ignora_dados_de_lote_informados(): void
    {
        $produto = $this->produto(['produto_controla_lote' => true]);
        $marca = Marca::create(['marca_nome' => 'Irrelevante']);

        $balanco = $this->service->realizarBalanco(
            produto: $produto->fresh(),
            quantidadeFisica: 4,
            loteCodigo: 'IRRELEVANTE',
            marcaId: $marca->id,
        );

        $this->assertSame(MovimentacaoTipoEnum::SAIDA, $balanco->mbal_tipo_movimentacao);
        $this->assertSame(0, $produto->lotes()->where('lote_codigo', 'IRRELEVANTE')->count());
    }
}
