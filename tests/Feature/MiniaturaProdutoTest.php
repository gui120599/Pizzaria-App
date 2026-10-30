<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Produto;
use App\Services\MiniaturaProdutoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Miniaturas WebP das fotos de produto: geradas no upload e pelo comando
 * produtos:miniaturas, sem nunca alterar a foto original; sem miniatura, a
 * tela usa a original.
 */
class MiniaturaProdutoTest extends TestCase
{
    use RefreshDatabase;

    private MiniaturaProdutoService $miniaturas;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->miniaturas = app(MiniaturaProdutoService::class);
    }

    private function fotoNoDisco(string $caminho = 'fotos_produtos/pizza.jpg', int $largura = 1200, int $altura = 900): string
    {
        Storage::disk('public')->put($caminho, UploadedFile::fake()->image('pizza.jpg', $largura, $altura)->getContent());

        return $caminho;
    }

    private function produto(array $attrs = []): Produto
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_cardapio' => true]);

        return Produto::create(array_merge([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
            'produto_cardapio' => true,
        ], $attrs));
    }

    public function test_gera_uma_miniatura_webp_por_tamanho_reduzindo_pelo_lado_menor(): void
    {
        $foto = $this->fotoNoDisco();

        $this->miniaturas->gerar($foto);

        foreach (MiniaturaProdutoService::LADOS as $lado) {
            $caminho = $this->miniaturas->caminho($foto, $lado);
            Storage::disk('public')->assertExists($caminho);
            [$largura, $altura, $tipo] = getimagesize(Storage::disk('public')->path($caminho));
            $this->assertSame($lado, min($largura, $altura));
            $this->assertSame(IMAGETYPE_WEBP, $tipo);
        }
    }

    public function test_foto_menor_que_a_miniatura_nao_e_ampliada(): void
    {
        $foto = $this->fotoNoDisco(largura: 200, altura: 200);

        $this->miniaturas->gerar($foto);

        [$largura] = getimagesize(Storage::disk('public')->path($this->miniaturas->caminho($foto, 640)));
        $this->assertSame(200, $largura);
    }

    public function test_salvar_produto_com_foto_nova_gera_as_miniaturas_sem_tocar_na_original(): void
    {
        $foto = $this->fotoNoDisco();
        $original = Storage::disk('public')->get($foto);

        $produto = $this->produto(['produto_foto' => $foto]);

        Storage::disk('public')->assertExists($this->miniaturas->caminho($foto, 320));
        $this->assertSame($original, Storage::disk('public')->get($foto));
        $this->assertSame($foto, $produto->fresh()->produto_foto);
    }

    public function test_url_da_miniatura_cai_na_foto_original_quando_a_miniatura_nao_existe(): void
    {
        $foto = $this->fotoNoDisco();
        $produto = $this->produto(['produto_foto' => $foto]);
        Storage::disk('public')->delete($this->miniaturas->caminho($foto, 320));

        $this->assertSame(Storage::disk('public')->url($foto), $produto->fresh()->getMiniaturaUrl(112));
        $this->assertSame(
            Storage::disk('public')->url($this->miniaturas->caminho($foto, 160)),
            $produto->fresh()->getMiniaturaUrl(56),
        );
    }

    public function test_produto_sem_foto_mantem_a_imagem_padrao(): void
    {
        $produto = $this->produto();

        $this->assertFalse($produto->temFoto());
        $this->assertSame(asset('Sem Imagem.png'), $produto->getMiniaturaUrl(112));
    }

    public function test_comando_em_dry_run_nao_grava_nada(): void
    {
        $foto = $this->fotoNoDisco();
        $this->produto()->forceFill(['produto_foto' => $foto])->saveQuietly();

        $this->artisan('produtos:miniaturas', ['--dry-run' => true])
            ->expectsOutputToContain('1 a processar')
            ->assertSuccessful();

        Storage::disk('public')->assertMissing($this->miniaturas->caminho($foto, 320));
    }

    public function test_comando_gera_as_miniaturas_que_faltam_das_fotos_antigas(): void
    {
        $foto = $this->fotoNoDisco();
        $produto = $this->produto();
        $produto->forceFill(['produto_foto' => $foto])->saveQuietly();

        $this->artisan('produtos:miniaturas')->assertSuccessful();

        foreach (MiniaturaProdutoService::LADOS as $lado) {
            Storage::disk('public')->assertExists($this->miniaturas->caminho($foto, $lado));
        }
        $this->assertSame($foto, $produto->fresh()->produto_foto);
    }
}
