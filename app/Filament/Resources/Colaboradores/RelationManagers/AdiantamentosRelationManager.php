<?php

namespace App\Filament\Resources\Colaboradores\RelationManagers;

use App\Filament\Resources\Adiantamentos\Support\RegistrarAdiantamentoAction;
use App\Filament\Resources\Adiantamentos\Tables\AdiantamentosTable;
use App\Models\Colaborador;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Vales e consumo do colaborador. */
class AdiantamentosRelationManager extends RelationManager
{
    protected static string $relationship = 'adiantamentos';

    protected static ?string $title = 'Adiantamentos';

    protected static ?string $modelLabel = 'adiantamento';

    protected static ?string $pluralModelLabel = 'adiantamentos';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return AdiantamentosTable::configure($table, doColaborador: true)
            ->headerActions([
                RegistrarAdiantamentoAction::make(colaboradorFixo: function (): Colaborador {
                    /** @var Colaborador */
                    return $this->getOwnerRecord();
                }),
            ]);
    }
}
