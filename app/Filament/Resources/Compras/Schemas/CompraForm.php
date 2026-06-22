<?php

namespace App\Filament\Resources\Compras\Schemas;

use App\Models\CentroCusto;
use App\Models\Compra;
use App\Models\Prestador;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                    ->disabled($bloqueado)
                    ->schema([
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
                            ->columnSpan(2),

                        Select::make('compra_centro_custo_id')
                            ->label('Centro de custo')
                            ->options(fn (): array => CentroCusto::query()->orderBy('centro_custo_nome')->pluck('centro_custo_nome', 'id')->toArray())
                            ->searchable()
                            ->native(false)
                            ->placeholder('Opcional')
                            ->columnSpan(1),

                        TextInput::make('compra_numero')->label('Número da NF')->columnSpan(1),
                        TextInput::make('compra_serie')->label('Série')->columnSpan(1),
                        DatePicker::make('compra_data_emissao')->label('Data de emissão')->columnSpan(1),
                        DatePicker::make('compra_data_entrada')
                            ->label('Entrada no estoque')
                            ->default(now())
                            ->required()
                            ->columnSpan(1)
                            ->helperText('Data usada nas movimentações'),

                        Textarea::make('compra_observacao')->label('Observação')->rows(2)->columnSpanFull(),
                    ]),

                Section::make('2. Acréscimos e Resumo')
                    ->description('Frete e despesas são rateados entre os itens ao confirmar.')
                    ->icon('heroicon-o-calculator')
                    ->columns(3)
                    ->disabled($bloqueado)
                    ->schema([
                        TextInput::make('compra_valor_frete')->label('Frete')->numeric()->step(0.01)->default(0)->prefix('R$'),
                        TextInput::make('compra_valor_desconto')->label('Desconto')->numeric()->step(0.01)->default(0)->prefix('R$'),
                        TextInput::make('compra_valor_outros')->label('Outras despesas')->numeric()->step(0.01)->default(0)->prefix('R$'),

                        Placeholder::make('resumo_produtos')
                            ->label('Total dos produtos')
                            ->content(fn (?Compra $record): string => 'R$ ' . number_format((float) ($record?->compra_valor_produtos ?? 0), 2, ',', '.')),

                        Placeholder::make('resumo_total')
                            ->label('Total da nota')
                            ->content(fn (?Compra $record): string => 'R$ ' . number_format((float) ($record?->compra_valor_total ?? 0), 2, ',', '.')),

                        Placeholder::make('resumo_itens')
                            ->label('Itens lançados')
                            ->content(fn (?Compra $record): string => (string) ($record?->itens()->count() ?? 0)),
                    ]),
            ]);
    }
}
