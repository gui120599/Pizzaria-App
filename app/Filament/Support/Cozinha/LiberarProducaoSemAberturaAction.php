<?php

namespace App\Filament\Support\Cozinha;

use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Services\Cozinha\ProducaoService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Com o bloqueio de produção sem checklist de abertura ligado
 * (cozinha.checklists.bloquear_producao_sem_abertura), o gerente libera um
 * setor para o dia com justificativa — fica no histórico do setor.
 */
class LiberarProducaoSemAberturaAction
{
    public static function make(): Action
    {
        return Action::make('liberarProducaoSemAbertura')
            ->label('Liberar produção sem abertura')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('warning')
            ->visible(fn (): bool => (bool) config('cozinha.ativo') && (bool) config('cozinha.checklists.bloquear_producao_sem_abertura')
                && Gate::allows('liberarSemAbertura', ProducaoItem::class))
            ->modalDescription('Libera o "Iniciar" no tablet do setor hoje, sem o checklist de abertura concluído.')
            ->schema([
                Select::make('setor_producao_id')
                    ->label('Setor')
                    ->options(fn (): array => SetorProducao::ativos()->deProducao()->ordenados()->get()
                        ->filter(fn (SetorProducao $setor) => app(ProducaoService::class)->aberturaPendente($setor))
                        ->pluck('nome', 'id')
                        ->all())
                    ->helperText('Só aparecem os setores com a abertura de hoje pendente.')
                    ->required(),
                Textarea::make('justificativa')
                    ->label('Justificativa')
                    ->placeholder('Ex.: checklist feito no papel, tablet sem rede')
                    ->required()
                    ->minLength(5)
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $setor = SetorProducao::findOrFail($data['setor_producao_id']);

                app(ProducaoService::class)->liberarSemAbertura($setor, auth()->user(), $data['justificativa']);

                Notification::make()->success()->title("{$setor->nome} liberado para produzir hoje")->send();
            });
    }
}
