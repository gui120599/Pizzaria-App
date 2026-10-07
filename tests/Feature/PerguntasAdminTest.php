<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Categorias\Pages\ManageCategorias;
use App\Filament\Resources\Produtos\Pages\EditProduto;
use App\Filament\Resources\Produtos\RelationManagers\PerguntasRelationManager;
use App\Models\Categoria;
use App\Models\Pergunta;
use App\Models\Produto;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cadastro das perguntas do item: aba Perguntas do produto e seção no modal
 * da categoria.
 */
class PerguntasAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_aba_do_produto_cria_pergunta_com_opcoes_e_acrescimo(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        Repeater::fake();
        $produto = $this->produto();

        $this->relationManager($produto)
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'pergunta_texto' => 'Ponto da carne',
                'pergunta_minimo' => 1,
                'pergunta_maximo' => 1,
                'pergunta_ativa' => true,
                'opcoes' => [
                    ['pergunta_opcao_nome' => 'Ao ponto', 'pergunta_opcao_valor' => '0,00', 'pergunta_opcao_ativa' => true],
                    ['pergunta_opcao_nome' => 'Com bacon', 'pergunta_opcao_valor' => '4,50', 'pergunta_opcao_ativa' => true],
                ],
            ])
            ->assertHasNoActionErrors();

        $pergunta = Pergunta::sole();
        $this->assertSame($produto->id, $pergunta->pergunta_produto_id);
        $this->assertTrue($pergunta->obrigatoria());
        $this->assertSame(['Ao ponto' => '0.00', 'Com bacon' => '4.50'], $pergunta->opcoes->pluck('pergunta_opcao_valor', 'pergunta_opcao_nome')->all());
    }

    public function test_maximo_menor_que_o_minimo_e_recusado(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        Repeater::fake();

        $this->relationManager($this->produto())
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'pergunta_texto' => 'Molhos',
                'pergunta_minimo' => 2,
                'pergunta_maximo' => 1,
                'opcoes' => [['pergunta_opcao_nome' => 'Alho', 'pergunta_opcao_valor' => '0,00', 'pergunta_opcao_ativa' => true]],
            ])
            ->assertHasActionErrors(['pergunta_maximo']);

        $this->assertSame(0, Pergunta::count());
    }

    public function test_quem_nao_edita_o_produto_nao_cria_pergunta(): void
    {
        $this->seed(PermissionSeeder::class);
        $atendente = User::factory()->atendente()->create(['name_first' => 'Atendente']);
        $this->actingAs($atendente);

        $this->relationManager($this->produto())
            ->assertActionHidden(TestAction::make(CreateAction::class)->table());
    }

    public function test_modal_da_categoria_grava_perguntas_com_opcoes(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        Repeater::fake();
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        Livewire::test(ManageCategorias::class)
            ->callTableAction('edit', $categoria, data: [
                'categoria_nome' => 'Pizzas',
                'categoria_cardapio' => true,
                'categoria_cardapio_garcom' => true,
                'categoria_ordem' => 1,
                'perguntas' => [[
                    'pergunta_texto' => 'Borda',
                    'pergunta_minimo' => 0,
                    'pergunta_maximo' => 1,
                    'pergunta_ativa' => true,
                    'opcoes' => [['pergunta_opcao_nome' => 'Catupiry', 'pergunta_opcao_valor' => 10, 'pergunta_opcao_ativa' => true]],
                ]],
            ])
            ->assertHasNoTableActionErrors();

        $pergunta = $categoria->perguntas()->sole();
        $this->assertSame('Borda', $pergunta->pergunta_texto);
        $this->assertSame('10.00', $pergunta->opcoes()->sole()->pergunta_opcao_valor);
    }

    private function produto(): Produto
    {
        return Produto::create([
            'produto_descricao' => 'X-Burguer',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Lanches'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);
    }

    private function relationManager(Produto $produto): mixed
    {
        return Livewire::test(PerguntasRelationManager::class, [
            'ownerRecord' => $produto,
            'pageClass' => EditProduto::class,
        ]);
    }
}
