<?php

namespace App\Filament\Resources\ContasFinanceiras\Tables;

use App\Enums\Financeiro\TipoContaFinanceira;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Filament\Resources\ContasFinanceiras\Support\AjustarSaldoAction;
use App\Filament\Resources\ContasFinanceiras\Support\TransferirEntreContasAction;
use App\Filament\Resources\MovimentacoesFinanceiras\MovimentacaoFinanceiraResource;
use App\Models\ContaFinanceira;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ContasFinanceirasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('ordem')
            ->columns([
                TextColumn::make('nome')
                    ->label('Conta')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                // Caixa PDV: o dinheiro da gaveta da sessão aberta, não o cache das movimentações.
                TextColumn::make('saldo_atual')
                    ->label('Saldo')
                    ->state(fn (ContaFinanceira $record): string => $record->ehCaixaPdv()
                        ? str(ContaFinanceiraSelect::rotulo($record))->after(' — ')->toString()
                        : 'R$ '.number_format((float) $record->saldo_atual, 2, ',', '.'))
                    ->color(fn (ContaFinanceira $record): ?string => $record->ehCaixaPdv() ? null : ((float) $record->saldo_atual < 0 ? 'danger' : 'success'))
                    ->alignEnd(),
                IconColumn::make('permite_saldo_negativo')
                    ->label('Aceita negativo')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('banco')
                    ->label('Banco')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('deleted_at')
                    ->label('Situação')
                    ->state(fn (ContaFinanceira $record): string => $record->trashed() ? 'Desativada' : 'Ativa')
                    ->badge()
                    ->color(fn (ContaFinanceira $record): string => $record->trashed() ? 'gray' : 'success'),
            ])
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(TipoContaFinanceira::class),
                TrashedFilter::make()->label('Desativadas'),
            ])
            ->recordActions([
                Action::make('extrato')
                    ->label('Extrato')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color('gray')
                    ->url(fn (ContaFinanceira $record): string => MovimentacaoFinanceiraResource::getUrl('index', [
                        'filters' => ['conta_financeira_id' => ['value' => $record->id]],
                    ])),
                ActionGroup::make([
                    TransferirEntreContasAction::make('transferirDaConta'),
                    AjustarSaldoAction::make(),
                    EditAction::make(),
                    DeleteAction::make()->label('Desativar'),
                    RestoreAction::make()->label('Reativar'),
                ]),
            ]);
    }
}
