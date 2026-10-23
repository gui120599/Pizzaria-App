<?php

namespace App\Filament\Resources\FechamentosCaixa\Support;

use App\Enums\StatusFechamentoCaixa;
use App\Exceptions\FinanceiroException;
use App\Models\FechamentoCaixa;
use App\Services\FechamentoCaixaService;
use App\Services\Financeiro\ContabilizacaoFechamentoService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Trava o fechamento pra edição direta (mesmo padrão de Lancamento::Pago) — a
 * única forma de corrigir depois é "Reabrir fechamento" (ReabrirFechamentoAction).
 * Confirmar também contabiliza a sessão (ContabilizacaoFechamentoService):
 * títulos no Contas a Receber e movimentações nas contas financeiras, tudo na
 * mesma transação da confirmação. Configuração pendente ou conferência do
 * Caixa PDV que não fecha bloqueiam a confirmação sem gravar nada.
 */
class ConfirmarFechamentoAction
{
    public static function make(string $name = 'confirmarFechamento'): Action
    {
        return Action::make($name)
            ->label('Confirmar fechamento')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (FechamentoCaixa $record): bool => $record->status === StatusFechamentoCaixa::Rascunho)
            ->requiresConfirmation()
            ->modalHeading('Confirmar fechamento de caixa')
            ->modalDescription(function (FechamentoCaixa $record): HtmlString|string {
                $texto = 'Trava a contagem e as maquininhas, lança as vendas nas contas financeiras e gera os títulos no Contas a Receber. Pra corrigir depois, use "Reabrir fechamento".';
                $pendencias = app(ContabilizacaoFechamentoService::class)->pendenciasDeConfiguracao($record->sessaoCaixa);

                if ($pendencias === []) {
                    return $texto;
                }

                return new HtmlString(e($texto).'<br><br><strong>Pendências que impedem a confirmação:</strong><ul class="list-disc pl-5">'
                    .collect($pendencias)->map(fn (string $pendencia): string => '<li>'.e($pendencia).'</li>')->implode('').'</ul>');
            })
            ->modalSubmitAction(fn (Action $action, FechamentoCaixa $record): Action|false => app(ContabilizacaoFechamentoService::class)->pendenciasDeConfiguracao($record->sessaoCaixa) === [] ? $action : false)
            ->action(function (FechamentoCaixa $record, FechamentoCaixaService $service, ContabilizacaoFechamentoService $contabilizacao, Action $action): void {
                $sessao = $record->sessaoCaixa()->first();
                $pendencias = $contabilizacao->pendenciasDeConfiguracao($sessao);

                if ($record->valor_troco_gaveta === null) {
                    $pendencias[] = 'Informe o troco que fica no caixa antes de confirmar.';
                }

                if ($pendencias !== []) {
                    Notification::make()->title('Não foi possível confirmar o fechamento')->body(implode(' ', $pendencias))->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                try {
                    DB::transaction(function () use ($record, $service, $contabilizacao, $sessao): void {
                        $service->confirmar($record);

                        if ($contabilizacao->podeContabilizar($sessao->refresh())) {
                            $contabilizacao->contabilizar($sessao);
                        }
                    });
                } catch (ValidationException|FinanceiroException $e) {
                    $record->refresh();

                    Notification::make()
                        ->title('Não foi possível confirmar o fechamento')
                        ->body($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Fechamento confirmado com sucesso!')
                    ->body($contabilizacao->avisos === [] ? null : implode(' ', array_unique($contabilizacao->avisos)))
                    ->success()
                    ->send();
            });
    }
}
