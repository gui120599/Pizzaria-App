<?php

namespace App\Filament\Resources\FechamentosCaixa\Tables;

use App\Enums\StatusFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\Support\ConfirmarFechamentoAction;
use App\Filament\Resources\FechamentosCaixa\Support\ReabrirFechamentoAction;
use App\Models\FechamentoCaixa;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FechamentosCaixaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // totalDebito/Credito/Pix (FechamentoCaixa) precisam das coleções carregadas
            // (o carryover é abatido por maquininha, não dá pra somar direto via SQL).
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['sessaoCaixa.caixa', 'sessaoCaixa.maquininhas', 'maquininhas', 'user'])
                ->withSum('notas', 'valor_total'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('sessaoCaixa.caixa.caixa_nome')
                    ->label('Caixa')
                    ->placeholder('—'),
                TextColumn::make('sessaoCaixa.sessaocaixa_data_hora_fechamento')
                    ->label('Fechado em')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('user.name')
                    ->label('Responsável'),
                TextColumn::make('totalApurado')
                    ->label('Apurado')
                    ->getStateUsing(fn (FechamentoCaixa $record): float => $record->totalApurado)
                    ->money('BRL'),
                TextColumn::make('totalEsperadoGeral')
                    ->label('Esperado')
                    ->getStateUsing(fn (FechamentoCaixa $record): float => $record->totalEsperadoGeral)
                    ->money('BRL'),
                TextColumn::make('diferencaGeral')
                    ->label('Diferença')
                    ->getStateUsing(fn (FechamentoCaixa $record): float => $record->diferencaGeral)
                    ->money('BRL')
                    ->color(fn (FechamentoCaixa $record): string => $record->diferencaGeral == 0.0 ? 'success' : 'danger')
                    ->weight(FontWeight::Bold),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StatusFechamentoCaixa::class),
            ])
            ->recordActions([
                ConfirmarFechamentoAction::make(),
                ReabrirFechamentoAction::make(),
                EditAction::make()
                    ->visible(fn (FechamentoCaixa $record): bool => $record->status !== StatusFechamentoCaixa::Confirmado),
                DeleteAction::make()
                    ->visible(fn (FechamentoCaixa $record): bool => $record->status !== StatusFechamentoCaixa::Confirmado),
            ]);
    }
}
