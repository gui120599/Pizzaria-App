<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Produtos\ProdutoResource;
use App\Models\Produto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Produtos cadastrados pela automação de compras (agente com autonomia
 * "completa"): ficam marcados até alguém revisar e aprovar.
 */
class RevisaoProdutosAutomacao extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $routePath = '/revisao-produtos-automacao';

    protected static ?string $title = 'Revisão de produtos da automação';

    protected static ?string $navigationLabel = 'Revisão de produtos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.revisao-produtos-automacao';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('revisar:produto_automacao') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && Produto::query()->where('produto_cadastro_pendente_revisao', true)->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Produto::query()->where('produto_cadastro_pendente_revisao', true))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum produto aguardando revisão')
            ->columns([
                TextColumn::make('produto_descricao')->label('Produto')->searchable()
                    ->url(fn (Produto $record): string => ProdutoResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('produto_tipo')->label('Tipo')->badge(),
                TextColumn::make('produto_unidade_estoque')->label('Unidade')->placeholder('—'),
                TextColumn::make('produto_codigo_EAN')->label('EAN')->placeholder('—'),
                TextColumn::make('created_at')->label('Cadastrado em')->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                Action::make('aprovar')
                    ->label('Aprovar')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->action(function (Produto $record): void {
                        $record->forceFill(['produto_cadastro_pendente_revisao' => false])->save();
                        Notification::make()->title('Produto aprovado')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('aprovarEmLote')
                    ->label('Aprovar')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        Produto::query()->whereKey($records->modelKeys())->update(['produto_cadastro_pendente_revisao' => false]);
                        Notification::make()->title($records->count().' produto(s) aprovado(s)')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
