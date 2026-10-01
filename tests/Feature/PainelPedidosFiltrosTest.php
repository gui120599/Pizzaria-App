<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Pages\PainelPedidos;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\LinhaProducao;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Os filtros trazidos da Central de Pedidos do RazelFood: busca, tipo de
 * atendimento, só atrasados, cancelados do turno, e persistência por sessão.
 */
class PainelPedidosFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        Carbon::setTestNow('2026-09-30 20:00:00');

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function gerente(): User
    {
        $user = User::factory()->create(['name_first' => 'Gerente']);
        $user->assignRole('Gerente');
        $user->givePermissionTo(Permission::firstOrCreate([
            'name' => 'view_any:pedido',
            'guard_name' => 'web',
        ]));

        return $user;
    }

    /** @return array<string, mixed> */
    private function pedido(StatusPedidoEnum $status, array $extras = []): Pedido
    {
        $pedido = Pedido::create($extras + [
            'pedido_status' => $status->value,
            'pedido_datahora_abertura' => Carbon::now(),
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40,
            'item_pedido_valor' => 40,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_busca_por_numero_do_pedido(): void
    {
        $this->actingAs($this->gerente());

        $alvo = $this->pedido(StatusPedidoEnum::ABERTO);
        $this->pedido(StatusPedidoEnum::ABERTO);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('busca', (string) $alvo->id)
            ->get('colunas');

        $this->assertSame([$alvo->id], $colunas['ABERTO']->pluck('id')->all());
    }

    public function test_busca_por_nome_do_cliente(): void
    {
        $this->actingAs($this->gerente());

        $cliente = Cliente::create(['cliente_nome' => 'Fernanda Souza', 'cliente_celular' => '11999990000', 'cliente_tipo' => 'Física']);
        $alvo = $this->pedido(StatusPedidoEnum::ABERTO, ['pedido_cliente_id' => $cliente->id]);
        $this->pedido(StatusPedidoEnum::ABERTO);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('busca', 'Fernanda')
            ->get('colunas');

        $this->assertSame([$alvo->id], $colunas['ABERTO']->pluck('id')->all());
    }

    public function test_busca_por_celular_do_cliente(): void
    {
        $this->actingAs($this->gerente());

        $cliente = Cliente::create(['cliente_nome' => 'Fernanda Souza', 'cliente_celular' => '11999990000', 'cliente_tipo' => 'Física']);
        $alvo = $this->pedido(StatusPedidoEnum::ABERTO, ['pedido_cliente_id' => $cliente->id]);
        $this->pedido(StatusPedidoEnum::ABERTO);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('busca', '999990000')
            ->get('colunas');

        $this->assertSame([$alvo->id], $colunas['ABERTO']->pluck('id')->all());
    }

    public function test_filtro_rapido_de_etapa_unica_esvazia_as_demais_colunas(): void
    {
        $this->actingAs($this->gerente());

        $this->pedido(StatusPedidoEnum::ABERTO);
        $this->pedido(StatusPedidoEnum::PREPARANDO);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('filtroRapido', 'preparando')
            ->get('colunas');

        $this->assertCount(0, $colunas['ABERTO']);
        $this->assertCount(1, $colunas['PREPARANDO']);
    }

    public function test_filtro_de_tipo_mesa(): void
    {
        $this->actingAs($this->gerente());

        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1']);
        $sessao = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_status' => 'ABERTA',
            'sessao_mesa_usuario_id' => $this->gerente()->id,
        ]);

        $deMesa = $this->pedido(StatusPedidoEnum::ABERTO, ['pedido_sessao_mesa_id' => $sessao->id]);
        $this->pedido(StatusPedidoEnum::ABERTO);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('filtroRapido', 'mesa')
            ->get('colunas');

        $this->assertSame([$deMesa->id], $colunas['ABERTO']->pluck('id')->all());
    }

    public function test_filtro_de_tipo_delivery(): void
    {
        $this->actingAs($this->gerente());

        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_requer_endereco' => true]);
        $balcao = OpcoesEntregas::create(['opcaoentrega_nome' => 'Balcão', 'opcaoentrega_requer_endereco' => false]);

        $deDelivery = $this->pedido(StatusPedidoEnum::ABERTO, ['pedido_opcaoentrega_id' => $delivery->id]);
        $this->pedido(StatusPedidoEnum::ABERTO, ['pedido_opcaoentrega_id' => $balcao->id]);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('filtroRapido', 'delivery')
            ->get('colunas');

        $this->assertSame([$deDelivery->id], $colunas['ABERTO']->pluck('id')->all());
    }

    public function test_filtro_de_tipo_retirada_inclui_pedido_sem_opcao_de_entrega(): void
    {
        $this->actingAs($this->gerente());

        $delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_requer_endereco' => true]);

        $semOpcao = $this->pedido(StatusPedidoEnum::ABERTO);
        $this->pedido(StatusPedidoEnum::ABERTO, ['pedido_opcaoentrega_id' => $delivery->id]);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('filtroRapido', 'retirada')
            ->get('colunas');

        $this->assertSame([$semOpcao->id], $colunas['ABERTO']->pluck('id')->all());
    }

    public function test_somente_atrasados_filtra_pelos_limites_de_sla(): void
    {
        $this->actingAs($this->gerente());

        config(['pizzaria.pedidos.sla' => [
            'ABERTO' => ['atencao' => 5, 'atrasado' => 10],
        ]]);

        $noPrazo = $this->pedido(StatusPedidoEnum::ABERTO, [
            'pedido_datahora_abertura' => Carbon::now()->copy()->subMinutes(2),
        ]);

        $atrasado = $this->pedido(StatusPedidoEnum::ABERTO, [
            'pedido_datahora_abertura' => Carbon::now()->copy()->subMinutes(15),
        ]);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('somenteAtrasados', true)
            ->get('colunas');

        $this->assertSame([$atrasado->id], $colunas['ABERTO']->pluck('id')->all());
    }

    public function test_cancelados_do_turno_ficam_ocultos_por_padrao_e_mostram_o_motivo(): void
    {
        $ator = $this->gerente();
        $this->actingAs($ator);

        $cancelado = $this->pedido(StatusPedidoEnum::CANCELADO, [
            'pedido_datahora_cancelado' => Carbon::now(),
            'pedido_motivo_cancelamento' => MotivoCancelamentoEnum::PRODUTO_INDISPONIVEL->value,
            'pedido_usuario_cancelou_id' => $ator->id,
        ]);

        $componente = Livewire::test(PainelPedidos::class);

        $this->assertCount(0, $componente->get('cancelados'));

        $componente->set('mostrarCancelados', true);

        $cancelados = $componente->get('cancelados');

        $this->assertSame([$cancelado->id], $cancelados->pluck('id')->all());
        $this->assertSame(
            MotivoCancelamentoEnum::PRODUTO_INDISPONIVEL,
            $cancelados->first()->pedido_motivo_cancelamento,
        );
        $this->assertSame($ator->id, $cancelados->first()->usuarioCancelou->id);
    }

    public function test_filtros_sobrevivem_a_remontar_o_componente(): void
    {
        $this->actingAs($this->gerente());

        Livewire::test(PainelPedidos::class)
            ->set('filtroRapido', 'delivery')
            ->set('busca', 'oi')
            ->set('somenteAtrasados', true);

        // Uma nova instância do componente (equivalente a um F5) deve
        // restaurar os valores gravados na sessão pelo #[Session].
        $remontado = Livewire::test(PainelPedidos::class);

        $this->assertSame('delivery', $remontado->get('filtroRapido'));
        $this->assertSame('oi', $remontado->get('busca'));
        $this->assertTrue($remontado->get('somenteAtrasados'));
    }

    /**
     * A vinculação com a querystring (?linha=) é mecanismo do próprio
     * Livewire (#[Url]); aqui testamos o filtro em si, setando a propriedade
     * diretamente — é o que ->set() também faria se viesse da URL.
     */
    public function test_linha_de_producao_filtra_pedido_com_item_da_linha(): void
    {
        $this->actingAs($this->gerente());

        $linha = LinhaProducao::create(['linha_nome' => 'Forno']);
        $linha->categorias()->attach($this->produto->produto_categoria_id);

        $daLinha = $this->pedido(StatusPedidoEnum::ABERTO);

        $outraCategoria = Categoria::create(['categoria_nome' => 'Bebidas']);
        $outroProduto = Produto::create([
            'produto_descricao' => 'Refrigerante',
            'produto_categoria_id' => $outraCategoria->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
        ]);

        $foraDaLinha = Pedido::create([
            'pedido_status' => StatusPedidoEnum::ABERTO->value,
            'pedido_datahora_abertura' => Carbon::now(),
        ]);
        ItensPedido::create([
            'item_pedido_pedido_id' => $foraDaLinha->id,
            'item_pedido_produto_id' => $outroProduto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 10,
            'item_pedido_valor' => 10,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('linhaProducaoId', $linha->id)
            ->get('colunas');

        $this->assertSame([$daLinha->id], $colunas['ABERTO']->pluck('id')->all());
    }

    public function test_entregador_so_filtra_quando_a_operacao_atribui_entregador(): void
    {
        $this->actingAs($this->gerente());

        config(['pizzaria.pedidos.atribui_entregador' => false]);

        $entregador = User::factory()->create(['name_first' => 'Motoboy']);
        $entregador->assignRole('Entregador');

        $doEntregador = $this->pedido(StatusPedidoEnum::EM_TRANSPORTE, [
            'pedido_usuario_entrega_id' => $entregador->id,
        ]);
        $this->pedido(StatusPedidoEnum::EM_TRANSPORTE);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('entregadorId', $entregador->id)
            ->get('colunas');

        // Com atribui_entregador desligado, o filtro é ignorado: as duas
        // colunas aparecem.
        $this->assertCount(2, $colunas['EM TRANSPORTE']);

        config(['pizzaria.pedidos.atribui_entregador' => true]);

        $colunas = Livewire::test(PainelPedidos::class)
            ->set('entregadorId', $entregador->id)
            ->get('colunas');

        $this->assertSame([$doEntregador->id], $colunas['EM TRANSPORTE']->pluck('id')->all());
    }
}
