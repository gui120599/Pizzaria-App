<?php

namespace App\Filament\Resources\Repasses\Support;

use App\Enums\Ponto\StatusRepasse;
use App\Exceptions\FinanceiroException;
use App\Exceptions\PontoException;
use App\Models\Repasse;
use App\Services\Ponto\RepasseService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/** Ações do repasse (página do repasse e aba de repasses do período). Toda regra fica no RepasseService. */
class RepasseActions
{
    public static function recalcular(): Action
    {
        return Action::make('recalcular')
            ->label('Recalcular')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Repasse $record): bool => $record->podeEditar() && self::podeAprovar())
            ->requiresConfirmation()
            ->modalDescription('Refaz os itens automáticos (jornada e adiantamentos). Itens manuais ficam.')
            ->action(fn (Repasse $record, Action $action) => self::executar($action, 'Repasse recalculado', fn () => app(RepasseService::class)->calcularItens($record)));
    }

    public static function aprovar(): Action
    {
        return Action::make('aprovar')
            ->label('Aprovar')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Repasse $record): bool => $record->podeEditar() && self::podeAprovar())
            ->requiresConfirmation()
            ->modalDescription(fn (Repasse $record): string => 'Cria o título de R$ '.number_format((float) $record->valor_liquido, 2, ',', '.').' no contas a pagar e avisa o colaborador.')
            ->action(fn (Repasse $record, Action $action) => self::executar($action, 'Repasse aprovado', fn () => app(RepasseService::class)->aprovar($record, auth()->user())));
    }

    public static function cancelarAprovacao(): Action
    {
        return Action::make('cancelarAprovacao')
            ->label('Cancelar aprovação')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Repasse $record): bool => $record->status === StatusRepasse::Aprovado && self::podeAprovar())
            ->modalDescription('O título no financeiro é cancelado (só se não tiver pagamento) e os adiantamentos voltam a ficar em aberto.')
            ->schema([self::campoMotivo()])
            ->action(fn (Repasse $record, array $data, Action $action) => self::executar($action, 'Aprovação cancelada', fn () => app(RepasseService::class)->cancelarAprovacao($record, auth()->user(), $data['motivo'])));
    }

    public static function cancelar(): Action
    {
        return Action::make('cancelarRepasse')
            ->label('Cancelar repasse')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Repasse $record): bool => $record->podeEditar() && self::podeAprovar())
            ->modalDescription('O repasse não será pago neste período. Adiantamentos continuam em aberto para o próximo.')
            ->schema([self::campoMotivo()])
            ->action(fn (Repasse $record, array $data, Action $action) => self::executar($action, 'Repasse cancelado', fn () => app(RepasseService::class)->cancelar($record, auth()->user(), $data['motivo'])));
    }

    public static function transferirSaldoDevedor(): Action
    {
        return Action::make('transferirSaldoDevedor')
            ->label('Transferir saldo devedor')
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->color('warning')
            ->visible(fn (Repasse $record): bool => $record->podeEditar() && (float) $record->valor_liquido < 0 && self::podeAprovar())
            ->requiresConfirmation()
            ->modalDescription('O valor negativo vira adiantamento "saldo devedor" no próximo período, e este repasse fica zerado.')
            ->action(fn (Repasse $record, Action $action) => self::executar($action, 'Saldo devedor transferido', fn () => app(RepasseService::class)->transferirSaldoDevedor($record, auth()->user())));
    }

    public static function recibo(): Action
    {
        return Action::make('recibo')
            ->label('Recibo')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->visible(fn (Repasse $record): bool => in_array($record->status, [StatusRepasse::Aprovado, StatusRepasse::Pago], true))
            ->url(fn (Repasse $record): string => route('ponto.repasses.recibo', $record))
            ->openUrlInNewTab();
    }

    private static function campoMotivo(): Textarea
    {
        return Textarea::make('motivo')->label('Motivo')->required()->minLength(10)->rows(2);
    }

    private static function podeAprovar(): bool
    {
        return auth()->user()?->can('aprovar:repasse') ?? false;
    }

    private static function executar(Action $action, string $sucesso, callable $operacao): void
    {
        try {
            $operacao();
        } catch (PontoException|FinanceiroException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }

        Notification::make()->title($sucesso)->success()->send();
    }
}
