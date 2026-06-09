<?php

namespace App\Filament\Resources\Pedidos\Pages;

use App\Filament\Resources\Pedidos\PedidoResource;
use App\Models\AdicionaisItemPedido;
use App\Models\ItensPedido;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Pages\CreateRecord;

class CreatePedido extends CreateRecord
{
    protected static string $resource = PedidoResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $itens = $data['itens_pedido_selecionados'] ?? [];
        unset($data['itens_pedido_selecionados']);

        $pedido = parent::handleRecordCreation($data);

        $valorItens  = 0;
        $totalDesconto = 0;

        foreach ($itens as $item) {
            $itemModel = ItensPedido::create([
                'item_pedido_pedido_id'        => $pedido->id,
                'item_pedido_produto_id'       => $item['produto_id'],
                'item_pedido_quantidade'       => $item['quantidade'],
                'item_pedido_valor_unitario'   => $item['valor_unitario'],
                'item_pedido_valor'            => $item['valor'],
                'item_pedido_desconto'         => $item['desconto'] ?? 0,
                'item_pedido_valor_adicionais' => $item['adicionais_valor'] ?? 0,
                'item_pedido_observacao'       => $item['observacao'] ?: null,
                'item_pedido_status'           => 'INSERIDO',
            ]);

            foreach ($item['adicionais'] ?? [] as $adicional) {
                AdicionaisItemPedido::create([
                    'aip_item_pedido_id' => $itemModel->id,
                    'aip_adicional_id'   => $adicional['id'],
                    'aip_quantidade'     => 1,
                    'aip_valor_unitario' => $adicional['valor'],
                    'aip_valor_total'    => $adicional['valor'],
                ]);
            }

            $valorItens  += (float) $item['valor'];
            $totalDesconto += (float) ($item['desconto'] ?? 0);
        }

        if (count($itens) > 0) {
            $descontoPedido = (float) ($pedido->pedido_valor_desconto ?? 0);
            $pedido->update([
                'pedido_valor_itens' => round($valorItens, 2),
                'pedido_valor_total' => round(max(0, $valorItens - $totalDesconto - $descontoPedido), 2),
            ]);
        }

        return $pedido;
    }
}
