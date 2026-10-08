<?php

namespace App\Filament\Resources\Clientes\Widgets;

use App\Models\Cliente;
use App\Services\DebitosClienteService;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

/**
 * Débito em aberto do cliente na edição dele, detalhado por título e pedido
 * (mesmo componente do relatório App\Filament\Pages\RelatorioDebitosClientes).
 */
class DebitosClienteWidget extends Widget
{
    protected string $view = 'filament.resources.clientes.debitos-cliente-widget';

    protected int|string|array $columnSpan = 'full';

    /** @var Cliente|null */
    public ?Model $record = null;

    /**
     * @return array<string, mixed>|null
     */
    public function debito(): ?array
    {
        if ($this->record === null) {
            return null;
        }

        return (new DebitosClienteService(['cliente_id' => $this->record->getKey()]))->porCliente()->first();
    }
}
