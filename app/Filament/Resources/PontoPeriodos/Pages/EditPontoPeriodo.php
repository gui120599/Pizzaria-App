<?php

namespace App\Filament\Resources\PontoPeriodos\Pages;

use App\Enums\Ponto\StatusPeriodo;
use App\Exceptions\PontoException;
use App\Filament\Pages\EspelhoPonto;
use App\Filament\Resources\PontoPeriodos\PontoPeriodoResource;
use App\Services\Ponto\FechamentoPeriodoService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPontoPeriodo extends EditRecord
{
    protected static string $resource = PontoPeriodoResource::class;

    public function getTitle(): string
    {
        return "Período {$this->record->rotulo()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('espelho')
                ->label('Espelho')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(fn (): string => EspelhoPonto::getUrl(['periodo' => $this->record->id])),
            Action::make('iniciarFechamento')
                ->label('Iniciar fechamento')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('primary')
                ->visible(fn (): bool => $this->record->status === StatusPeriodo::Aberto)
                ->requiresConfirmation()
                ->modalDescription('Apura todos os dias, trava marcações e ajustes do período e gera os repasses em rascunho. Dia com marcação faltante bloqueia.')
                ->action(function (FechamentoPeriodoService $fechamento, Action $action): void {
                    try {
                        $resultado = $fechamento->iniciarFechamento($this->record, auth()->user());
                    } catch (PontoException $e) {
                        Notification::make()->title('Fechamento bloqueado')->body($e->getMessage())->danger()->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()
                        ->title($resultado['repasses']->count().' repasse(s) em rascunho')
                        ->body($resultado['avisos'] === [] ? null : "Confira:\n".implode("\n", array_slice($resultado['avisos'], 0, 15)))
                        ->success()
                        ->persistent($resultado['avisos'] !== [])
                        ->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('reabrir')
                ->label('Reabrir')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === StatusPeriodo::EmConferencia)
                ->requiresConfirmation()
                ->modalDescription('Volta a aceitar marcações e correções. Os rascunhos são recalculados no próximo fechamento.')
                ->action(function (FechamentoPeriodoService $fechamento, Action $action): void {
                    try {
                        $fechamento->reabrir($this->record, auth()->user());
                    } catch (PontoException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Período reaberto')->success()->send();
                    $this->refreshFormData(['status']);
                }),
            Action::make('aprovarConferidos')
                ->label('Aprovar repasses conferidos')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (): bool => $this->record->status === StatusPeriodo::EmConferencia && (auth()->user()?->can('aprovar:repasse') ?? false))
                ->requiresConfirmation()
                ->modalDescription('Aprova todos os rascunhos: CLT só se o holerite estiver conferido; líquido negativo fica para tratar.')
                ->action(function (FechamentoPeriodoService $fechamento): void {
                    $resultado = $fechamento->aprovarConferidos($this->record, auth()->user());

                    Notification::make()
                        ->title("{$resultado['aprovados']} repasse(s) aprovado(s)")
                        ->body($resultado['recusados'] === [] ? null : implode("\n", $resultado['recusados']))
                        ->color($resultado['recusados'] === [] ? 'success' : 'warning')
                        ->persistent($resultado['recusados'] !== [])
                        ->send();
                    $this->refreshFormData(['status']);
                }),
        ];
    }
}
