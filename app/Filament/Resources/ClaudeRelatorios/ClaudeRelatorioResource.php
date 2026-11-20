<?php

namespace App\Filament\Resources\ClaudeRelatorios;

use App\Enums\TipoRelatorioClaude;
use App\Filament\Resources\ClaudeRelatorios\Pages\ListClaudeRelatorios;
use App\Filament\Resources\ClaudeRelatorios\Pages\ViewClaudeRelatorio;
use App\Models\ClaudeRelatorio;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Relatórios gravados pelas rotinas do Claude (resumo do dia, sugestão de
 * compras, anomalias). Só leitura; quem vê é quem usa o conector de gestão.
 */
class ClaudeRelatorioResource extends Resource
{
    protected static ?string $model = ClaudeRelatorio::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static UnitEnum|string|null $navigationGroup = 'Indicadores';

    protected static ?string $navigationLabel = 'Relatórios do Claude';

    protected static ?string $modelLabel = 'relatório do Claude';

    protected static ?string $pluralModelLabel = 'relatórios do Claude';

    protected static ?string $slug = 'relatorios-claude';

    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('usar:claude_gestao') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('tipo')->label('Tipo')->badge(),
            TextEntry::make('referencia')->label('Referente a')->date('d/m/Y')->placeholder('—'),
            TextEntry::make('created_at')->label('Gerado em')->dateTime('d/m/Y H:i'),
            TextEntry::make('user.name')->label('Conector de')->placeholder('—'),
            TextEntry::make('conteudo')->hiddenLabel()->markdown()->columnSpanFull(),
        ])->columns(4);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Gerado em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('tipo')->label('Tipo')->badge(),
                TextColumn::make('titulo')->label('Título')->searchable()->wrap(),
                TextColumn::make('referencia')->label('Referente a')->date('d/m/Y')->placeholder('—')->sortable(),
                TextColumn::make('user.name')->label('Conector de')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tipo')->label('Tipo')->options(TipoRelatorioClaude::class),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaudeRelatorios::route('/'),
            'view' => ViewClaudeRelatorio::route('/{record}'),
        ];
    }
}
