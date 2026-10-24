<?php

namespace App\Filament\Resources\Cupons\Tables;

use App\Enums\OrigemCupomEnum;
use App\Models\Cupom;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CupomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('cupom_codigo')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('influenciador')->withCount('pedidosValidos'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum cupom cadastrado')
            ->columns([
                TextColumn::make('cupom_codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cupom_origem')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => $state === OrigemCupomEnum::INFLUENCIADOR ? 'info' : 'gray'),

                TextColumn::make('influenciador.influenciador_nome')
                    ->label('Influenciador')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('cupom_tipo_desconto')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->badge(),

                TextColumn::make('cupom_valor_desconto')
                    ->label('Desconto')
                    ->state(fn (Cupom $record): string => $record->cupom_tipo_desconto?->value === 'percentual'
                        ? rtrim(rtrim(number_format((float) $record->cupom_valor_desconto, 2, ',', '.'), '0'), ',').'%'
                        : 'R$ '.number_format((float) $record->cupom_valor_desconto, 2, ',', '.')),

                TextColumn::make('pedidos_validos_count')
                    ->label('Usos')
                    ->badge()
                    ->sortable(),

                TextColumn::make('usos_restantes')
                    ->label('Restantes')
                    ->state(fn (Cupom $record): string => $record->cupom_limite_uso_total === null
                        ? 'Ilimitado'
                        : (string) max(0, $record->cupom_limite_uso_total - $record->pedidos_validos_count)),

                TextColumn::make('cupom_data_expiracao')
                    ->label('Vigência')
                    ->state(fn (Cupom $record): string => ($record->cupom_data_inicio?->format('d/m/Y') ?? '—').' a '.($record->cupom_data_expiracao?->format('d/m/Y') ?? 'sem fim')),

                IconColumn::make('cupom_ativo')
                    ->label('Ativo')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Cadastro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('cupom_origem')
                    ->label('Origem')
                    ->options(collect([OrigemCupomEnum::INTERNO, OrigemCupomEnum::INFLUENCIADOR])
                        ->mapWithKeys(fn (OrigemCupomEnum $origem) => [$origem->value => $origem->label()])
                        ->all()),

                SelectFilter::make('cupom_influenciador_id')
                    ->label('Influenciador')
                    ->relationship('influenciador', 'influenciador_nome')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('cupom_ativo')
                    ->label('Ativo'),

                Filter::make('vigente')
                    ->label('Vigente hoje')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('cupom_ativo', true)
                        ->where(fn (Builder $q) => $q->whereNull('cupom_data_inicio')->orWhereDate('cupom_data_inicio', '<=', now()))
                        ->where(fn (Builder $q) => $q->whereNull('cupom_data_expiracao')->orWhereDate('cupom_data_expiracao', '>=', now()))),

                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('alternarAtivo')
                    ->label(fn (Cupom $record): string => $record->cupom_ativo ? 'Desativar' : 'Ativar')
                    ->icon(fn (Cupom $record) => $record->cupom_ativo ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
                    ->color(fn (Cupom $record): string => $record->cupom_ativo ? 'danger' : 'success')
                    ->visible(fn (Cupom $record): bool => ! $record->trashed())
                    ->requiresConfirmation()
                    ->action(function (Cupom $record): void {
                        $record->update(['cupom_ativo' => ! $record->cupom_ativo]);

                        Notification::make()
                            ->title($record->cupom_ativo ? 'Cupom ativado' : 'Cupom desativado')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }
}
