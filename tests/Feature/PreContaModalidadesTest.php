<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\SessaoMesaService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Modalidades da pré-conta de mesa: por pessoa (padrão), itens agrupados,
 * por rodada e comanda individual.
 */
class PreContaModalidadesTest extends TestCase
{
    use RefreshDatabase;

    private SessaoMesa $sessao;

    private User $garcom;

    private Produto $coca;

    private Produto $pizza;

    private Cliente $joana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        config(['pizzaria.salao.taxa_servico_percentual' => 10, 'pizzaria.salao.taxa_servico_padrao_ligada' => true]);

        $this->garcom = User::factory()->garcom()->create(['name_first' => 'Ana']);
        $this->actingAs($this->garcom);
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 9', 'mesa_status' => 'LIBERADA']);
        $this->sessao = app(SessaoMesaService::class)->abrir($mesa->id, $this->garcom->id);
        $categoria = Categoria::create(['categoria_nome' => 'Cardápio']);
        $this->coca = Produto::create(['produto_descricao' => 'Coca-Cola', 'produto_categoria_id' => $categoria->id, 'produto_tipo' => ProdutoTipoEnum::REVENDA->value, 'produto_preco_venda' => 14]);
        $this->pizza = Produto::create(['produto_descricao' => 'Calabresa', 'produto_categoria_id' => $categoria->id, 'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value, 'produto_preco_venda' => 50]);
        $this->joana = app(AtendimentoMesaService::class)->adicionarClienteNaMesa($this->sessao, null, 'Joana', semCadastro: true);

        // Rodada 1 (19:10): Coca da Joana + Pizza da mesa. Rodada 2 (19:40): outra Coca da Joana.
        Carbon::setTestNow('2026-10-02 19:10:00');
        $this->rodada([[$this->coca, $this->joana->id], [$this->pizza, null]]);
        Carbon::setTestNow('2026-10-02 19:40:00');
        $this->rodada([[$this->coca, $this->joana->id]]);
        Carbon::setTestNow();
    }

    /** @param  array<int, array{0: Produto, 1: ?int}>  $itens */
    private function rodada(array $itens): Pedido
    {
        $servico = app(AtendimentoMesaService::class);
        $rascunho = $servico->rascunho($this->sessao, $this->garcom);

        foreach ($itens as [$produto, $clienteId]) {
            ItensPedido::create([
                'item_pedido_pedido_id' => $rascunho->id,
                'item_pedido_produto_id' => $produto->id,
                'item_pedido_cliente_id' => $clienteId,
                'item_pedido_quantidade' => 1,
                'item_pedido_valor_unitario' => $produto->produto_preco_venda,
                'item_pedido_valor' => $produto->produto_preco_venda,
                'item_pedido_desconto' => 0,
                'item_pedido_status' => 'INSERIDO',
            ]);
        }

        $servico->enviarRodada($rascunho);

        return $rascunho->fresh();
    }

    private function url(array $parametros = []): string
    {
        return route('sessaoMesa.imprimir', ['id' => $this->sessao->id, ...$parametros]);
    }

    public function test_padrao_separa_por_pessoa_com_taxa_no_total(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSeeInOrder(['Joana', 'Subtotal Joana', 'R$ 28,00', 'Mesa geral', 'R$ 50,00'])
            ->assertSee('Taxa de Serviço')
            ->assertSee('R$ 85,80');
    }

    public function test_agrupado_soma_itens_iguais_de_rodadas_diferentes(): void
    {
        $this->get($this->url(['modo' => 'agrupado']))
            ->assertOk()
            ->assertSee('Pré-conta (itens agrupados)')
            ->assertSeeInOrder(['2', 'Cardápio', 'Coca-Cola', 'R$ 28,00'])
            ->assertDontSee('Subtotal');
    }

    public function test_agrupado_nao_junta_o_mesmo_produto_de_categorias_diferentes(): void
    {
        $broto = Categoria::create(['categoria_nome' => 'Pizza Broto']);
        $calabresaBroto = Produto::create(['produto_descricao' => 'Calabresa', 'produto_categoria_id' => $broto->id, 'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value, 'produto_preco_venda' => 30]);
        $this->rodada([[$calabresaBroto, null]]);

        $this->get($this->url(['modo' => 'agrupado']))
            ->assertOk()
            ->assertSeeInOrder(['Cardápio', 'Calabresa', 'R$ 50,00'])
            ->assertSeeInOrder(['Pizza Broto', 'Calabresa', 'R$ 30,00']);
    }

    public function test_por_rodada_mostra_data_hora_e_garcom(): void
    {
        $this->get($this->url(['modo' => 'rodada']))
            ->assertOk()
            ->assertSeeInOrder(['Rodada #', '02/10 19:10 · Ana', 'Calabresa', 'Rodada #', '02/10 19:40 · Ana']);
    }

    public function test_comanda_individual_so_com_os_itens_da_pessoa(): void
    {
        $this->get($this->url(['cliente' => $this->joana->id]))
            ->assertOk()
            ->assertSee('Comanda de Joana')
            ->assertDontSee('Calabresa')
            ->assertSee('R$ 2,80')
            ->assertSee('R$ 30,80');

        $this->get($this->url(['cliente' => 0]))
            ->assertOk()
            ->assertSee('Mesa geral')
            ->assertSee('Calabresa')
            ->assertDontSee('Coca-Cola');
    }
}
