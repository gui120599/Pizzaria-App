<?php

namespace App\Filament\Resources\DispositivosCozinha\Support;

use App\Models\DispositivoCozinha;
use App\Services\Cozinha\PareamentoDispositivoService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Gera o QR de pareamento (uso único, expira) e mostra no modal. O token em
 * texto só existe neste modal: no banco fica o hash.
 */
class ParearDispositivoAction
{
    public static function make(): Action
    {
        return Action::make('parear')
            ->label(fn (DispositivoCozinha $record): string => $record->pareado_em ? 'Parear de novo' : 'Parear')
            ->icon(Heroicon::OutlinedQrCode)
            ->color('primary')
            ->authorize('parear')
            ->visible(fn (DispositivoCozinha $record): bool => $record->ativo)
            ->requiresConfirmation(fn (DispositivoCozinha $record): bool => $record->pareado_em !== null)
            ->modalDescription(fn (DispositivoCozinha $record): ?string => $record->pareado_em
                ? 'O tablet pareado hoje continua funcionando até o novo QR ser lido. Para tirar o acesso dele, use Revogar.'
                : null)
            ->action(function (DispositivoCozinha $record, Action $action): void {
                $token = app(PareamentoDispositivoService::class)->gerarToken($record);

                $action->getLivewire()->replaceMountedAction('qrPareamento', ['dispositivo' => $record->id, 'token' => $token]);
            });
    }

    /** Modal só de exibição, aberto no lugar do "Parear" logo depois de gerar o token. */
    public static function qrModal(): Action
    {
        return Action::make('qrPareamento')
            ->modalHeading('Parear tablet')
            ->modalWidth('sm')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (array $arguments) {
                $servico = app(PareamentoDispositivoService::class);
                $dispositivo = DispositivoCozinha::findOrFail($arguments['dispositivo'] ?? 0);
                $token = (string) ($arguments['token'] ?? '');

                return view('filament.cozinha.pareamento-qr', [
                    'dispositivo' => $dispositivo,
                    'qr' => $servico->qrPng($token),
                    'url' => $servico->urlPareamento($token),
                ]);
            });
    }
}
