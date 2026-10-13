<?php

namespace App\Filament\Resources\ChecklistNaoConformidades;

use App\Filament\Resources\ChecklistNaoConformidades\Pages\EditChecklistNaoConformidade;
use App\Filament\Resources\ChecklistNaoConformidades\Pages\ListChecklistNaoConformidades;
use App\Filament\Resources\ChecklistNaoConformidades\Schemas\ChecklistNaoConformidadeForm;
use App\Filament\Resources\ChecklistNaoConformidades\Tables\ChecklistNaoConformidadesTable;
use App\Models\ChecklistNaoConformidade;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** NCs nascem na finalização da execução; aqui a gestão trata (responsável, prazo, ação, resolução). */
class ChecklistNaoConformidadeResource extends Resource
{
    protected static ?string $model = ChecklistNaoConformidade::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static UnitEnum|string|null $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Não conformidades';

    protected static ?string $modelLabel = 'não conformidade';

    protected static ?string $pluralModelLabel = 'não conformidades';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $slug = 'checklist-nao-conformidades';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return ChecklistNaoConformidadeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChecklistNaoConformidadesTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $abertas = ChecklistNaoConformidade::pendentes()->count();

        return $abertas > 0 ? (string) $abertas : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Não conformidades abertas ou em andamento';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecklistNaoConformidades::route('/'),
            'edit' => EditChecklistNaoConformidade::route('/{record}/edit'),
        ];
    }
}
