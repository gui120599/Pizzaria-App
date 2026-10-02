<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Garcom\Pages\AtenderMesa;
use App\Livewire\ClientePicker;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\SessaoMesaCliente;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\SessaoMesaService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientesDaMesaGarcomTest extends TestCase
{
    use RefreshDatabase;

    private AtendimentoMesaService $service;

    private User $garcom;

    private SessaoMesa $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('garcom'));

        $this->service = app(AtendimentoMesaService::class);
        $this->garcom = User::factory()->garcom()->create(['name_first' => 'Ana']);
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 5', 'mesa_status' => 'LIBERADA']);
        $this->sessao = app(SessaoMesaService::class)->abrir($mesa->id, $this->garcom->id);
    }

    public function test_vincula_existente_cadastra_novo_e_cria_nome_livre(): void
    {
        $existente = Cliente::create(['cliente_nome' => 'Marcos', 'cliente_celular' => '64991112222', 'cliente_tipo' => 'Física']);

        $this->service->adicionarClienteNaMesa($this->sessao, $existente->id);
        $novo = $this->service->adicionarClienteNaMesa($this->sessao, null, 'Joana', '(64) 99333-4444');
        $livre = $this->service->adicionarClienteNaMesa($this->sessao, null, 'Camisa azul', semCadastro: true);

        $this->assertSame(3, SessaoMesaCliente::where('smc_sessao_mesa_id', $this->sessao->id)->count());
        $this->assertSame('64993334444', $novo->cliente_celular);
        $this->assertFalse($novo->fresh()->cliente_avulso);
        $this->assertTrue($livre->cliente_avulso);
        $this->assertNull($livre->cliente_celular);

        // Repetir a mesma pessoa não duplica o vínculo.
        $this->service->adicionarClienteNaMesa($this->sessao, $existente->id);
        $this->assertSame(3, SessaoMesaCliente::where('smc_sessao_mesa_id', $this->sessao->id)->count());
    }

    public function test_nome_livre_fica_fora_da_busca_de_clientes(): void
    {
        $this->service->adicionarClienteNaMesa($this->sessao, null, 'Camisa azul', semCadastro: true);
        Cliente::create(['cliente_nome' => 'Camisa Verde Ltda', 'cliente_tipo' => 'Física']);

        $resultados = Livewire::test(ClientePicker::class)
            ->set('buscaQuery', 'Camisa')
            ->instance()
            ->resultadosBusca();

        $this->assertSame(['Camisa Verde Ltda'], $resultados->pluck('cliente_nome')->all());
    }

    public function test_seletor_lanca_para_a_pessoa_padrao_e_volta_a_ela(): void
    {
        $joana = $this->service->adicionarClienteNaMesa($this->sessao, null, 'Joana', semCadastro: true);
        $categoria = Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio_garcom' => true]);
        $coca = Produto::create([
            'produto_descricao' => 'Coca-Cola',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            'produto_preco_venda' => 14,
        ]);
        $rascunho = $this->service->rascunho($this->sessao, $this->garcom);

        Livewire::test(PedidoProdutoSelector::class, [
            'pedidoId' => $rascunho->id,
            'sessaoMesaClientes' => [['id' => $joana->id, 'nome' => 'Joana']],
            'clientePadraoId' => $joana->id,
        ])
            ->assertSet('clienteSelecionadoId', $joana->id)
            ->call('selecionarProduto', $coca->id)
            ->call('confirmarItem')
            ->assertSet('clienteSelecionadoId', $joana->id);

        $this->assertSame($joana->id, ItensPedido::sole()->item_pedido_cliente_id);
    }

    public function test_pessoa_com_itens_nao_sai_da_mesa(): void
    {
        $joana = $this->service->adicionarClienteNaMesa($this->sessao, null, 'Joana', semCadastro: true);
        $rascunho = $this->service->rascunho($this->sessao, $this->garcom);
        ItensPedido::create([
            'item_pedido_pedido_id' => $rascunho->id,
            'item_pedido_produto_id' => Produto::create([
                'produto_descricao' => 'Água',
                'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas'])->id,
                'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
                'produto_preco_venda' => 5,
            ])->id,
            'item_pedido_cliente_id' => $joana->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 5,
            'item_pedido_valor' => 5,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $this->expectExceptionMessage('já tem itens');

        $this->service->removerClienteDaMesa($this->sessao, $joana->id);
    }

    public function test_tela_adiciona_pessoa_por_nome_livre_e_passa_a_lancar_para_ela(): void
    {
        $this->actingAs($this->garcom);

        $tela = Livewire::test(AtenderMesa::class, ['sessao' => $this->sessao->id])
            ->callAction('adicionarPessoa', ['modo' => 'livre', 'nome' => 'Moça do vestido'])
            ->assertHasNoActionErrors()
            ->assertSee('Moça do vestido');

        $cliente = Cliente::where('cliente_nome', 'Moça do vestido')->sole();
        $this->assertTrue($cliente->cliente_avulso);
        $tela->assertSet('clientePadraoId', $cliente->id);
    }
}
