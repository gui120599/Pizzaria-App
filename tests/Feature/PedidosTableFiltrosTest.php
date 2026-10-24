<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\SessaoMesaService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Filtros da lista de pedidos: cada um isolado, combinados, e a lista inteira
 * (sem nenhum filtro) sem zerar.
 */
class PedidosTableFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $beto;

    private User $entregador;

    private OpcoesEntregas $delivery;

    private OpcoesEntregas $balcao;

    private OpcoesPagamento $cartao;

    private OpcoesPagamento $pix;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow('2026-10-24 12:00:00');

        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $gerente->givePermissionTo([Permission::findOrCreate('view_any:pedido'), Permission::findOrCreate('view:pedido')]);
        $this->actingAs($gerente);

        $this->ana = User::factory()->create(['name_first' => 'Ana']);
        $this->beto = User::factory()->create(['name_first' => 'Beto']);
        $this->entregador = User::factory()->create(['name_first' => 'Edu']);

        $this->delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_requer_endereco' => true]);
        $this->balcao = OpcoesEntregas::create(['opcaoentrega_nome' => 'Balcão', 'opcaoentrega_requer_endereco' => false]);
        $this->cartao = OpcoesPagamento::create(['opcaopag_nome' => 'Cartão Stone', 'opcaopag_desc_nfe' => 'creditCard']);
        $this->pix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_desc_nfe' => 'InstantPayment']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pedido(string $status, array $extra = []): Pedido
    {
        return Pedido::create(array_merge(
            ['pedido_status' => $status, 'pedido_valor_total' => 40.0, 'pedido_datahora_abertura' => now()->subHour()],
            $extra,
        ));
    }

    private function vendaPaga(OpcoesPagamento $forma): Venda
    {
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 40.0]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $forma->id,
            'pg_venda_valor_pagamento' => 40.0,
            'pg_venda_valor_pago_pelo_cliente' => 40.0,
            'pg_venda_valor_troco' => 0,
        ]);

        return $venda;
    }

    private function item(Pedido $pedido, array $extra = []): ItensPedido
    {
        return ItensPedido::create(array_merge([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40.0,
            'item_pedido_valor' => 40.0,
            'item_pedido_status' => 'INSERIDO',
        ], $extra));
    }

    public function test_sem_nenhum_filtro_a_lista_nao_zera(): void
    {
        $a = $this->pedido('ABERTO');
        $b = $this->pedido('CANCELADO');

        Livewire::test(ListPedidos::class)->assertCanSeeTableRecords([$a, $b]);
    }

    public function test_filtros_de_atendimento(): void
    {
        $a = $this->pedido('ABERTO', ['pedido_origem' => PedidoOrigemEnum::ATENDENTE, 'pedido_usuario_garcom_id' => $this->ana->id, 'pedido_opcaoentrega_id' => $this->delivery->id]);
        $b = $this->pedido('PREPARANDO', ['pedido_origem' => PedidoOrigemEnum::CARDAPIO, 'pedido_usuario_garcom_id' => $this->beto->id, 'pedido_usuario_entrega_id' => $this->entregador->id, 'pedido_opcaoentrega_id' => $this->balcao->id]);
        $sessao = app(SessaoMesaService::class)->abrir(Mesa::create(['mesa_nome' => 'Mesa 7', 'mesa_status' => 'LIBERADA'])->id, $this->ana->id, taxaServicoPercentual: 0.0);
        $c = $this->pedido('ENTREGUE', ['pedido_sessao_mesa_id' => $sessao->id]);

        $tabela = Livewire::test(ListPedidos::class)->assertCanSeeTableRecords([$a, $b, $c]);

        $tabela->filterTable('status', ['ABERTO', 'ENTREGUE'])->assertCanSeeTableRecords([$a, $c])->assertCanNotSeeTableRecords([$b])->removeTableFilter('status');
        $tabela->filterTable('tipo_atendimento', ['delivery'])->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c])->removeTableFilter('tipo_atendimento');
        $tabela->filterTable('tipo_atendimento', ['retirada', 'mesa'])->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a])->removeTableFilter('tipo_atendimento');
        $tabela->filterTable('origem', PedidoOrigemEnum::CARDAPIO->value)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])->removeTableFilter('origem');
        $tabela->filterTable('opcao_entrega', $this->delivery->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b, $c])->removeTableFilter('opcao_entrega');
        $tabela->filterTable('atendente', $this->beto->id)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])->removeTableFilter('atendente');
        $tabela->filterTable('entregador', $this->entregador->id)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])->removeTableFilter('entregador');
        $tabela->filterTable('mesa', $sessao->sessao_mesa_mesa_id)->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b])->removeTableFilter('mesa');
    }

    public function test_forma_de_pagamento_e_a_da_venda_do_pedido_ou_dos_itens(): void
    {
        $viaVenda = $this->pedido('FINALIZADO', ['pedido_venda_id' => $this->vendaPaga($this->cartao)->id]);
        $viaItem = $this->pedido('ENTREGUE');
        $this->item($viaItem, ['item_pedido_venda_id' => $this->vendaPaga($this->pix)->id]);
        $semVenda = $this->pedido('ABERTO');

        Livewire::test(ListPedidos::class)
            ->filterTable('forma_pagamento', [$this->cartao->id])
            ->assertCanSeeTableRecords([$viaVenda])
            ->assertCanNotSeeTableRecords([$viaItem, $semVenda])
            ->filterTable('forma_pagamento', [$this->pix->id])
            ->assertCanSeeTableRecords([$viaItem])
            ->assertCanNotSeeTableRecords([$viaVenda, $semVenda])
            ->filterTable('forma_pagamento', [$this->pix->id, $this->cartao->id])
            ->assertCanSeeTableRecords([$viaVenda, $viaItem])
            ->assertCanNotSeeTableRecords([$semVenda]);
    }

    public function test_filtros_de_excecao(): void
    {
        $comDesconto = $this->pedido('ABERTO', ['pedido_valor_desconto' => 5.0]);
        $semDesconto = $this->pedido('ABERTO');
        $comCortesia = $this->pedido('ABERTO');
        $this->item($comCortesia, ['item_pedido_cortesia' => true]);
        $cortesiaRemovida = $this->pedido('ABERTO');
        $this->item($cortesiaRemovida, ['item_pedido_cortesia' => true, 'item_pedido_status' => 'REMOVIDO']);
        $cancelado = $this->pedido('CANCELADO', ['pedido_motivo_cancelamento' => MotivoCancelamentoEnum::DEMORA->value]);
        $canceladoOutro = $this->pedido('CANCELADO', ['pedido_motivo_cancelamento' => MotivoCancelamentoEnum::DUPLICADO->value]);

        $tabela = Livewire::test(ListPedidos::class);

        $tabela->filterTable('com_desconto', true)->assertCanSeeTableRecords([$comDesconto])->assertCanNotSeeTableRecords([$semDesconto, $comCortesia])->removeTableFilter('com_desconto');
        $tabela->filterTable('com_cortesia', true)->assertCanSeeTableRecords([$comCortesia])->assertCanNotSeeTableRecords([$semDesconto, $cortesiaRemovida])->removeTableFilter('com_cortesia');
        $tabela->filterTable('motivo_cancelamento', MotivoCancelamentoEnum::DEMORA->value)->assertCanSeeTableRecords([$cancelado])->assertCanNotSeeTableRecords([$canceladoOutro, $semDesconto])->removeTableFilter('motivo_cancelamento');
    }

    public function test_filtros_combinam_e_o_periodo_respeita_a_data_de_abertura(): void
    {
        $alvo = $this->pedido('ABERTO', ['pedido_usuario_garcom_id' => $this->ana->id, 'pedido_datahora_abertura' => now()->subDays(2)]);
        $outraAtendente = $this->pedido('ABERTO', ['pedido_usuario_garcom_id' => $this->beto->id, 'pedido_datahora_abertura' => now()->subDays(2)]);
        $outroDia = $this->pedido('ABERTO', ['pedido_usuario_garcom_id' => $this->ana->id, 'pedido_datahora_abertura' => now()->subDays(5)]);

        Livewire::test(ListPedidos::class)
            ->filterTable('atendente', $this->ana->id)
            ->filterTable('periodo', ['de' => now()->subDays(3)->toDateString(), 'ate' => now()->toDateString()])
            ->assertCanSeeTableRecords([$alvo])
            ->assertCanNotSeeTableRecords([$outraAtendente, $outroDia]);
    }
}
