<?php

namespace App\Filament\Cozinha\Concerns;

use App\Exceptions\ProducaoException;
use App\Models\DispositivoCozinha;
use App\Models\User;
use App\Services\Cozinha\OperadorCozinhaService;
use App\Support\Cozinha\Operador;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

/**
 * Operador ativo no topo das telas da cozinha (spec §5.8 e §6.2): a pessoa
 * toca no próprio nome (+ PIN, se configurado). No tablet da estação, sem
 * operador, as ações dos setores que exigem operador ficam bloqueadas.
 */
trait GerenciaOperadorCozinha
{
    #[Computed]
    public function operador(): ?Operador
    {
        return app(OperadorCozinhaService::class)->atual();
    }

    #[Computed]
    public function precisaEscolherOperador(): bool
    {
        return app(OperadorCozinhaService::class)->precisaEscolher();
    }

    /** Usuário cujas permissões valem para a ação: o operador ou a conta logada. */
    protected function executor(): ?User
    {
        return $this->operador?->usuario() ?? auth()->user();
    }

    protected function dispositivo(): ?DispositivoCozinha
    {
        return DispositivoCozinha::atual();
    }

    public function trocarOperadorAction(): Action
    {
        $servico = app(OperadorCozinhaService::class);

        return Action::make('trocarOperador')
            ->label(fn (): string => $this->operador?->nome ?? 'Quem está produzindo?')
            ->icon(Heroicon::OutlinedUserCircle)
            ->color(fn (): string => $this->operador ? 'gray' : 'warning')
            ->visible(fn (): bool => $this->precisaEscolherOperador)
            ->modalHeading('Quem está produzindo?')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Entrar')
            ->schema([
                ToggleButtons::make('user_id')
                    ->hiddenLabel()
                    ->icons(fn (): array => $servico->opcoes()->mapWithKeys(fn (User $u) => [$u->id => Heroicon::OutlinedUserCircle])->all())
                    ->options(fn (): array => $servico->opcoes()->mapWithKeys(fn (User $u) => [$u->id => $u->name_first ?: $u->name])->all())
                    ->columns(3)
                    ->required()
                    ->validationMessages(['required' => 'Toque no seu nome.']),
                TextInput::make('pin')
                    ->label('PIN')
                    ->password()
                    ->inputMode('numeric')
                    ->maxLength(8)
                    ->visible(fn (): bool => $servico->exigePin())
                    ->required(fn (): bool => $servico->exigePin()),
            ])
            ->extraModalFooterActions(fn (): array => $this->operador ? [
                Action::make('sairOperador')
                    ->label('Sair ('.$this->operador->nome.')')
                    ->color('gray')
                    ->cancelParentActions()
                    ->action(function (): void {
                        app(OperadorCozinhaService::class)->sair();
                        unset($this->operador);
                        $this->aoTrocarOperador();
                    }),
            ] : [])
            ->action(function (array $data, Action $action) use ($servico): void {
                try {
                    $operador = $servico->selecionar(User::findOrFail($data['user_id']), $data['pin'] ?? null);
                } catch (ProducaoException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                unset($this->operador);
                Notification::make()->success()->title("Bom trabalho, {$operador->nome}!")->send();
                $this->aoTrocarOperador();
            });
    }

    /** Ganchos das páginas (ex.: o checklist recarrega com a nova pessoa). */
    protected function aoTrocarOperador(): void {}
}
