<?php

namespace App\Filament\Resources\Influenciadores\RelationManagers;

use App\Enums\StatusComissaoEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ComissoesRelationManager extends RelationManager
{
    protected static string $relationship = 'comissoes';

    protected static ?string $title = 'Comissões';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('comissao_pedido_id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('comissao_pedido_id')->label('Pedido')->prefix('#')->sortable(),
                TextColumn::make('cupom.cupom_codigo')->label('Cupom')->placeholder('—'),
                TextColumn::make('comissao_base_calculo')->label('Base')->money('BRL'),
                TextColumn::make('comissao_valor')->label('Comissão')->money('BRL'),
                TextColumn::make('comissao_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => match ($state) {
                        StatusComissaoEnum::PAGA => 'success',
                        StatusComissaoEnum::APROVADA => 'info',
                        StatusComissaoEnum::PENDENTE => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Criada em')->dateTime('d/m/Y H:i')->sortable(),
            ]);
    }
}
