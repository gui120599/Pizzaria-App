<?php

namespace App\Filament\Resources\Produtos\RelationManagers;

use App\Filament\Support\PerguntasSchema;
use App\Models\Pergunta;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Perguntas feitas só neste produto ao lançar o item ("Ponto da carne"). As
 * que valem para a categoria inteira (ex.: borda das pizzas) ficam no modal da
 * categoria.
 */
class PerguntasRelationManager extends RelationManager
{
    protected static string $relationship = 'perguntas';

    protected static ?string $title = 'Perguntas';

    protected static ?string $modelLabel = 'pergunta';

    protected static ?string $pluralModelLabel = 'perguntas';

    /** A pergunta faz parte do produto: criar, editar e excluir exigem poder editá-lo. */
    private function podeEditarProduto(): bool
    {
        return Gate::allows('update', $this->getOwnerRecord());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(PerguntasSchema::campos());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('pergunta_texto')
            ->modifyQueryUsing(fn ($query) => $query->withCount('opcoes'))
            ->reorderable('pergunta_ordem')
            ->defaultSort('pergunta_ordem')
            ->emptyStateHeading('Nenhuma pergunta')
            ->emptyStateDescription('Pergunte algo ao lançar este item, como o ponto da carne. Perguntas da categoria (ex.: borda) valem também.')
            ->emptyStateIcon('heroicon-o-question-mark-circle')
            ->columns([
                TextColumn::make('pergunta_texto')
                    ->label('Pergunta'),

                TextColumn::make('pergunta_minimo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (Pergunta $record): string => $record->obrigatoria() ? 'Obrigatória' : 'Opcional')
                    ->color(fn (Pergunta $record): string => $record->obrigatoria() ? 'danger' : 'gray'),

                TextColumn::make('pergunta_maximo')
                    ->label('Escolhas')
                    ->formatStateUsing(fn (Pergunta $record): string => $record->pergunta_maximo === 1 ? 'Única' : 'Até '.$record->pergunta_maximo),

                TextColumn::make('opcoes_count')
                    ->label('Opções'),

                IconColumn::make('pergunta_ativa')
                    ->label('Ativa')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nova pergunta')
                    ->authorize(fn (): bool => $this->podeEditarProduto()),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (): bool => $this->podeEditarProduto()),
                DeleteAction::make()
                    ->authorize(fn (): bool => $this->podeEditarProduto()),
            ]);
    }
}
