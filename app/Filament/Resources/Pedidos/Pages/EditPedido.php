<?php

namespace App\Filament\Resources\Pedidos\Pages;

use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Resources\Pedidos\Schemas\PedidoEditForm;
use App\Models\ItensPedido;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditPedido extends EditRecord
{
    protected static string $resource = PedidoResource::class;

    public function form(Schema $schema): Schema
    {
        return PedidoEditForm::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        unset($data['itens_pedido_selecionados']);

        // Recalcula totais a partir dos itens gravados no DB pelo Livewire
        $itens = ItensPedido::where('item_pedido_pedido_id', $record->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();

        $valorItens = round($itens->sum('item_pedido_valor'), 2);
        $totalDesconto = round($itens->sum('item_pedido_desconto'), 2);
        $descontoPedido = max(0.0, (float) ($data['pedido_valor_desconto'] ?? 0));

        $data['pedido_valor_itens'] = $valorItens;
        $data['pedido_valor_desconto'] = round($totalDesconto + $descontoPedido, 2);
        $data['pedido_valor_total'] = round(max(0, $valorItens - $totalDesconto - $descontoPedido), 2);

        $updated = parent::handleRecordUpdate($record, $data);

        Notification::make()
            ->title('Pedido atualizado')
            ->body('Total: R$ '.number_format($data['pedido_valor_total'], 2, ',', '.'))
            ->success()
            ->send();

        return $updated;
    }
}
