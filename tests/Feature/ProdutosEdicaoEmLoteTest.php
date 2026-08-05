<?php

namespace Tests\Feature;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Produtos\Pages\ListProdutos;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre a bulk action "edicao_em_lote" do ProdutosTable: só os campos com o
 * toggle "alterar_*" marcado podem ser sobrescritos nos produtos
 * selecionados — os demais campos precisam ficar intactos.
 */
class ProdutosEdicaoEmLoteTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoriaOrigem;

    private Categoria $categoriaDestino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->categoriaOrigem = Categoria::create(['categoria_nome' => 'Origem']);
        $this->categoriaDestino = Categoria::create(['categoria_nome' => 'Destino']);
    }

    private function produto(string $nome): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoriaOrigem->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_controla_estoque' => true,
            'produto_modo_controle_estoque' => EstoqueModoControleEnum::NAO_CONTROLAR,
        ]);
    }

    public function test_altera_so_o_campo_marcado_e_preserva_os_demais(): void
    {
        $a = $this->produto('Calabresa');
        $b = $this->produto('Marguerita');

        Livewire::test(ListProdutos::class)
            ->callTableBulkAction('edicao_em_lote', [$a, $b], data: [
                'alterar_categoria' => true,
                'produto_categoria_id' => $this->categoriaDestino->id,
                // Nenhum outro toggle marcado — tipo e modo de controle não podem mudar.
            ])
            ->assertHasNoTableBulkActionErrors();

        $a->refresh();
        $b->refresh();

        $this->assertSame($this->categoriaDestino->id, $a->produto_categoria_id);
        $this->assertSame($this->categoriaDestino->id, $b->produto_categoria_id);

        // Campos não marcados ficam como estavam.
        $this->assertSame(ProdutoTipoEnum::PRODUZIDO, ProdutoTipoEnum::from($a->produto_tipo));
        $this->assertSame(EstoqueModoControleEnum::NAO_CONTROLAR, $a->produto_modo_controle_estoque);
    }

    public function test_altera_multiplos_campos_marcados_de_uma_vez(): void
    {
        $produto = $this->produto('Calabresa');

        Livewire::test(ListProdutos::class)
            ->callTableBulkAction('edicao_em_lote', [$produto], data: [
                'alterar_modo_controle' => true,
                'produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR->value,
                'alterar_cardapio' => true,
                'produto_cardapio' => false,
            ])
            ->assertHasNoTableBulkActionErrors();

        $produto->refresh();

        $this->assertSame(EstoqueModoControleEnum::BLOQUEAR, $produto->produto_modo_controle_estoque);
        $this->assertFalse((bool) $produto->produto_cardapio);
        // Categoria não foi marcada pra alterar — permanece a original.
        $this->assertSame($this->categoriaOrigem->id, $produto->produto_categoria_id);
    }

    public function test_altera_controla_marca_em_lote(): void
    {
        $a = $this->produto('Papel Toalha');
        $b = $this->produto('Guardanapo');

        Livewire::test(ListProdutos::class)
            ->callTableBulkAction('edicao_em_lote', [$a, $b], data: [
                'alterar_controla_marca' => true,
                'produto_controla_marca' => true,
            ])
            ->assertHasNoTableBulkActionErrors();

        $a->refresh();
        $b->refresh();
        $this->assertTrue((bool) $a->produto_controla_marca);
        $this->assertTrue((bool) $b->produto_controla_marca);
        // Lote continua desligado — os dois toggles são independentes.
        $this->assertFalse((bool) $a->produto_controla_lote);
    }

    public function test_sem_nenhum_toggle_marcado_nao_altera_nada(): void
    {
        $produto = $this->produto('Calabresa');
        $categoriaOriginal = $produto->produto_categoria_id;

        Livewire::test(ListProdutos::class)
            ->callTableBulkAction('edicao_em_lote', [$produto], data: [])
            ->assertHasNoTableBulkActionErrors();

        $produto->refresh();
        $this->assertSame($categoriaOriginal, $produto->produto_categoria_id);
    }
}
