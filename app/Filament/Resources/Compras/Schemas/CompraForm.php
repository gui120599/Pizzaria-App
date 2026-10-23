<?php

namespace App\Filament\Resources\Compras\Schemas;

use App\Enums\PrestadorCategoriaEnum;
use App\Filament\Resources\CentroCustos\CentroCustoResource;
use App\Filament\Resources\Compras\Support\OperacaoFiscalView;
use App\Filament\Resources\Fornecedores\FornecedorResource;
use App\Models\CentroCusto;
use App\Models\Compra;
use App\Models\Prestador;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Leandrocfe\FilamentPtbrFormFields\Money;

class CompraForm
{
    public static function configure(Schema $schema): Schema
    {
        $bloqueado = fn (?Compra $record): bool => $record !== null && ! $record->isRascunho();

        return $schema
            ->components([
                Section::make('1. Dados da Nota')
                    ->description('Quem vendeu e quando entra no estoque. Depois de salvar, você adiciona os itens.')
                    ->icon('heroicon-o-document-text')
                    ->columns(3)
                    ->columnSpan(6)
                    ->disabled($bloqueado)
                    ->schema([
                        Placeholder::make('origem_xml_info')
                            ->label('Origem')
                            ->columnSpanFull()
                            ->visible(fn (?Compra $record): bool => $record?->compra_origem === 'xml')
                            ->content(fn (?Compra $record): string => 'Importado via XML — Chave de acesso: '.($record?->compra_chave_nfe ?? '—')),

                        Select::make('compra_prestador_id')
                            ->label('Fornecedor')
                            ->placeholder('Selecione o fornecedor')
                            ->options(fn (): array => Prestador::fornecedores()
                                ->orderBy('nome')
                                ->get()
                                ->mapWithKeys(fn (Prestador $p) => [$p->id => $p->nome_exibicao])
                                ->toArray())
                            ->searchable()
                            ->native(false)
                            ->columnSpan(2)
                            ->createOptionForm(fn (Schema $schema) => FornecedorResource::form($schema))
                            ->createOptionAction(fn (Action $action) => $action
                                ->modalHeading('Cadastrar novo fornecedor')
                                ->slideOver()
                            )
                            ->createOptionUsing(function (array $data): int {
                                $data['categoria'] = PrestadorCategoriaEnum::FORNECEDOR;

                                return Prestador::create($data)->getKey();
                            }),

                        Select::make('compra_centro_custo_id')
                            ->label('Centro de custo')
                            ->options(fn (): array => CentroCusto::query()->orderBy('centro_custo_nome')->pluck('centro_custo_nome', 'id')->toArray())
                            ->searchable()
                            ->native(false)
                            ->placeholder('Opcional')
                            ->columnSpan(1)
                            ->createOptionForm(fn (Schema $schema) => CentroCustoResource::form($schema))
                            ->createOptionAction(fn (Action $action) => $action
                                ->modalHeading('Cadastrar novo centro de custo')
                                ->modalWidth('lg')
                            )
                            ->createOptionUsing(function (array $data): int {
                                return CentroCusto::create($data)->getKey();
                            }),

                        TextInput::make('compra_numero')->label('Número da NF')->columnSpan(1),
                        TextInput::make('compra_serie')->label('Série')->columnSpan(1),
                        DatePicker::make('compra_data_emissao')->label('Data de emissão')->columnSpan(1),
                        DatePicker::make('compra_data_entrada')
                            ->label('Entrada no estoque')
                            ->default(now())
                            ->required()
                            ->columnSpan(1)
                            ->helperText('Data usada nas movimentações'),

                        Textarea::make('compra_observacao')->label('Observação')->rows(5)->columnSpanFull(),
                    ]),

                Section::make('2. Acréscimos e Resumo')
                    ->description('Frete e despesas são rateados entre os itens ao confirmar.')
                    ->icon('heroicon-o-calculator')
                    ->columns(1)
                    ->columnSpan(3)
                    ->disabled($bloqueado)
                    ->schema([
                        Money::make('compra_valor_frete')->label('Frete')->default(0),
                        Money::make('compra_valor_desconto')->label('Desconto')->default(0),
                        Money::make('compra_valor_outros')->label('Outras despesas')->default(0),

                        Placeholder::make('resumo_produtos')
                            ->label('Total dos produtos')
                            ->content(fn (?Compra $record): string => 'R$ '.number_format((float) ($record?->compra_valor_produtos ?? 0), 2, ',', '.')),

                        Placeholder::make('resumo_total')
                            ->label('Total da nota')
                            ->content(fn (?Compra $record): string => 'R$ '.number_format((float) ($record?->compra_valor_total ?? 0), 2, ',', '.')),

                        Placeholder::make('resumo_itens')
                            ->label('Itens lançados')
                            ->content(fn (?Compra $record): string => (string) ($record?->itens()->count() ?? 0)),
                    ]),

                Section::make('3. Operação fiscal')
                    ->description('Lida do XML e sugerida pelo sistema. Somente leitura: por enquanto não muda nada no estoque nem no financeiro.')
                    ->icon('heroicon-o-scale')
                    ->columns(3)
                    ->columnSpanFull()
                    ->collapsible()
                    ->visible(fn (?Compra $record): bool => $record?->temDadosFiscais() ?? false)
                    ->schema([
                        Placeholder::make('fiscal_operacao')
                            ->label('Operação sugerida')
                            ->content(fn (?Compra $record): HtmlString => OperacaoFiscalView::badgeOperacao($record)),

                        Placeholder::make('fiscal_confianca')
                            ->label('Confiança')
                            ->content(fn (?Compra $record): HtmlString => OperacaoFiscalView::badgeConfianca($record)),

                        Placeholder::make('fiscal_cfops')
                            ->label('CFOP dos itens')
                            ->content(fn (?Compra $record): string => OperacaoFiscalView::cfops($record)),

                        Placeholder::make('fiscal_nat_op')
                            ->label('Natureza da operação')
                            ->content(fn (?Compra $record): string => $record->compra_nat_op ?: '—'),

                        Placeholder::make('fiscal_finalidade')
                            ->label('Finalidade (finNFe)')
                            ->content(fn (?Compra $record): string => OperacaoFiscalView::finalidade($record->compra_fin_nfe)),

                        Placeholder::make('fiscal_tp_nf')
                            ->label('Tipo da nota (tpNF)')
                            ->content(fn (?Compra $record): string => OperacaoFiscalView::tipoNf($record->compra_tp_nf)),

                        Placeholder::make('fiscal_criterios')
                            ->label('Critérios')
                            ->columnSpanFull()
                            ->content(fn (?Compra $record): HtmlString => OperacaoFiscalView::lista($record->compra_operacao_criterios['criterios'] ?? [])),

                        Placeholder::make('fiscal_alertas')
                            ->label('Alertas')
                            ->columnSpanFull()
                            ->visible(fn (?Compra $record): bool => ! empty($record?->compra_operacao_criterios['alertas']))
                            ->content(fn (?Compra $record): HtmlString => OperacaoFiscalView::lista($record->compra_operacao_criterios['alertas'] ?? [])),

                        Placeholder::make('fiscal_divergencia_valor')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (?Compra $record): bool => $record !== null && OperacaoFiscalView::avisoDivergenciaValor($record) !== null)
                            ->content(fn (?Compra $record): HtmlString => OperacaoFiscalView::avisoDivergenciaValor($record)),
                    ]),
            ])
            ->columns(9);
    }
}
