<?php

namespace App\Filament\Resources\ChecklistExecucoes;

use App\Filament\Resources\ChecklistExecucoes\Pages\ListChecklistExecucoes;
use App\Filament\Resources\ChecklistExecucoes\Pages\ViewChecklistExecucao;
use App\Filament\Resources\ChecklistExecucoes\Schemas\ChecklistExecucaoInfolist;
use App\Filament\Resources\ChecklistExecucoes\Tables\ChecklistExecucoesTable;
use App\Models\ChecklistExecucao;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Somente leitura + aprovação: execuções nascem e mudam pelo ChecklistExecucaoService. */
class ChecklistExecucaoResource extends Resource
{
    protected static ?string $model = ChecklistExecucao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static UnitEnum|string|null $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Execuções de checklist';

    protected static ?string $modelLabel = 'execução de checklist';

    protected static ?string $pluralModelLabel = 'execuções de checklist';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $slug = 'checklist-execucoes';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return ChecklistExecucaoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChecklistExecucoesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecklistExecucoes::route('/'),
            'view' => ViewChecklistExecucao::route('/{record}'),
        ];
    }
}
