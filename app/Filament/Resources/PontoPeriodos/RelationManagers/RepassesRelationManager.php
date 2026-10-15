<?php

namespace App\Filament\Resources\PontoPeriodos\RelationManagers;

use App\Filament\Resources\Repasses\RepasseResource;
use App\Filament\Resources\Repasses\Tables\RepassesTable;
use App\Models\Repasse;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class RepassesRelationManager extends RelationManager
{
    protected static string $relationship = 'repasses';

    protected static ?string $title = 'Repasses';

    protected static ?string $modelLabel = 'repasse';

    protected static ?string $pluralModelLabel = 'repasses';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return RepassesTable::configure($table, doPeriodo: true)
            ->recordUrl(fn (Repasse $record): string => RepasseResource::getUrl('view', ['record' => $record]));
    }
}
