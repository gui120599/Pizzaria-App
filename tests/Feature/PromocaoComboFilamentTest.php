<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\PromocoesCombo\Pages\CreatePromocaoCombo;
use App\Filament\Resources\PromocoesCombo\Pages\EditPromocaoCombo;
use App\Filament\Resources\PromocoesCombo\Pages\ListPromocoesCombo;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\PromocaoCombo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PromocaoComboFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_cria_a_promocao_com_categoria_participante_e_produto_excluido(): void
    {
        $grande = Categoria::create(['categoria_nome' => 'Pizza Grande']);
        $camarao = Produto::create([
            'produto_descricao' => 'Camarão',
            'produto_categoria_id' => $grande->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 89.90,
        ]);

        Livewire::test(CreatePromocaoCombo::class)
            ->fillForm([
                'promocombo_nome' => '2 Grandes por 87,99',
                'promocombo_quantidade' => 2,
                // Money::make() espera o formato BR (vírgula decimal).
                'promocombo_preco' => '87,99',
                'promocombo_inicio' => now()->format('Y-m-d H:i:s'),
                'promocombo_fim' => now()->addDays(7)->format('Y-m-d H:i:s'),
                'promocombo_aplica_fracionado' => false,
                'participantes' => [
                    ['pcp_categoria_id' => $grande->id, 'pcp_produto_id' => null, 'pcp_excluir' => false],
                    ['pcp_categoria_id' => null, 'pcp_produto_id' => $camarao->id, 'pcp_excluir' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promocao = PromocaoCombo::with('participantes')->sole();
        $this->assertSame('87.99', $promocao->promocombo_preco);
        $this->assertFalse($promocao->promocombo_aplica_fracionado);
        $this->assertTrue($promocao->participantes->firstWhere('pcp_produto_id', $camarao->id)->pcp_excluir);
        $this->assertSame($grande->id, $promocao->participantes->firstWhere('pcp_excluir', false)->pcp_categoria_id);

        Livewire::test(ListPromocoesCombo::class)->assertCanSeeTableRecords([$promocao]);
        Livewire::test(EditPromocaoCombo::class, ['record' => $promocao->id])->assertOk();
    }

    public function test_imagem_da_promocao_e_gravada_no_disco_publico(): void
    {
        Storage::fake('public');
        $grande = Categoria::create(['categoria_nome' => 'Pizza Grande']);

        Livewire::test(CreatePromocaoCombo::class)
            ->fillForm([
                'promocombo_nome' => 'Combo com banner',
                'promocombo_quantidade' => 2,
                'promocombo_preco' => '87,99',
                'promocombo_inicio' => now()->format('Y-m-d H:i:s'),
                'promocombo_fim' => now()->addDay()->format('Y-m-d H:i:s'),
                'promocombo_imagem' => UploadedFile::fake()->image('banner.jpg', 1200, 675),
                'participantes' => [['pcp_categoria_id' => $grande->id, 'pcp_produto_id' => null, 'pcp_excluir' => false]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $caminho = PromocaoCombo::sole()->promocombo_imagem;
        $this->assertStringStartsWith('promocoes_combo/', $caminho);
        Storage::disk('public')->assertExists($caminho);
    }

    public function test_participante_precisa_de_categoria_ou_produto_e_nao_dos_dois(): void
    {
        $grande = Categoria::create(['categoria_nome' => 'Pizza Grande']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $grande->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 59.90,
        ]);
        $base = [
            'promocombo_nome' => 'Combo',
            'promocombo_quantidade' => 2,
            'promocombo_preco' => '87,99',
            'promocombo_inicio' => now()->format('Y-m-d H:i:s'),
            'promocombo_fim' => now()->addDay()->format('Y-m-d H:i:s'),
        ];

        foreach ([[null, null], [$grande->id, $produto->id]] as [$categoria, $produtoId]) {
            Livewire::test(CreatePromocaoCombo::class)
                ->fillForm($base + ['participantes' => [['pcp_categoria_id' => $categoria, 'pcp_produto_id' => $produtoId, 'pcp_excluir' => false]]])
                ->call('create')
                ->assertHasFormErrors();
        }

        $this->assertSame(0, PromocaoCombo::count());
    }
}
