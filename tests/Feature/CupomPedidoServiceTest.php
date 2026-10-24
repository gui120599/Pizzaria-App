<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\TipoDescontoEnum;
use App\Exceptions\CupomInvalidoException;
use App\Filament\Pages\AtenderPedido;
use App\Livewire\PedidoProdutoSelector;
use App\Models\Categoria;
use App\Models\Cupom;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\CupomPedidoService;
use App\Services\LancamentoItemPedidoService;
use App\Services\LancamentoItensVendaService;
use App\Services\TotaisPedidoService;
use App\Services\VendaService;
use App\Support\TotaisPedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** F1.1: o desconto do cupom vive nos itens (rateado em centavos) e chega à venda e à NFC-e. */
class CupomPedidoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function produto(string $nome, float $preco): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Diversos'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    private function cupom(TipoDescontoEnum $tipo, float $valor): Cupom
    {
        return Cupom::create([
            'cupom_codigo' => 'CUPOM'.strtoupper(uniqid()),
            'cupom_tipo_desconto' => $tipo->value,
            'cupom_valor_desconto' => $valor,
        ]);
    }

    /**
     * @param  list<float>  $valores  um item por valor (quantidade 1)
     * @return array{0: Pedido, 1: list<ItensPedido>}
     */
    private function pedidoComItens(array $valores, string $status = 'PREPARANDO', ?OpcoesEntregas $entrega = null): array
    {
        $pedido = Pedido::create([
            'pedido_status' => $status,
            'pedido_opcaoentrega_id' => $entrega?->id,
            'pedido_valor_itens' => array_sum($valores),
            'pedido_valor_total' => array_sum($valores),
        ]);

        $itens = [];
        foreach ($valores as $i => $valor) {
            $itens[] = ItensPedido::create([
                'item_pedido_pedido_id' => $pedido->id,
                'item_pedido_produto_id' => $this->produto("Produto {$i}", $valor)->id,
                'item_pedido_quantidade' => 1,
                'item_pedido_valor_unitario' => $valor,
                'item_pedido_valor' => $valor,
                'item_pedido_desconto' => 0,
                'item_pedido_valor_adicionais' => 0,
                'item_pedido_status' => 'INSERIDO',
            ]);
        }

        return [$pedido, $itens];
    }

    private function somaPartes(Pedido $pedido): float
    {
        return round((float) ItensPedido::where('item_pedido_pedido_id', $pedido->id)->sum('item_pedido_desconto_cupom'), 2);
    }

    private function somaValores(Pedido $pedido): float
    {
        return round((float) ItensPedido::where('item_pedido_pedido_id', $pedido->id)->where('item_pedido_status', 'INSERIDO')->sum('item_pedido_valor'), 2);
    }

    public function test_percentual_distribui_em_centavos_e_fecha_com_o_total(): void
    {
        [$pedido] = $this->pedidoComItens([10.01, 20.03, 30.07]);

        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));
        $totais = app(TotaisPedidoService::class)->recalcular($pedido);

        // 10% de 60,11 = 6,01 (centavos exatos, sem sobrar nem faltar).
        $this->assertSame(6.01, $this->somaPartes($pedido));
        $this->assertSame(54.10, $this->somaValores($pedido));
        $this->assertSame(54.10, $totais['total']);
        $this->assertSame(60.11, $totais['itens']);
        $this->assertSame(6.01, $totais['desconto']);
        $this->assertSame('6.01', $pedido->fresh()->pedido_valor_desconto_cupom);
        $this->assertSame('54.10', $pedido->fresh()->pedido_valor_total);
    }

    public function test_valor_fixo_e_limitado_a_soma_dos_itens(): void
    {
        [$pedido] = $this->pedidoComItens([3.33, 3.33, 3.34]);

        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom(TipoDescontoEnum::VALOR_FIXO, 50));
        $totais = app(TotaisPedidoService::class)->recalcular($pedido);

        $this->assertSame(10.00, $this->somaPartes($pedido));
        $this->assertSame(0.0, $totais['total']);
    }

    public function test_valor_fixo_com_resto_de_centavos_fecha_exato(): void
    {
        [$pedido] = $this->pedidoComItens([10.00, 10.00, 10.00]);

        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom(TipoDescontoEnum::VALOR_FIXO, 10));

        $this->assertSame(10.00, $this->somaPartes($pedido));
        $this->assertSame(20.00, $this->somaValores($pedido));
    }

    public function test_cortesia_fica_fora_da_distribuicao(): void
    {
        [$pedido, $itens] = $this->pedidoComItens([50.00, 50.00]);
        $itens[1]->update(['item_pedido_cortesia' => true, 'item_pedido_valor' => 0, 'item_pedido_desconto' => 50]);

        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));

        $this->assertSame(5.00, (float) $itens[0]->fresh()->item_pedido_desconto_cupom);
        $this->assertSame(0.0, (float) $itens[1]->fresh()->item_pedido_desconto_cupom);
        $this->assertSame(0.0, (float) $itens[1]->fresh()->item_pedido_valor);
    }

    public function test_reaplicar_e_idempotente(): void
    {
        [$pedido] = $this->pedidoComItens([10.01, 20.03]);
        $service = app(CupomPedidoService::class);
        $service->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 15));

        $antes = $this->somaValores($pedido);
        $service->reaplicar($pedido->fresh());
        $service->reaplicar($pedido->fresh());

        $this->assertSame($antes, $this->somaValores($pedido));
    }

    public function test_remover_devolve_o_valor_cheio(): void
    {
        [$pedido] = $this->pedidoComItens([40.00, 60.00]);
        $service = app(CupomPedidoService::class);
        $service->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));

        $service->remover($pedido);

        $this->assertSame(100.00, $this->somaValores($pedido));
        $this->assertSame(0.0, $this->somaPartes($pedido));
        $this->assertNull($pedido->fresh()->pedido_cupom_id);
    }

    public function test_alterar_quantidade_mantem_o_percentual(): void
    {
        [$pedido, $itens] = $this->pedidoComItens([100.00, 50.00]);
        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));

        app(LancamentoItemPedidoService::class)->atualizarQuantidade($itens[0], 2);

        // Itens: 200 + 50 = 250; 10% = 25.
        $pedido->refresh();
        $this->assertSame(25.00, $this->somaPartes($pedido));
        $this->assertSame(225.00, (float) $pedido->pedido_valor_total);
        $this->assertSame(250.00, (float) $pedido->pedido_valor_itens);
        $this->assertSame(25.00, (float) $pedido->pedido_valor_desconto);
    }

    public function test_incluir_e_remover_item_no_atender_pedido_mantem_o_percentual(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        [$pedido, $itens] = $this->pedidoComItens([100.00]);
        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('selecionarProduto', $this->produto('Coca', 20.00)->id)
            ->call('confirmarItem');

        $pedido->refresh();
        $this->assertSame(12.00, $this->somaPartes($pedido));
        $this->assertSame(108.00, (float) $pedido->pedido_valor_total);

        Livewire::test(PedidoProdutoSelector::class, ['pedidoId' => $pedido->id])
            ->call('removerItem', (string) $itens[0]->id);

        $pedido->refresh();
        $this->assertSame(2.00, $this->somaPartes($pedido));
        $this->assertSame(18.00, (float) $pedido->pedido_valor_total);
    }

    public function test_frete_minimo_avalia_o_liquido_ja_com_o_cupom(): void
    {
        $entrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Entrega',
            'opcaoentrega_valor_frete' => 5,
            'opcaoentrega_min_valor_frete' => 100,
        ]);
        [$pedido] = $this->pedidoComItens([105.00], entrega: $entrega);

        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));
        $totais = app(TotaisPedidoService::class)->recalcular($pedido);

        // 105 - 10,50 = 94,50: abaixo do mínimo, volta a cobrar o frete.
        $this->assertSame(5.0, $totais['frete']);
        $this->assertSame(99.50, $totais['total']);
    }

    public function test_totais_pedido_nao_subtrai_o_cupom_duas_vezes(): void
    {
        // Itens já líquidos (90 = 100 - 10 de cupom): bruto 100, desconto 10, total 90.
        $totais = TotaisPedido::paraItens(collect([
            new ItensPedido(['item_pedido_valor' => 90, 'item_pedido_desconto' => 0, 'item_pedido_desconto_cupom' => 10]),
        ]), null);

        $this->assertEqualsWithDelta(100.0, $totais['itens'], 0.001);
        $this->assertEqualsWithDelta(10.0, $totais['desconto'], 0.001);
        $this->assertEqualsWithDelta(90.0, $totais['total'], 0.001);
    }

    public function test_venda_gerada_do_pedido_tem_o_total_do_pedido_e_o_desconto_do_cupom_na_linha(): void
    {
        [$pedido] = $this->pedidoComItens([10.01, 20.03, 30.07]);
        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));
        $totais = app(TotaisPedidoService::class)->recalcular($pedido);

        $venda = Venda::create(['venda_status' => 'INICIADA']);
        app(LancamentoItensVendaService::class)->lancarPedido($venda, $pedido);
        $venda = app(VendaService::class)->atualizarValoresdaVenda($venda->id);

        // Total da venda == total do pedido == o que a Stone cobra (pedido_valor_total).
        $this->assertSame($totais['total'], round((float) $venda->venda_valor_total, 2));
        $this->assertSame(6.01, round((float) $venda->venda_valor_desconto, 2));

        // item_venda_desconto carrega a parte do cupom (vira o vDesc da NFC-e).
        $this->assertSame(6.01, round((float) ItensVenda::sum('item_venda_desconto'), 2));
        $this->assertSame(54.10, round((float) ItensVenda::sum('item_venda_valor'), 2));
    }

    public function test_cobranca_da_stone_no_atender_pedido_usa_o_total_com_cupom(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        [$pedido] = $this->pedidoComItens([100.00]);
        app(CupomPedidoService::class)->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));
        app(TotaisPedidoService::class)->recalcular($pedido);

        Livewire::test(AtenderPedido::class, ['pedido' => $pedido->fresh()])
            ->assertSet('cupomId', $pedido->fresh()->pedido_cupom_id)
            ->assertSee('90,00');

        $this->assertSame('90.00', $pedido->fresh()->pedido_valor_total);
    }

    public function test_item_ja_vendido_nao_e_redistribuido(): void
    {
        [$pedido, $itens] = $this->pedidoComItens([100.00, 100.00]);
        $service = app(CupomPedidoService::class);
        $service->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));

        // O primeiro item entra numa venda (mesa com pagamento parcial): fica congelado.
        $venda = Venda::create(['venda_status' => 'INICIADA']);
        $itens[0]->update(['item_pedido_venda_id' => $venda->id]);
        $parteCongelada = (float) $itens[0]->fresh()->item_pedido_desconto_cupom;

        // Novo item livre de 100: 10% de 300 = 30; congelado já tem 10, o resto vai para os livres.
        $novo = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto('Extra', 100)->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 100,
            'item_pedido_valor' => 100,
            'item_pedido_desconto' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
        $service->reaplicar($pedido->fresh());

        $this->assertSame($parteCongelada, (float) $itens[0]->fresh()->item_pedido_desconto_cupom);
        $this->assertSame(30.00, $this->somaPartes($pedido));
        // O que sobra do desconto total (30 - 10 já congelado) é rateado só entre as linhas livres.
        $this->assertSame(20.00, round((float) $itens[1]->fresh()->item_pedido_desconto_cupom + (float) $novo->fresh()->item_pedido_desconto_cupom, 2));
    }

    public function test_pedido_com_venda_nao_aceita_trocar_nem_remover_o_cupom(): void
    {
        [$pedido, $itens] = $this->pedidoComItens([100.00]);
        $service = app(CupomPedidoService::class);
        $service->aplicar($pedido, $this->cupom(TipoDescontoEnum::PERCENTUAL, 10));
        $itens[0]->update(['item_pedido_venda_id' => Venda::create(['venda_status' => 'INICIADA'])->id]);

        $this->expectException(CupomInvalidoException::class);
        $service->remover($pedido->fresh());
    }

    public function test_pedido_sem_cupom_nao_e_alterado_pelo_reaplicar(): void
    {
        [$pedido] = $this->pedidoComItens([10.01, 20.03]);

        app(TotaisPedidoService::class)->recalcular($pedido);

        $this->assertSame(30.04, $this->somaValores($pedido));
        $this->assertSame(0.0, $this->somaPartes($pedido));
    }
}
