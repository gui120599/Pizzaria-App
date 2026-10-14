<?php

namespace App\Observers;

use App\Models\AdicionaisItemPedido;
use App\Models\ItensPedido;
use App\Services\Cozinha\RoteadorProducao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Item do pedido → produção: item novo, removido (status REMOVIDO),
 * quantidade, sabores, observação e adicionais. Registrado via #[ObservedBy]
 * em ItensPedido e AdicionaisItemPedido. O roteador é idempotente, então
 * vários eventos na mesma transação só repetem a mesma conta.
 */
class ItemPedidoProducaoObserver
{
    /** Mudança só nestas colunas não interessa à cozinha (valores, vínculos de venda, cache). */
    private const CAMPOS_IGNORADOS = [
        'updated_at',
        'item_pedido_valor_unitario',
        'item_pedido_desconto',
        'item_pedido_desconto_unitario',
        'item_pedido_valor_adicionais',
        'item_pedido_valor',
        'item_pedido_venda_id',
        'item_pedido_item_venda_id',
        'item_pedido_cliente_id',
        'item_pedido_producao_status',
    ];

    public function created(Model $model): void
    {
        $this->sincronizar($model);
    }

    public function updated(Model $model): void
    {
        if ($model instanceof ItensPedido && array_diff(array_keys($model->getChanges()), self::CAMPOS_IGNORADOS) === []) {
            return;
        }

        $this->sincronizar($model);
    }

    public function deleted(Model $model): void
    {
        $this->sincronizar($model);
    }

    private function sincronizar(Model $model): void
    {
        $itemId = $model instanceof AdicionaisItemPedido ? $model->aip_item_pedido_id : $model->getKey();

        if ($itemId === null) {
            return;
        }

        DB::afterCommit(function () use ($itemId): void {
            $item = ItensPedido::find($itemId);

            if ($item !== null) {
                app(RoteadorProducao::class)->sincronizarItem($item);
            }
        });
    }
}
