<?php

namespace App\Filament\Resources\SefazNotasRecebidas\Pages;

use App\Exceptions\SefazAutenticacaoException;
use App\Exceptions\SefazIndisponivelException;
use App\Filament\Resources\SefazNotasRecebidas\SefazNotaRecebidaResource;
use App\Services\Sefaz\SefazDistribuicaoService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageSefazNotasRecebidas extends ManageRecords
{
    protected static string $resource = SefazNotaRecebidaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sincronizar')
                ->label('Buscar novidades')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (SefazDistribuicaoService $service): void {
                    try {
                        $resultado = $service->sincronizarNotasRecebidas();
                    } catch (SefazAutenticacaoException|SefazIndisponivelException $e) {
                        Notification::make()
                            ->title('Não foi possível consultar a SEFAZ')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Consulta concluída')
                        ->body("{$resultado->novas} nota(s) nova(s) encontrada(s), {$resultado->atualizadas} atualizada(s).")
                        ->success()
                        ->send();
                }),
        ];
    }
}
