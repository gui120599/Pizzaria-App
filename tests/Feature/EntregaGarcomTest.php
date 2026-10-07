<?php

namespace Tests\Feature;

use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Garcom\Pages\AtenderRetirada;
use App\Filament\Garcom\Pages\MapaMesas;
use App\Livewire\EntregaPagamentoPicker;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Services\Garcom\RetiradaService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class EntregaGarcomTest extends TestCase
{
    use RefreshDatabase;

    private RetiradaService $service;

    private User $garcom;

    private OpcoesEntregas $delivery;

    private OpcoesPagamento $dinheiro;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('garcom'));

        $this->service = app(RetiradaService::class);
        $this->garcom = User::factory()->garcom()->create(['name_first' => 'Ana']);
        $this->delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_valor_frete' => 8, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => true]);
        OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
        $this->dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash', 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0]);
    }

    private function rascunhoComItem(float $valor = 50.00): Pedido
    {
        $rascunho = $this->service->rascunho($this->garcom);

        ItensPedido::create([
            'item_pedido_pedido_id' => $rascunho->id,
            'item_pedido_produto_id' => Produto::create([
                'produto_descricao' => 'Calabresa',
                'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
                'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
                'produto_preco_venda' => $valor,
            ])->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $valor,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $rascunho;
    }

    /** @return array<string, mixed> */
    private function cliente(array $sobrescrever = []): array
    {
        return array_merge([
            'nome' => 'Paula Reis',
            'celular' => '(64) 99876-5432',
            'enderecoRua' => 'Rua das Flores',
            'enderecoNumero' => '120',
            'enderecoBairro' => 'Centro',
            'enderecoCidade' => 'Rio Verde',
        ], $sobrescrever);
    }

    /** @return array<string, mixed> */
    private function entregaPagamento(string $valor = '58,00', ?string $trocoPara = '100,00'): array
    {
        return [
            'opcaoEntregaId' => $this->delivery->id,
            'pagamentos' => [['opcaoPagamentoId' => $this->dinheiro->id, 'valor' => $valor, 'trocoPara' => $trocoPara]],
        ];
    }

    public function test_entrega_exige_endereco(): void
    {
        $rascunho = $this->rascunhoComItem();

        $this->expectExceptionMessage('rua e bairro');

        $this->service->enviarEntrega($rascunho, $this->cliente(['enderecoRua' => '']), $this->entregaPagamento());
    }

    public function test_pagamento_precisa_bater_com_o_total_com_frete(): void
    {
        $rascunho = $this->rascunhoComItem();

        try {
            $this->service->enviarEntrega($rascunho, $this->cliente(), $this->entregaPagamento('50,00'));
            $this->fail('Soma dos pagamentos sem o frete deveria ser recusada.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('R$ 58,00', $e->getMessage());
        }

        $this->assertSame(StatusPedidoEnum::INICIADO->value, $rascunho->fresh()->pedido_status);
    }

    public function test_entrega_enviada_grava_endereco_frete_e_forma_combinada(): void
    {
        $rascunho = $this->rascunhoComItem();

        $this->assertTrue($this->service->enviarEntrega($rascunho, $this->cliente(), $this->entregaPagamento()));
        $this->assertFalse($this->service->enviarEntrega($rascunho, $this->cliente(), $this->entregaPagamento()));

        $pedido = $rascunho->fresh(['opcaoEntrega', 'cliente']);
        $this->assertSame(StatusPedidoEnum::ABERTO->value, $pedido->pedido_status);
        $this->assertSame(PedidoOrigemEnum::GARCOM, $pedido->pedido_origem);
        $this->assertTrue($pedido->exigeEntrega());
        $this->assertEquals(8.00, $pedido->pedido_valor_frete);
        $this->assertEquals(58.00, $pedido->pedido_valor_total);
        $this->assertSame('Rua das Flores, 120, Centro, Rio Verde', $pedido->pedido_endereco_entrega);
        $this->assertSame('Rua das Flores', $pedido->cliente->cliente_endereco);
        $this->assertDatabaseHas(PagamentosPedido::class, [
            'pg_pedido_pedido_id' => $pedido->id,
            'pg_pedido_opcaopagamento_id' => $this->dinheiro->id,
            'pg_pedido_valor' => 58.00,
            'pg_pedido_valor_troco_para' => 100.00,
        ]);
    }

    public function test_entrega_do_garcom_so_lista_opcoes_com_endereco(): void
    {
        Livewire::test(EntregaPagamentoPicker::class, ['somenteComEndereco' => true])
            ->assertSee('Delivery')
            ->assertDontSee('Retirada');

        // Fora do garçom (AtenderPedido) continua listando todas.
        Livewire::test(EntregaPagamentoPicker::class)->assertSee('Retirada');
    }

    public function test_tela_de_entrega_envia_e_aparece_na_aba_viagem_sem_cobranca_do_garcom(): void
    {
        $this->actingAs($this->garcom);
        $rascunho = $this->rascunhoComItem();

        Livewire::test(AtenderRetirada::class)
            ->call('usarTipo', 'entrega')
            ->call('onClienteAtualizado', $this->cliente())
            ->call('onEntregaPagamentoAtualizado', $this->entregaPagamento())
            ->call('onItensAtualizados', [['id' => 1, 'produto_id' => 1, 'produto_nome' => 'Calabresa', 'quantidade' => 1.0, 'valor' => 50.0, 'desconto' => 0.0]])
            ->assertSee('R$ 58,00')
            ->call('enviar')
            ->assertRedirect(AtenderRetirada::getUrl(['pedido' => $rascunho->id]));

        Livewire::test(AtenderRetirada::class, ['pedido' => $rascunho->id])
            ->assertSee('Rua das Flores')
            ->assertDontSee('Entregue ao cliente')
            ->assertDontSeeHtml('wire:click="abrirModal"');

        Livewire::test(MapaMesas::class)
            ->set('tipo', 'RETIRADA')
            ->assertSee('Paula Reis')
            ->assertSee('Entrega');
    }
}
