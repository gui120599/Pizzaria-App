<?php

namespace App\Filament\Resources\FechamentosCaixa\Tables;

use App\Enums\StatusFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\Support\ConfirmarFechamentoAction;
use App\Filament\Resources\FechamentosCaixa\Support\EstornarImportacaoReceberAction;
use App\Filament\Resources\FechamentosCaixa\Support\ImportarReceberAction;
use App\Filament\Resources\FechamentosCaixa\Support\ImportarReceberBulkAction;
use App\Filament\Resources\FechamentosCaixa\Support\ReabrirFechamentoAction;
use App\Models\FechamentoCaixa;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
                ->with(['sessaoCaixa.caixa', 'sessaoCaixa.maquininhas', 'sessaoCaixa.lancamentos', 'maquininhas', 'user'])
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
                IconColumn::make('importado')
                    ->label('Importado')
                    ->getStateUsing(fn (FechamentoCaixa $record): bool => $record->sessaoCaixa->lancamentos->isNotEmpty())
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StatusFechamentoCaixa::class),
                TernaryFilter::make('importado')
                    ->label('Importado p/ Contas a Receber')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('sessaoCaixa.lancamentos'),
                        false: fn (Builder $query) => $query->whereDoesntHave('sessaoCaixa.lancamentos'),
                    ),
            ])
            ->recordActions([
                ConfirmarFechamentoAction::make(),
                ReabrirFechamentoAction::make(),
                ImportarReceberAction::make(),
                EstornarImportacaoReceberAction::make(),
                EditAction::make()
                    ->visible(fn (FechamentoCaixa $record): bool => $record->status !== StatusFechamentoCaixa::Confirmado),
                DeleteAction::make()
                    ->visible(fn (FechamentoCaixa $record): bool => $record->status !== StatusFechamentoCaixa::Confirmado),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ImportarReceberBulkAction::make(),
                ]),
            ]);
    }
}
