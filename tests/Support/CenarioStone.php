<?php

namespace Tests\Support;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoOrigem;
use App\Enums\StonePedidoStatus;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\StonePedido;
use Illuminate\Support\Facades\Http;

/**
 * Cenário comum dos testes da cobrança em maquininha Stone Connect: config da
 * integração, nenhuma chamada real (Http::preventStrayRequests) e fábricas
 * pequenas de maquininha, forma de pagamento, pedido e cobrança pendente.
 */
trait CenarioStone
{
    protected function configurarStone(): void
    {
        config([
            'services.stone.secret_key' => 'sk_test_abc',
            'services.stone.service_referer_name' => 'ref-123',
            'services.stone.base_url' => 'https://api.pagar.me/core/v5',
            'services.stone.pedido_direto' => true,
            'services.stone.limite_pedidos_abertos' => 30,
            'services.stone.webhook_user' => null,
            'services.stone.webhook_password' => null,
        ]);

        Http::preventStrayRequests();
    }

    protected function maquininhaConnect(string $nome = 'Sunmi', string $serie = '111'): Maquininha
    {
        return Maquininha::create(['nome' => $nome, 'operadora' => 'stone', 'maquininha_connect_ativo' => true, 'numero_serie' => $serie]);
    }

    /** Forma única "Crédito" que pode ser cobrada na Stone (D1). */
    protected function formaCredito(bool $permiteStone = true): OpcoesPagamento
    {
        return OpcoesPagamento::create([
            'opcaopag_nome' => 'Crédito',
            'opcaopag_desc_nfe' => 'creditCard',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => $permiteStone,
        ]);
    }

    /** Pedido com um item de $total e o valor total gravado, no status indicado. */
    protected function pedidoComTotal(float $total = 80.0, string $status = 'EM TRANSPORTE'): Pedido
    {
        $produto = Produto::firstOrCreate(
            ['produto_descricao' => 'Calabresa'],
            [
                'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'])->id,
                'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
                'produto_preco_venda' => 40.0,
            ],
        );

        $pedido = Pedido::create(['pedido_status' => $status, 'pedido_valor_total' => $total, 'pedido_valor_itens' => $total]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $total,
            'item_pedido_valor' => $total,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    protected function combinarPagamento(Pedido $pedido, OpcoesPagamento $opcao, ?float $valor = null, bool $pago = false): PagamentosPedido
    {
        return PagamentosPedido::create([
            'pg_pedido_pedido_id' => $pedido->id,
            'pg_pedido_opcaopagamento_id' => $opcao->id,
            'pg_pedido_opcaopagamento_nome' => $opcao->opcaopag_nome,
            'pg_pedido_valor' => $valor ?? (float) $pedido->pedido_valor_total,
            'pg_pedido_pago' => $pago,
            'pg_pedido_ordem' => 0,
        ]);
    }

    /**
     * Cobrança já gravada para um pedido (sem passar pelo serviço).
     *
     * @param  array<string, mixed>  $atributos
     */
    protected function cobrancaDoPedido(Pedido $pedido, Maquininha $maquininha, array $atributos = []): StonePedido
    {
        return StonePedido::create($atributos + [
            'stp_pedido_id' => $pedido->id,
            'stp_maquininha_id' => $maquininha->id,
            'stp_origem' => StonePedidoOrigem::Pedido,
            'stp_order_id' => 'or_'.uniqid(),
            'stp_valor_solicitado' => (float) $pedido->pedido_valor_total,
            'stp_status' => StonePedidoStatus::Aguardando,
            'stp_modo' => StonePedidoModo::Listado,
        ]);
    }

    /** $quantidade cobranças abertas (aguardando, com order_id) ocupando vagas do POS da maquininha. */
    protected function ocuparVagas(Maquininha $maquininha, int $quantidade, StonePedidoStatus $status = StonePedidoStatus::Aguardando): void
    {
        foreach (range(1, $quantidade) as $i) {
            StonePedido::create([
                'stp_maquininha_id' => $maquininha->id,
                'stp_origem' => StonePedidoOrigem::Pedido,
                'stp_order_id' => "or_vaga_{$maquininha->id}_{$status->value}_{$i}",
                'stp_valor_solicitado' => 10,
                'stp_status' => $status,
                'stp_modo' => StonePedidoModo::Listado,
            ]);
        }
    }

    /** POST /orders responde com os ids indicados, na ordem; as demais rotas da Stone devolvem 200 vazio. */
    protected function fakeStoneCriaPedidos(string ...$orderIds): void
    {
        $sequencia = Http::sequence();
        foreach ($orderIds as $orderId) {
            $sequencia->push(['id' => $orderId, 'code' => null, 'status' => 'pending'], 200);
        }

        Http::fake([
            'api.pagar.me/core/v5/orders' => $sequencia,
            'api.pagar.me/*' => Http::response([], 200),
        ]);
    }
}
