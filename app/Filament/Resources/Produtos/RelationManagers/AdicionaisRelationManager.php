<?php

namespace App\Filament\Resources\Produtos\RelationManagers;

use App\Filament\Resources\Adicionais\AdicionalResource;
use App\Models\Adicional;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class AdicionaisRelationManager extends RelationManager
{
    protected static string $relationship = 'adicionais';

    protected static ?string $title = 'Adicionais';

    protected static ?string $modelLabel = 'adicional';

    protected static ?string $pluralModelLabel = 'adicionais';

    /** Vincular/desvincular altera o produto: exige poder editá-lo (o Filament não autoriza attach/detach sozinho). */
    private function podeEditarProduto(): bool
    {
        return Gate::allows('update', $this->getOwnerRecord());
    }

    public function form(Schema $schema): Schema
    {
        return AdicionalResource::form($schema);
    }

    /** Vincula/desvincula adicionais; a edição do adicional em si fica no AdicionalResource. */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('adicional_nome')
            ->defaultSort('adicional_nome')
            ->emptyStateHeading('Nenhum adicional vinculado')
            ->emptyStateDescription('Vincule adicionais para oferecê-los junto deste produto.')
            ->emptyStateIcon('heroicon-o-plus-circle')
            ->columns([
                ImageColumn::make('adicional_foto')
                    ->label('')
                    ->state(fn (Adicional $record): string => $record->getImagemUrl())
                    ->circular()
                    ->size(32),

                TextColumn::make('adicional_nome')
                    ->label('Adicional')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('adicional_valor')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Vincular adicionais')
                    ->authorize(fn (): bool => $this->podeEditarProduto())
                    ->preloadRecordSelect()
                    ->multiple()
                    ->recordSelectSearchColumns(['adicional_nome'])
                    ->recordTitle(fn (Model $record): string => $record->adicional_nome.' — R$ '.number_format((float) $record->adicional_valor, 2, ',', '.')),

                CreateAction::make()
                    ->label('Novo adicional')
                    ->authorize(fn (): bool => $this->podeEditarProduto() && Gate::allows('create', Adicional::class)),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label('Desvincular')
                    ->authorize(fn (): bool => $this->podeEditarProduto()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label('Desvincular selecionados')
                        ->authorize(fn (): bool => $this->podeEditarProduto()),
                ]),
            ]);
    }
}
