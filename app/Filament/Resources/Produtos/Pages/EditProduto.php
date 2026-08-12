<?php

namespace App\Filament\Resources\Produtos\Pages;

use App\Enums\MovimentacaoTipoEnum;
use App\Filament\Components\MarcaSelect;
use App\Filament\Resources\Produtos\ProdutoResource;
use App\Filament\Support\CorrecaoEstoquePreview;
use App\Models\MovimentacaoProduto;
use App\Models\Produto;
use App\Services\BalancoEstoqueService;
use App\Services\CorrecaoEstoqueService;
use App\Services\EstoqueService;
use App\Support\CustoUnitarioFormatter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

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

                        $campos[] = DatePicker::make('validade')
                            ->label('Validade');
                    }

                    if ($produto->rastreiaLote()) {
                        $campos[] = MarcaSelect::make('marca_id');
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

            Action::make('corrigirMovimentacaoEstoque')
                ->label('Corrigir movimentação de estoque')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('warning')
                ->visible(fn (): bool => (bool) $this->record->produto_controla_estoque
                    && auth()->user()?->can('corrigir', MovimentacaoProduto::class))
                ->modalHeading('Corrigir movimentação de estoque')
                ->modalDescription('Escolha uma entrada lançada com quantidade ou custo errado (ex.: compra não conferida, fator de conversão errado). O sistema recalcula em cadeia o custo médio e tudo que saiu do estoque depois dela.')
                ->modalWidth('lg')
                ->form(function (): array {
                    /** @var Produto $produto */
                    $produto = $this->record;
                    $unidade = $produto->produto_unidade_estoque ?? '';

                    $opcoes = MovimentacaoProduto::where('mov_produto_id', $produto->id)
                        ->where('mov_tipo', MovimentacaoTipoEnum::ENTRADA)
                        ->orderByDesc('mov_data')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (MovimentacaoProduto $m): array => [$m->id => sprintf(
                            '%s — %s — %s %s a %s cada',
                            $m->mov_data?->format('d/m/Y H:i'),
                            $m->mov_origem?->label() ?? '—',
                            number_format((float) $m->mov_quantidade, 3, ',', '.'),
                            $unidade,
                            CustoUnitarioFormatter::formatar((float) $m->mov_custo_unitario),
                        )]);

                    return [
                        Select::make('movimentacao_id')
                            ->label('Movimentação a corrigir')
                            ->options($opcoes)
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $mov = $state ? MovimentacaoProduto::find($state) : null;
                                $set('quantidade_correta', $mov ? (float) $mov->mov_quantidade : null);
                                $set('custo_correto', $mov ? (float) $mov->mov_custo_unitario : null);
                            }),

                        TextInput::make('quantidade_correta')
                            ->label('Quantidade correta')
                            ->numeric()->step(0.001)->minValue(0.001)->required()
                            ->suffix($unidade ?: null)
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => filled($get('movimentacao_id'))),

                        TextInput::make('custo_correto')
                            ->label('Custo unitário correto')
                            ->numeric()->step(0.00000001)->minValue(0)->required()
                            ->prefix('R$')
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => filled($get('movimentacao_id'))),

                        Placeholder::make('previa')
                            ->label('Prévia do impacto')
                            ->visible(fn (Get $get): bool => filled($get('movimentacao_id')))
                            ->content(function (Get $get) use ($produto): HtmlString {
                                $mov = ($movId = $get('movimentacao_id')) ? MovimentacaoProduto::find($movId) : null;
                                $qtd = (float) ($get('quantidade_correta') ?? 0);
                                $custo = (float) ($get('custo_correto') ?? 0);

                                if (! $mov || $qtd <= 0) {
                                    return new HtmlString('Informe a quantidade e o custo corretos.');
                                }

                                $resultado = app(CorrecaoEstoqueService::class)->simular($produto, $mov, $qtd, $custo);

                                return CorrecaoEstoquePreview::resumo($resultado);
                            }),

                        Textarea::make('motivo')
                            ->label('Motivo da correção')
                            ->required()
                            ->rows(2)
                            ->placeholder('Ex.: item não conferido — comprado 1 CX de 15un, lançado como 1un.'),
                    ];
                })
                ->action(function (array $data): void {
                    /** @var Produto $produto */
                    $produto = $this->record;
                    $mov = MovimentacaoProduto::find($data['movimentacao_id']);

                    if (! $mov) {
                        Notification::make()->title('Movimentação não encontrada')->danger()->send();

                        return;
                    }

                    try {
                        app(CorrecaoEstoqueService::class)->aplicar(
                            $mov,
                            (float) $data['quantidade_correta'],
                            (float) $data['custo_correto'],
                            $data['motivo'],
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Não foi possível corrigir')
                            ->body(collect($e->errors())->flatten()->implode(' '))
                            ->danger()
                            ->send();

                        return;
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Erro ao corrigir movimentação')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $produto->refresh();

                    Notification::make()
                        ->title('Correção aplicada')
                        ->body('Novo saldo: '.number_format((float) $produto->produto_saldo_estoque, 3, ',', '.').($produto->produto_unidade_estoque ? " {$produto->produto_unidade_estoque}" : '').'.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['produto_saldo_estoque', 'produto_custo_medio']);
                }),

            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
