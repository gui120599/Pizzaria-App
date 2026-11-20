<?php

namespace App\Filament\Resources\ClaudeAcoes;

use App\Enums\Gestao\StatusAcaoClaude;
use App\Enums\Gestao\TipoAcaoClaude;
use App\Filament\Resources\ClaudeAcoes\Pages\ListClaudeAcoes;
use App\Filament\Resources\ClaudeAcoes\Pages\ViewClaudeAcao;
use App\Models\ClaudeAcao;
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
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Trilha das ações feitas pelo chat do Claude (G3): o que foi preparado,
 * por quem, o resumo que o usuário confirmou e o que foi gravado. Só
 * leitura; vê quem usa o conector de gestão.
 */
class ClaudeAcaoResource extends Resource
{
    protected static ?string $model = ClaudeAcao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static UnitEnum|string|null $navigationGroup = 'Indicadores';

    protected static ?string $navigationLabel = 'Ações do Claude';

    protected static ?string $modelLabel = 'ação do Claude';

    protected static ?string $pluralModelLabel = 'ações do Claude';

    protected static ?string $slug = 'acoes-claude';

    protected static ?int $navigationSort = 91;

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
            TextEntry::make('tipo')->label('Ação')->badge(),
            TextEntry::make('status')->label('Situação')->badge(),
            TextEntry::make('user.name')->label('Usuário')->placeholder('—'),
            TextEntry::make('created_at')->label('Preparada em')->dateTime('d/m/Y H:i'),
            TextEntry::make('confirmada_em')->label('Confirmada em')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextEntry::make('previa.resumo_texto')->label('Resumo mostrado ao usuário')
                ->state(fn (ClaudeAcao $record): ?string => $record->previa['resumo_texto'] ?? null)
                ->extraAttributes(['class' => 'whitespace-pre-line'])
                ->columnSpanFull(),
            TextEntry::make('resultado')->label('O que foi gravado')
                ->state(fn (ClaudeAcao $record): ?string => $record->resultado ? json_encode($record->resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null)
                ->fontFamily('mono')
                ->extraAttributes(['class' => 'whitespace-pre-wrap text-xs'])
                ->placeholder('Nada gravado.')
                ->columnSpanFull(),
        ])->columns(5);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Preparada em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('tipo')->label('Ação')->badge(),
                TextColumn::make('resumo')->label('Resumo')
                    ->state(fn (ClaudeAcao $record): string => Str::limit(strtok((string) ($record->previa['resumo_texto'] ?? ''), "\n") ?: '—', 90))
                    ->wrap(),
                TextColumn::make('status')->label('Situação')->badge(),
                TextColumn::make('user.name')->label('Usuário')->placeholder('—'),
                TextColumn::make('confirmada_em')->label('Confirmada em')->dateTime('d/m/Y H:i')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tipo')->label('Ação')->options(TipoAcaoClaude::class),
                SelectFilter::make('status')->label('Situação')->options(StatusAcaoClaude::class),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaudeAcoes::route('/'),
            'view' => ViewClaudeAcao::route('/{record}'),
        ];
    }
}
