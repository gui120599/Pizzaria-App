<?php

namespace App\Filament\Resources\Contratos\RelationManagers;

use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Models\Lancamento;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lista somente-leitura dos lançamentos já gerados por este contrato (governança:
 * ver de relance o histórico sem sair da tela do contrato). Gestão do lançamento
 * em si (registrar pagamento, estornar, editar) continua só no LancamentoResource.
 */
class LancamentosRelationManager extends RelationManager
{
    protected static string $relationship = 'lancamentos';

    protected static ?string $title = 'Lançamentos gerados';

    protected static ?string $modelLabel = 'lançamento';

    protected static ?string $pluralModelLabel = 'lançamentos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('competencia', 'desc')
            ->columns([
                TextColumn::make('competencia')
                    ->label('Competência')
                    ->date('m/Y'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->money('BRL'),
                TextColumn::make('vencimento')
                    ->label('Vencimento')
                    ->date('d/m/Y'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->filters([])
            ->headerActions([])
            ->recordActions([
                Action::make('abrir')
                    ->label('Abrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Lancamento $record): string => LancamentoResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }
}
