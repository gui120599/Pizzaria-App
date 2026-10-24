<?php

namespace App\Filament\Resources\Comissoes\Tables\Actions;

use App\Enums\FormaPagamentoComissaoEnum;
use App\Enums\StatusComissaoEnum;
use App\Models\Comissao;
use App\Services\PagamentoComissaoService;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Agrupa comissões aprovadas de um mesmo influenciador num único pagamento.
 * Regra de negócio (transação, criação do cupom de crédito) vive em
 * PagamentoComissaoService — esta classe só monta a UI (modal de forma de
 * pagamento) e delega.
 */
class GerarPagamentoBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('gerarPagamento')
            ->label('Gerar pagamento')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->authorize(fn (): bool => (bool) auth()->user()?->can('create:resgate_carteira'))
            ->modalHeading('Gerar pagamento de comissão')
            ->modalDescription('Todas as comissões selecionadas precisam ser do mesmo influenciador e estar aprovadas.')
            ->modalSubmitActionLabel('Gerar pagamento')
            ->form([
                Select::make('forma')
                    ->label('Forma de Pagamento')
                    ->options([
                        FormaPagamentoComissaoEnum::PIX->value => FormaPagamentoComissaoEnum::PIX->label(),
                        FormaPagamentoComissaoEnum::CREDITO_PIZZA->value => FormaPagamentoComissaoEnum::CREDITO_PIZZA->label(),
                    ])
                    ->required()
                    ->native(false)
                    ->live(),

                FileUpload::make('comprovante')
                    ->label('Comprovante')
                    ->disk('public')
                    ->directory('comprovantes_comissao')
                    ->visible(fn (callable $get): bool => $get('forma') === FormaPagamentoComissaoEnum::PIX->value),
            ])
            ->action(function (Collection $records, array $data, PagamentoComissaoService $service): void {
                $forma = FormaPagamentoComissaoEnum::from($data['forma']);

                try {
                    $pagamento = $service->gerar($records, $forma, $data['comprovante'] ?? null);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Não foi possível gerar o pagamento')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()
                        ->title('Falha ao gerar pagamento')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Pagamento gerado com sucesso')
                    ->body("Pagamento #{$pagamento->id} no valor de R$ ".number_format((float) $pagamento->pgcom_valor_total, 2, ',', '.'))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn (Collection $records): bool => $records->every(fn (Comissao $c) => $c->comissao_status === StatusComissaoEnum::APROVADA));
    }
}
