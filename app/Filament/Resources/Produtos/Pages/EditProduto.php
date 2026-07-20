<?php

namespace App\Filament\Resources\Produtos\Pages;

use App\Filament\Components\MarcaSelect;
use App\Filament\Resources\Produtos\ProdutoResource;
use App\Models\Produto;
use App\Services\BalancoEstoqueService;
use App\Services\EstoqueService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduto extends EditRecord
{
    protected static string $resource = ProdutoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registrarProducao')
                ->label('Registrar Produção')
                ->icon('heroicon-o-beaker')
                ->color('success')
                ->visible(fn (): bool => (bool) $this->record->produto_controla_estoque && $this->record->temFichaTecnica())
                ->modalHeading('Registrar produção')
                ->modalDescription('Informe a quantidade produzida. O sistema baixa os insumos da ficha técnica (recursivamente, se algum deles também for produzido) e credita o saldo deste produto.')
                ->modalWidth('md')
                ->form(function (): array {
                    /** @var Produto $produto */
                    $produto = $this->record;
                    $unidade = $produto->produto_unidade_estoque ?? '';

                    $campos = [
                        TextInput::make('quantidade_produzida')
                            ->label('Quantidade produzida')
                            ->numeric()
                            ->minValue(0.001)
                            ->step(0.001)
                            ->suffix($unidade ?: null)
                            ->required(),
                    ];

                    if ($produto->produto_controla_lote) {
                        $campos[] = TextInput::make('lote_codigo')
                            ->label('Código do lote')
                            ->maxLength(50);

                        $campos[] = DatePicker::make('validade')
                            ->label('Validade');
                    }

                    $campos[] = Textarea::make('observacao')
                        ->label('Observação')
                        ->placeholder('Ex.: sova da manhã, lote de muçarela ralada...')
                        ->rows(2)
                        ->maxLength(500);

                    return $campos;
                })
                ->action(function (array $data): void {
                    /** @var Produto $produto */
                    $produto = $this->record;
                    $service = app(EstoqueService::class);
                    $quantidade = (float) $data['quantidade_produzida'];

                    try {
                        $avisos = $service->validarDisponibilidade($produto, $quantidade);

                        $service->registrarProducao($produto, $quantidade, [
                            'lote_codigo' => $data['lote_codigo'] ?? null,
                            'validade' => $data['validade'] ?? null,
                            'motivo' => $data['observacao'] ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Erro ao registrar produção')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    foreach ($avisos as $aviso) {
                        Notification::make()
                            ->title('Aviso de estoque')
                            ->body($aviso)
                            ->warning()
                            ->send();
                    }

                    $produto->refresh();

                    Notification::make()
                        ->title('Produção registrada')
                        ->body('Novo saldo: '.number_format((float) $produto->produto_saldo_estoque, 3, ',', '.').($produto->produto_unidade_estoque ? " {$produto->produto_unidade_estoque}" : '').'.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['produto_saldo_estoque', 'produto_custo_medio']);
                }),

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
                    $saldo = number_format((float) $produto->produto_saldo_estoque, 3, ',', '.');
                    $unidade = $produto->produto_unidade_estoque ?? '';

                    $campos = [
                        Placeholder::make('saldo_atual')
                            ->label('Saldo atual no sistema')
                            ->content("{$saldo}".($unidade ? " {$unidade}" : '')),

                        TextInput::make('quantidade_fisica')
                            ->label('Quantidade física contada')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.001)
                            ->suffix($unidade ?: null)
                            ->required(),
                    ];

                    if ($produto->produto_controla_lote) {
                        $campos[] = TextInput::make('lote_codigo')
                            ->label('Lote')
                            ->helperText('Se a contagem apurar sobra, essa sobra vira um lote novo com esses dados.');

                        $campos[] = MarcaSelect::make('marca_id');

                        $campos[] = DatePicker::make('validade')
                            ->label('Validade');
                    }

                    $campos[] = Textarea::make('observacao')
                        ->label('Observação')
                        ->placeholder('Motivo do ajuste, responsável pela contagem...')
                        ->rows(2)
                        ->maxLength(500);

                    return $campos;
                })
                ->action(function (array $data): void {
                    /** @var Produto $produto */
                    $produto = $this->record;

                    try {
                        $balanco = app(BalancoEstoqueService::class)->realizarBalanco(
                            produto: $produto,
                            quantidadeFisica: (float) $data['quantidade_fisica'],
                            observacao: $data['observacao'] ?? null,
                            loteCodigo: $data['lote_codigo'] ?? null,
                            marcaId: $data['marca_id'] ?? null,
                            validade: $data['validade'] ?? null,
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

                    $tipo = $balanco->mbal_tipo_movimentacao->label();
                    $ajuste = number_format((float) $balanco->mbal_quantidade_ajuste, 3, ',', '.');

                    Notification::make()
                        ->title('Balanço registrado com sucesso')
                        ->body("Ajuste de {$tipo}: {$ajuste}".($produto->produto_unidade_estoque ? " {$produto->produto_unidade_estoque}" : '').'.')
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
