<?php

namespace Tests\Feature;

use App\Enums\FormatoPizzaEnum;
use App\Filament\Resources\Categorias\Pages\ManageCategorias;
use App\Models\Categoria;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoriaFormatoPizzaTest extends TestCase
{
    use RefreshDatabase;

    public function test_categoria_nova_nasce_redonda(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas'])->refresh();

        $this->assertSame(FormatoPizzaEnum::REDONDA, $categoria->formatoPizza());
    }

    public function test_filha_quadrada_nao_segue_o_formato_do_pai_redondo(): void
    {
        $pai = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_permite_sabores' => true]);
        $filha = Categoria::create([
            'categoria_nome' => 'Quadradas',
            'categoria_pai_id' => $pai->id,
            'categoria_herda_sabores' => true,
            'categoria_formato_pizza' => FormatoPizzaEnum::QUADRADA,
        ]);

        $this->assertSame(FormatoPizzaEnum::QUADRADA, $filha->fresh()->formatoPizza());
        $this->assertSame(FormatoPizzaEnum::REDONDA, $pai->fresh()->formatoPizza());
    }

    public function test_admin_edita_o_formato_pelo_cadastro(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_permite_sabores' => true]);

        Livewire::test(ManageCategorias::class)
            ->callTableAction('edit', $categoria, data: [
                'categoria_nome' => 'Pizzas',
                'categoria_cardapio' => true,
                'categoria_cardapio_garcom' => true,
                'categoria_ordem' => 1,
                'categoria_permite_sabores' => true,
                'categoria_formato_pizza' => FormatoPizzaEnum::QUADRADA->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(FormatoPizzaEnum::QUADRADA, $categoria->fresh()->formatoPizza());
    }
}
