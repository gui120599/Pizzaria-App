<?php

namespace App\Filament\Influenciador\Resources\Cupoms\Tables;

use App\Models\Cupom;
use App\Models\Influenciador;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CupomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('cupom_codigo')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('pedidos'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum cupom cadastrado')
            ->emptyStateDescription('Crie um cupom para seus seguidores usarem no cardápio.')
            ->columns([
                TextColumn::make('cupom_codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cupom_tipo_desconto')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->badge(),

                TextColumn::make('cupom_valor_desconto')
                    ->label('Desconto')
                    ->money('BRL'),

                TextColumn::make('cupom_valor_minimo_pedido')
                    ->label('Pedido Mínimo')
                    ->money('BRL')
                    ->placeholder('Sem mínimo'),

                TextColumn::make('pedidos_count')
                    ->label('Usos')
                    ->badge()
                    ->sortable(),

                TextColumn::make('cupom_limite_uso_total')
                    ->label('Limite Total')
                    ->placeholder('Ilimitado'),

                TextColumn::make('cupom_data_expiracao')
                    ->label('Expira em')
                    ->date('d/m/Y')
                    ->placeholder('Sem expiração')
                    ->sortable(),

                IconColumn::make('principal')
                    ->label('Principal')
                    ->state(fn (Cupom $record): bool => $record->id === Auth::guard('influenciador')->user()?->influenciador_cupom_principal_id)
                    ->boolean()
                    ->tooltip('Cupom que o seu link já preenche no cardápio'),

                IconColumn::make('cupom_ativo')
                    ->label('Ativo')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Cadastro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('definirPrincipal')
                    ->label('Tornar principal')
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (Cupom $record): bool => $record->cupom_ativo
                        && $record->id !== Auth::guard('influenciador')->user()?->influenciador_cupom_principal_id)
                    ->action(function (Cupom $record): void {
                        /** @var Influenciador $influenciador */
                        $influenciador = Auth::guard('influenciador')->user();
                        $influenciador->update(['influenciador_cupom_principal_id' => $record->id]);

                        Notification::make()->title('Cupom principal atualizado')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
