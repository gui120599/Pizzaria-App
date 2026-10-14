<?php

namespace Tests\Feature;

use App\Enums\Cozinha\EscopoIngrediente;
use App\Enums\Cozinha\TipoSetorProducao;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Cozinha\Pages\Estacao;
use App\Filament\Cozinha\Pages\Expedicao;
use App\Filament\Garcom\Pages\AtenderMesa;
use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\ProducaoItem;
use App\Models\RotaProducao;
use App\Models\SetorProducao;
use App\Models\User;
use App\Notifications\Cozinha\ProducaoProntaNotification;
use App\Services\Cozinha\ProducaoService;
use App\Services\Garcom\MapaMesasService;
use Database\Seeders\FichasTecnicasExemploSeeder;
use Database\Seeders\SetoresProducaoSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** Fase 5: garçom (aviso e chips), seeders e quantidade de queries das telas. */
class CozinhaFase5Test extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    public function test_rodada_despachada_pela_expedicao_avisa_o_garcom_e_mostra_os_chips(): void
    {
        $garcom = User::factory()->garcom()->create(['name_first' => 'Beto']);
        $this->actingAs($garcom);
        $pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $pizzaria);
        $pedido = $this->pedidoMesa($garcom);
        $this->item($pedido, $this->produto('Calabresa', $pizzas));
        $this->abrir($pedido);

        Filament::setCurrentPanel('garcom');
        Livewire::test(AtenderMesa::class, ['sessao' => $pedido->pedido_sessao_mesa_id])
            ->set('aba', 'rodadas')
            ->assertSee('Na fila');

        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $producao = app(ProducaoService::class);
        $item = ProducaoItem::sole();
        $producao->concluir($producao->iniciar($item, $this->operador($gerente)), $this->operador($gerente));
        $this->assertSame([], app(MapaMesasService::class)->prontasDoGarcom($garcom->id), 'Com expedição, pronto na cozinha ainda não é do garçom.');

        $producao->expedirPedido($pedido, $gerente);

        $this->assertSame([$pedido->id], array_column(app(MapaMesasService::class)->prontasDoGarcom($garcom->id), 'id'));
        $this->assertSame(1, $garcom->notifications()->where('type', ProducaoProntaNotification::class)->count());
        $this->assertTrue(Filament::getPanel('garcom')->hasDatabaseNotifications());

        Livewire::test(AtenderMesa::class, ['sessao' => $pedido->pedido_sessao_mesa_id])
            ->set('aba', 'rodadas')
            ->assertSee('Entregue');

        $this->travel(MapaMesasService::MINUTOS_DESPACHO_RECENTE + 1)->minutes();
        $this->assertSame([], app(MapaMesasService::class)->prontasDoGarcom($garcom->id));
    }

    public function test_seeder_de_setores_cria_padrao_e_rotas_e_e_idempotente(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->categoria('Pizza Grande', $pizzas);
        $drink = $this->categoria('Drink');
        $pastel = $this->categoria('Pastel');
        $this->categoria('Refrigerante');

        $this->seed(SetoresProducaoSeeder::class);
        $this->seed(SetoresProducaoSeeder::class);

        $this->assertSame(['pizzaria', 'cozinha', 'bar', 'expedicao'], SetorProducao::ordenados()->pluck('slug')->all());
        $this->assertSame(1, SetorProducao::where('tipo', TipoSetorProducao::Expedicao)->count());
        $rotas = RotaProducao::with('setor', 'categoria')->get()->mapWithKeys(fn ($r) => [$r->categoria->categoria_nome => $r->setor->slug])->all();
        $this->assertSame(['Pizzas' => 'pizzaria', 'Pastel' => 'cozinha', 'Drink' => 'bar'], $rotas);
        $this->assertSame(1, $drink->rotasProducao()->count());
        $this->assertSame(1, $pastel->rotasProducao()->count());
    }

    public function test_seeder_de_setores_nao_duplica_setores_vindos_das_linhas_de_producao(): void
    {
        SetorProducao::factory()->create(['nome' => 'Cozinha Pizzas', 'slug' => 'cozinha-pizzas']);

        $this->seed(SetoresProducaoSeeder::class);

        $this->assertSame(['cozinha-pizzas', 'expedicao'], SetorProducao::orderBy('id')->pluck('slug')->all());
        $this->assertSame(0, RotaProducao::count());
    }

    public function test_seeder_de_fichas_cria_exemplo_e_preserva_a_ficha_de_custo_existente(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria', 'slug' => 'pizzaria']);
        $grande = $this->categoria('Pizza Grande');
        $calabresa = $this->produto('Calabresa', $grande);
        $portuguesa = $this->produto('Portuguesa', $grande);
        $mussarela = $this->produto('Mussarela', $this->categoria('Laticínios'), ['produto_tipo' => ProdutoTipoEnum::INSUMO->value]);
        $custoPortuguesa = FichaTecnicaItem::factory()->create(['fti_produto_id' => $portuguesa->id, 'fti_insumo_id' => $mussarela->id, 'fti_quantidade' => 0.3]);

        $this->seed(FichasTecnicasExemploSeeder::class);
        $this->seed(FichasTecnicasExemploSeeder::class);

        $ficha = FichaTecnica::where('produto_id', $calabresa->id)->sole();
        $this->assertCount(8, $ficha->passos);
        $this->assertSame($pizzaria->id, $ficha->passos->first()->setor_producao_id);
        $ingredientes = $ficha->ingredientes()->with('insumo')->get();
        $this->assertSame(['Massa G', 'Molho de tomate', 'Mussarela', 'Calabresa fatiada', 'Cebola', 'Azeitona', 'Orégano'], $ingredientes->pluck('insumo.produto_descricao')->all());
        $this->assertSame($mussarela->id, $ingredientes[2]->fti_insumo_id, 'Reaproveita o insumo que já existe.');
        $this->assertSame('cebola', $ingredientes[4]->fti_opcional_ref);
        $this->assertTrue(FichaTecnicaItem::where('fti_produto_id', $ingredientes[0]->fti_insumo_id)->exists(), 'Massa G é subficha.');

        $this->assertSame([$custoPortuguesa->id], FichaTecnicaItem::where('fti_produto_id', $portuguesa->id)->pluck('id')->all());
        $this->assertSame(EscopoIngrediente::Base, $custoPortuguesa->fresh()->fti_escopo);
        $this->assertEquals(0.3, (float) $custoPortuguesa->fresh()->fti_quantidade);
        $this->assertSame(2, FichaTecnica::count(), 'Marguerita não existe: pulada.');
    }

    public function test_estacao_e_expedicao_com_50_itens_nao_fazem_n_mais_1(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $this->actingAs($gerente);
        $pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $pizzaria);
        $produto = $this->produto('Calabresa', $pizzas);

        $contar = function (int $pedidos) use ($produto): array {
            foreach (range(1, $pedidos) as $_) {
                $pedido = $this->pedidoMesa();
                $this->item($pedido, $produto, 5);
                $this->abrir($pedido);
            }

            Filament::setCurrentPanel('cozinha');
            $resultado = [];

            foreach (['estacao' => Estacao::class, 'expedicao' => Expedicao::class] as $nome => $pagina) {
                Livewire::test($pagina); // aquece o cache de permissões: conta só o que a tela faz
                DB::flushQueryLog();
                DB::enableQueryLog();
                Livewire::test($pagina);
                $resultado[$nome] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }

            return $resultado;
        };

        $com2 = $contar(2);
        $com10 = $contar(8); // 10 pedidos × 5 pizzas

        $this->assertSame(50, ProducaoItem::count());
        $this->assertSame($com2['estacao'], $com10['estacao'], 'A Estação não pode crescer com o número de itens.');
        $this->assertSame($com2['expedicao'], $com10['expedicao'], 'A Expedição não pode crescer com o número de pedidos.');
    }
}
