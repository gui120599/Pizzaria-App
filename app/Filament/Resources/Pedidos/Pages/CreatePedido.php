<?php

namespace App\Filament\Resources\Pedidos\Pages;

use App\Filament\Resources\Pedidos\PedidoResource;
use App\Models\AdicionaisItemPedido;
use App\Models\ItensPedido;
use App\Models\Produto;
use App\Services\EstoqueService;
use App\Support\TotaisPedido;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreatePedido extends CreateRecord
{
    protected static string $resource = PedidoResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $itens = $data['itens_pedido_selecionados'] ?? [];
        unset($data['itens_pedido_selecionados']);

        $bloqueios = [];
        $estoque = app(EstoqueService::class);

        foreach (collect($itens)->groupBy('produto_id') as $produtoId => $doGrupo) {
            $produto = Produto::find($produtoId);
            if (! $produto) {
                continue;
            }

            $qtdTotal = (float) collect($doGrupo)->sum('quantidade');
            $resultado = $estoque->checarDisponibilidade($produto, $qtdTotal);
            array_push($bloqueios, ...$resultado['bloqueios']);
        }

        if ($bloqueios !== []) {
            Notification::make()
                ->title('Estoque insuficiente')
                ->body(implode(' | ', $bloqueios))
                ->danger()
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }

        $pedido = parent::handleRecordCreation($data);

        foreach ($itens as $item) {
            $itemModel = ItensPedido::create([
                'item_pedido_pedido_id' => $pedido->id,
                'item_pedido_produto_id' => $item['produto_id'],
                'item_pedido_quantidade' => $item['quantidade'],
                'item_pedido_valor_unitario' => $item['valor_unitario'],
                'item_pedido_valor' => $item['valor'],
                'item_pedido_desconto' => $item['desconto'] ?? 0,
                'item_pedido_valor_adicionais' => $item['adicionais_valor'] ?? 0,
                'item_pedido_observacao' => $item['observacao'] ?: null,
                'item_pedido_status' => 'INSERIDO',
            ]);

            foreach ($item['adicionais'] ?? [] as $adicional) {
                AdicionaisItemPedido::create([
                    'aip_item_pedido_id' => $itemModel->id,
                    'aip_adicional_id' => $adicional['id'],
                    'aip_quantidade' => 1,
                    'aip_valor_unitario' => $adicional['valor'],
                    'aip_valor_total' => $adicional['valor'],
                ]);
            }
        }

        if (count($itens) > 0) {
            $linhas = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->get();

            $totais = TotaisPedido::paraItens($linhas, $pedido->opcaoEntrega, (float) ($pedido->pedido_valor_desconto ?? 0));

            $pedido->update([
                'pedido_valor_itens' => $totais['itens'],
                'pedido_valor_desconto' => $totais['desconto'],
                'pedido_valor_total' => $totais['total'],
            ]);
        }

        return $pedido;
    }
}
