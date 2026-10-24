<?php

namespace App\Filament\Resources\Influenciadores\RelationManagers;

use App\Models\Cupom;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CuponsRelationManager extends RelationManager
{
    protected static string $relationship = 'cupons';

    protected static ?string $title = 'Cupons';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('cupom_codigo')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('pedidosValidos'))
            ->columns([
                TextColumn::make('cupom_codigo')->label('Código')->searchable(),
                TextColumn::make('cupom_tipo_desconto')->label('Tipo')->formatStateUsing(fn ($state) => $state?->label())->badge(),
                TextColumn::make('cupom_valor_desconto')->label('Desconto')->numeric(2),
                TextColumn::make('pedidos_validos_count')->label('Usos')->badge(),
                TextColumn::make('cupom_limite_uso_total')->label('Limite')->placeholder('Ilimitado'),
                IconColumn::make('cupom_ativo')->label('Ativo')->boolean(),
            ])
            ->recordActions([
                Action::make('alternarAtivo')
                    ->label(fn (Cupom $record): string => $record->cupom_ativo ? 'Desativar' : 'Ativar')
                    ->icon(fn (Cupom $record) => $record->cupom_ativo ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->action(function (Cupom $record): void {
                        $record->update(['cupom_ativo' => ! $record->cupom_ativo]);

                        Notification::make()->title($record->cupom_ativo ? 'Cupom ativado' : 'Cupom desativado')->success()->send();
                    }),
            ]);
    }
}
