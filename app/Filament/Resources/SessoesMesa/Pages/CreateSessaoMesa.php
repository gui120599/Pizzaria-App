<?php

namespace App\Filament\Resources\SessoesMesa\Pages;

use App\Exceptions\MesaIndisponivelException;
use App\Filament\Resources\SessoesMesa\SessaoMesaResource;
use App\Services\SessaoMesaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSessaoMesa extends CreateRecord
{
    protected static string $resource = SessaoMesaResource::class;

    /** Delega ao SessaoMesaService::abrir — mesma regra de SessaoMesaController::AbrirSessaoMesa. */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SessaoMesaService::class)->abrir(
                mesaId: (int) $data['sessao_mesa_mesa_id'],
                usuarioId: (int) $data['sessao_mesa_usuario_id'],
                clienteId: $data['sessao_mesa_cliente_id'] ?? null,
            );
        } catch (MesaIndisponivelException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt();
        }
    }
}
