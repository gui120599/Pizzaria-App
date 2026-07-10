<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use App\Services\PrecificadorService;
use App\Services\PromocaoRelampagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromocaoRelampagoTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Produto $calabresa;

    private Produto $marguerita;

    private Produto $portuguesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'categoria_nome' => 'Pizza Grande',
            'categoria_permite_sabores' => true,
            'categoria_max_sabores' => 2,
        ]);

        $this->calabresa = $this->produto('Calabresa', 55.00);
        $this->marguerita = $this->produto('Marguerita', 55.00);
        $this->portuguesa = $this->produto('Portuguesa', 60.00);
    }

    private function produto(string $nome, float $preco, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ], $attrs));
    }

    private function promocao(array $attrs = [], array $produtos = []): PromocaoRelampago
    {
        $promocao = PromocaoRelampago::create(array_merge([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 40,
            'promocao_permite_sabores' => true,
        ], $attrs));

        foreach ($produtos ?: [$this->calabresa, $this->marguerita] as $produto) {
            $promocao->promocaoProdutos()->create([
                'prp_produto_id' => $produto->id,
                'prp_preco_promocional' => 39.90,
            ]);
        }

        return $promocao->fresh(['promocaoProdutos']);
    }

    private function itemPromocional(PromocaoRelampago $promocao, Produto $produto, float $qtd): ItensPedido
    {
        $pedido = Pedido::create(['pedido_status' => 'INICIADO']);

        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_promocao_id' => $promocao->id,
            'item_pedido_quantidade' => $qtd,
            'item_pedido_valor_unitario' => 55.00,
            'item_pedido_desconto' => 15.10 * $qtd,
            'item_pedido_desconto_unitario' => 15.10,
            'item_pedido_valor' => 39.90 * $qtd,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    public function test_promocao_relampago_tem_precedencia_sobre_preco_promocional_do_produto(): void
    {
        $this->calabresa->update(['produto_preco_promocional' => 49.90]);
        $this->promocao();

        $preco = app(PrecificadorService::class)->resolver($this->calabresa->fresh());

        $this->assertSame(39.90, $preco->precoFinal());
        $this->assertTrue($preco->temPromocaoRelampago());
    }

    public function test_promocao_so_inteira_segue_a_categoria_mas_fracao_volta_ao_normal(): void
    {
        // Categoria permite sabores (setUp: max 2); promoção é "só inteira".
        // O produto continua selecionável como sabor: inteiro sai pelo
        // promocional, fração volta ao preço de venda.
        $this->promocao(['promocao_permite_sabores' => false]);

        $calabresa = $this->calabresa->fresh('categoria');

        $this->assertTrue($calabresa->permiteSaboresCardapio());
        $this->assertSame(2, $calabresa->maxSaboresCardapio());
        $this->assertTrue($calabresa->relampagoSoInteiraCardapio());

        // Inteiro = promocional; fração = venda.
        $this->assertSame(39.90, $calabresa->precoResolvido()->precoFinal());
        $this->assertSame(55.00, $calabresa->precoFracaoCardapio());
    }

    public function test_promocao_que_permite_sabores_usa_o_maximo_da_promocao(): void
    {
        $this->promocao(['promocao_permite_sabores' => true, 'promocao_max_sabores' => 2]);

        $calabresa = $this->calabresa->fresh('categoria');

        $this->assertTrue($calabresa->permiteSaboresCardapio());
        $this->assertSame(2, $calabresa->maxSaboresCardapio());
    }

    public function test_produto_fora_de_promocao_segue_a_categoria(): void
    {
        // Nenhuma promoção relâmpago vigente para a portuguesa.
        $portuguesa = $this->portuguesa->fresh('categoria');

        $this->assertSame(
            (bool) $portuguesa->categoria->categoria_permite_sabores,
            $portuguesa->permiteSaboresCardapio(),
        );
        $this->assertSame(
            (int) $portuguesa->categoria->categoria_max_sabores,
            $portuguesa->maxSaboresCardapio(),
        );
    }

    public function test_preco_promocional_do_produto_expirado_nao_e_aplicado(): void
    {
        $this->portuguesa->update([
            'produto_preco_promocional' => 45.00,
            'produto_data_inicio_promocao' => now()->subDays(10)->toDateString(),
            'produto_data_final_promocao' => now()->subDay()->toDateString(),
        ]);

        $preco = app(PrecificadorService::class)->resolver($this->portuguesa->fresh());

        $this->assertSame(60.00, $preco->precoFinal());
        $this->assertSame(0.0, $preco->descontoUnitario);
    }

    public function test_meia_a_meia_promocional_soma_exatamente_o_preco_anunciado(): void
    {
        $this->promocao();

        $linhas = app(PrecificadorService::class)->ratearCombo(
            [$this->calabresa, $this->marguerita],
            qtd: 1,
        );

        $this->assertCount(2, $linhas);
        $this->assertSame(39.90, round(array_sum(array_column($linhas, 'valor')), 2));
        $this->assertSame(1.0, round(array_sum(array_column($linhas, 'quantidade')), 2));
        $this->assertNotNull($linhas[0]['promocao_id']);
    }

    public function test_sabor_fora_da_promocao_derruba_a_promocao_do_combo(): void
    {
        $this->promocao();

        $linhas = app(PrecificadorService::class)->ratearCombo(
            [$this->calabresa, $this->portuguesa],
            qtd: 1,
        );

        // (55 + 60) / 2 = 57,50 — preço cheio, sem promoção.
        $this->assertSame(57.50, round(array_sum(array_column($linhas, 'valor')), 2));
        $this->assertNull($linhas[0]['promocao_id']);
    }

    public function test_promocao_que_nao_permite_sabores_nao_vale_para_meia_a_meia(): void
    {
        $this->promocao(['promocao_permite_sabores' => false]);

        $linhas = app(PrecificadorService::class)->ratearCombo(
            [$this->calabresa, $this->marguerita],
            qtd: 1,
        );

        $this->assertSame(55.00, round(array_sum(array_column($linhas, 'valor')), 2));
        $this->assertNull($linhas[0]['promocao_id']);
    }

    public function test_contador_nao_ultrapassa_o_teto_do_pool(): void
    {
        $promocao = $this->promocao(['promocao_qtd_total' => 2]);
        $service = app(PromocaoRelampagoService::class);

        $service->consumir($this->itemPromocional($promocao, $this->calabresa, 2));

        $this->assertSame('2.00', $promocao->fresh()->promocao_qtd_vendida);

        $this->expectException(PromocaoIndisponivelException::class);
        $service->consumir($this->itemPromocional($promocao, $this->calabresa, 1));
    }

    public function test_sublimite_por_produto_esgota_antes_do_pool(): void
    {
        $promocao = $this->promocao(['promocao_qtd_total' => 40]);
        $promocao->promocaoProdutos()
            ->where('prp_produto_id', $this->calabresa->id)
            ->update(['prp_qtd_total' => 1]);

        $service = app(PromocaoRelampagoService::class);
        $service->consumir($this->itemPromocional($promocao, $this->calabresa, 1));

        $this->expectException(PromocaoIndisponivelException::class);
        $service->consumir($this->itemPromocional($promocao, $this->calabresa, 1));
    }

    public function test_consumir_e_idempotente_por_item(): void
    {
        $promocao = $this->promocao();
        $service = app(PromocaoRelampagoService::class);
        $item = $this->itemPromocional($promocao, $this->calabresa, 1);

        $service->consumir($item);
        $service->consumir($item);

        $this->assertSame('1.00', $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_estorno_devolve_ao_contador_e_nao_devolve_duas_vezes(): void
    {
        $promocao = $this->promocao();
        $service = app(PromocaoRelampagoService::class);
        $item = $this->itemPromocional($promocao, $this->calabresa, 3);

        $service->consumir($item);
        $this->assertSame('3.00', $promocao->fresh()->promocao_qtd_vendida);

        $service->estornarItem($item);
        $service->estornarItem($item);

        $this->assertSame('0.00', $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_estorno_do_pedido_devolve_todos_os_itens_ativos(): void
    {
        $promocao = $this->promocao();
        $service = app(PromocaoRelampagoService::class);

        $item = $this->itemPromocional($promocao, $this->calabresa, 2);
        $service->consumir($item);

        $service->estornarPedido($item->pedido);

        $this->assertSame('0.00', $promocao->fresh()->promocao_qtd_vendida);
    }

    public function test_promocao_fora_da_janela_nao_e_consumida(): void
    {
        $promocao = $this->promocao(['promocao_fim' => now()->subMinute()]);
        $service = app(PromocaoRelampagoService::class);

        $this->expectException(PromocaoIndisponivelException::class);
        $service->consumir($this->itemPromocional($promocao, $this->calabresa, 1));
    }

    public function test_item_promocional_nao_e_reprecificado_ao_recalcular(): void
    {
        $promocao = $this->promocao();
        $item = $this->itemPromocional($promocao, $this->calabresa, 1);

        // Promoção encerra depois da venda.
        $promocao->update(['promocao_ativa' => false]);

        $item->item_pedido_quantidade = 2;
        $item->recalcularValores(0.0);

        // Preço congelado: 2 × (55,00 − 15,10) = 79,80.
        $this->assertSame(79.80, (float) $item->item_pedido_valor);
        $this->assertSame(30.20, (float) $item->item_pedido_desconto);
    }
}
