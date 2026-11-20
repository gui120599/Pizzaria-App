<?php

namespace Tests\Feature;

use App\Enums\AcaoBannerEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\BannersCardapio\BannerCardapioResource;
use App\Filament\Resources\BannersCardapio\Pages\CreateBannerCardapio;
use App\Models\BannerCardapio;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\User;
use App\Services\CardapioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Carrossel de banners do topo do cardápio: vigência (janela única ou dias
 * da semana com horário), canal (delivery/mesa), destino do toque e cadastro
 * no painel.
 */
class BannerCardapioTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function titulosNoCardapio(bool $mesa = false): array
    {
        return array_column(app(CardapioService::class)->dados($mesa)['banners'], 'titulo');
    }

    public function test_banner_sem_janela_fica_no_ar_e_sai_quando_desligado_ou_fora_da_janela(): void
    {
        $sempre = BannerCardapio::factory()->create(['banner_titulo' => 'Sempre']);
        BannerCardapio::factory()->create(['banner_titulo' => 'Futuro', 'banner_inicio' => now()->addDay()]);
        BannerCardapio::factory()->create(['banner_titulo' => 'Vencido', 'banner_fim' => now()->subMinute()]);
        BannerCardapio::factory()->create(['banner_titulo' => 'Desligado', 'banner_ativo' => false]);

        $this->assertSame(['Sempre'], $this->titulosNoCardapio());
        $this->assertSame('No ar', $sempre->situacao());
    }

    public function test_sextou_aparece_so_na_sexta_a_partir_das_18h(): void
    {
        BannerCardapio::factory()->recorrente([Carbon::FRIDAY], '18:00', '23:59')->create(['banner_titulo' => 'Sextou']);

        $sexta = Carbon::parse('next friday');

        $this->travelTo($sexta->copy()->setTime(17, 30));
        $this->assertSame([], $this->titulosNoCardapio());

        $this->travelTo($sexta->copy()->setTime(19, 0));
        $this->assertSame(['Sextou'], $this->titulosNoCardapio());

        $this->travelTo($sexta->copy()->addDay()->setTime(19, 0));
        $this->assertSame([], $this->titulosNoCardapio());
        $this->assertSame('Fora do horário', BannerCardapio::sole()->situacao());
    }

    public function test_carrossel_segue_a_ordem_e_o_canal_de_cada_banner(): void
    {
        BannerCardapio::factory()->create(['banner_titulo' => 'Segundo', 'banner_ordem' => 2]);
        BannerCardapio::factory()->create(['banner_titulo' => 'Primeiro', 'banner_ordem' => 1]);
        BannerCardapio::factory()->create(['banner_titulo' => 'Só delivery', 'banner_ordem' => 3, 'banner_exibir_mesa' => false]);

        $this->assertSame(['Primeiro', 'Segundo', 'Só delivery'], $this->titulosNoCardapio());
        $this->assertSame(['Primeiro', 'Segundo'], $this->titulosNoCardapio(mesa: true));
    }

    public function test_destino_do_toque_so_vale_para_o_que_esta_no_cardapio(): void
    {
        $pizzas = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_cardapio' => true]);
        $grande = Categoria::create(['categoria_nome' => 'Pizza Grande', 'categoria_cardapio' => true, 'categoria_pai_id' => $pizzas->id]);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $grande->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50,
            'produto_cardapio' => true,
        ]);

        $categoria = BannerCardapio::factory()->create(['banner_acao' => AcaoBannerEnum::CATEGORIA, 'banner_categoria_id' => $grande->id]);
        $doProduto = BannerCardapio::factory()->create(['banner_acao' => AcaoBannerEnum::PRODUTO, 'banner_produto_id' => $produto->id]);
        $link = BannerCardapio::factory()->create(['banner_acao' => AcaoBannerEnum::LINK, 'banner_url' => 'https://instagram.com/emporio']);
        $script = BannerCardapio::factory()->create(['banner_acao' => AcaoBannerEnum::LINK, 'banner_url' => 'javascript:alert(1)']);

        // Subcategoria leva à seção da categoria mãe (é ela que tem id="categoria_*").
        $this->assertSame(['tipo' => 'categoria', 'alvo' => $pizzas->id], $categoria->destino());
        $this->assertSame(['tipo' => 'produto', 'alvo' => $produto->id], $doProduto->destino());
        $this->assertSame(['tipo' => 'link', 'alvo' => 'https://instagram.com/emporio'], $link->destino());
        $this->assertNull($script->destino());

        $produto->update(['produto_cardapio' => false]);
        $this->assertNull($doProduto->fresh()->destino());
    }

    public function test_pagina_do_cardapio_monta_o_carrossel_com_a_imagem(): void
    {
        BannerCardapio::factory()->create(['banner_titulo' => 'Sextou', 'banner_imagem' => 'banners_cardapio/sextou.gif']);

        $this->get(route('cardapio'))
            ->assertOk()
            ->assertSee('carrosselBanners(1)', false)
            ->assertSee('storage/banners_cardapio/sextou.gif', false)
            ->assertSee('alt="Sextou"', false);
    }

    public function test_sem_banner_no_ar_o_cardapio_nao_monta_o_carrossel(): void
    {
        $this->get(route('cardapio'))->assertOk()->assertDontSee('carrosselBanners', false);
    }

    public function test_admin_cadastra_banner_com_gif_e_o_link_precisa_ser_http(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $gif = UploadedFile::fake()->image('sextou.gif', 1680, 640);

        Livewire::test(CreateBannerCardapio::class)
            ->fillForm([
                'banner_titulo' => 'Sextou',
                'banner_imagem' => $gif,
                'banner_acao' => AcaoBannerEnum::LINK->value,
                'banner_url' => 'javascript:alert(1)',
            ])
            ->call('create')
            ->assertHasFormErrors(['banner_url']);

        Livewire::test(CreateBannerCardapio::class)
            ->fillForm([
                'banner_titulo' => 'Sextou',
                'banner_imagem' => $gif,
                'banner_acao' => AcaoBannerEnum::LINK->value,
                'banner_url' => 'https://instagram.com/emporio',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $banner = BannerCardapio::sole();
        Storage::disk('public')->assertExists($banner->banner_imagem);
        $this->assertStringEndsWith('.gif', $banner->banner_imagem);
        $this->assertSame(1, $banner->banner_ordem);
    }

    public function test_gerente_gerencia_os_banners_e_atendente_nao(): void
    {
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $this->get(BannerCardapioResource::getUrl('index'))->assertOk();

        $this->actingAs(User::factory()->atendente()->create(['name_first' => 'Carla']));
        $this->get(BannerCardapioResource::getUrl('index'))->assertForbidden();
    }
}
