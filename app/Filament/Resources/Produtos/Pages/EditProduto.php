<?php

namespace App\Filament\Resources\Produtos\Pages;

use App\Filament\Resources\Produtos\ProdutoResource;
use App\Models\MovimentacaoBalanco;
use App\Models\Produto;
use App\Services\BalancoEstoqueService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditProduto extends EditRecord
{
    protected static string $resource = ProdutoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('realizarBalanco')
                ->label('Realizar Balanço')
                ->icon('heroicon-o-scale')
                ->color('warning')
                ->visible(fn (): bool => (bool) $this->record->produto_controla_estoque)
                ->modalHeading('Balanço físico de estoque')
                ->modalDescription('Informe a quantidade contada fisicamente. O sistema calculará a diferença e registrará o ajuste automaticamente.')
                ->modalWidth('md')
                ->form(function (): array {
                    /** @var Produto $produto */
                    $produto = $this->record;
                    $saldo   = number_format((float) $produto->produto_saldo_estoque, 3, ',', '.');
                    $unidade = $produto->produto_unidade_estoque ?? '';

                    return [
                        Placeholder::make('saldo_atual')
                            ->label('Saldo atual no sistema')
                            ->content("{$saldo}" . ($unidade ? " {$unidade}" : '')),

                        TextInput::make('quantidade_fisica')
                            ->label('Quantidade física contada')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.001)
                            ->suffix($unidade ?: null)
                            ->required(),

                        Textarea::make('observacao')
                            ->label('Observação')
                            ->placeholder('Motivo do ajuste, responsável pela contagem...')
                            ->rows(2)
                            ->maxLength(500),
                    ];
                })
                ->action(function (array $data): void {
                    /** @var Produto $produto */
                    $produto = $this->record;

                    try {
                        $balanco = app(BalancoEstoqueService::class)->realizarBalanco(
                            produto: $produto,
                            quantidadeFisica: (float) $data['quantidade_fisica'],
                            observacao: $data['observacao'] ?? null,
                        );
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Erro ao registrar balanço')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($balanco === null) {
                        Notification::make()
                            ->title('Sem diferença de estoque')
                            ->body('O saldo físico é igual ao registrado no sistema. Nenhum ajuste foi gerado.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $tipo   = $balanco->mbal_tipo_movimentacao->label();
                    $ajuste = number_format((float) $balanco->mbal_quantidade_ajuste, 3, ',', '.');

                    Notification::make()
                        ->title('Balanço registrado com sucesso')
                        ->body("Ajuste de {$tipo}: {$ajuste}" . ($produto->produto_unidade_estoque ? " {$produto->produto_unidade_estoque}" : '') . '.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['produto_saldo_estoque']);
                }),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
