<?php

namespace App\Filament\Resources\ExtratoBancarioItens;

use App\Enums\Financeiro\StatusConciliacaoExtrato;
use App\Enums\StatusLancamento;
use App\Filament\Resources\ExtratoBancarioItens\Pages\ListExtratoBancarioItens;
use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Models\ExtratoBancarioItem;
use App\Models\Lancamento;
use App\Services\Financeiro\ConciliacaoBoletoService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Extrato bancário importado por API (Banco do Brasil): somente leitura,
 * com conciliação manual dos débitos ambíguos ou sem correspondência.
 */
class ExtratoBancarioItemResource extends Resource
{
    protected static ?string $model = ExtratoBancarioItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Extrato bancário';

    protected static ?string $modelLabel = 'linha do extrato';

    protected static ?string $pluralModelLabel = 'extrato bancário';

    protected static ?string $slug = 'extrato-bancario';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view:extrato_bancario') ?? false;
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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['contaFinanceira', 'movimentacao']))
            ->defaultSort('data_lancamento', 'desc')
            ->columns([
                TextColumn::make('data_lancamento')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('contaFinanceira.nome')->label('Conta')->toggleable(),
                TextColumn::make('historico')->label('Histórico')->description(fn (ExtratoBancarioItem $record): ?string => $record->info_complementar)->wrap()->searchable(),
                TextColumn::make('documento')->label('Documento')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('valor')->label('Valor')->money('BRL')->alignEnd()
                    ->color(fn (ExtratoBancarioItem $record): string => $record->ehDebito() ? 'danger' : 'success')
                    ->prefix(fn (ExtratoBancarioItem $record): string => $record->ehDebito() ? '− ' : '+ '),
                TextColumn::make('status_conciliacao')->label('Conciliação')->badge(),
                TextColumn::make('movimentacao.descricao')->label('Movimentação')->placeholder('—')->limit(40)
                    ->url(fn (ExtratoBancarioItem $record): ?string => $record->movimentacao?->lancamento_id
                        ? LancamentoResource::getUrl('edit', ['record' => $record->movimentacao->lancamento_id])
                        : null),
            ])
            ->filters([
                SelectFilter::make('status_conciliacao')->label('Conciliação')->options(StatusConciliacaoExtrato::class),
                SelectFilter::make('natureza')->label('Tipo')->options(['D' => 'Débitos', 'C' => 'Créditos']),
                SelectFilter::make('conta_financeira_id')->label('Conta')->relationship('contaFinanceira', 'nome'),
            ])
            ->recordActions([
                Action::make('conciliar')
                    ->label('Conciliar com parcela')
                    ->icon(Heroicon::OutlinedLink)
                    ->visible(fn (ExtratoBancarioItem $record): bool => $record->ehDebito() && in_array($record->status_conciliacao, [
                        StatusConciliacaoExtrato::Pendente, StatusConciliacaoExtrato::Ambiguo, StatusConciliacaoExtrato::SemCorrespondencia,
                    ], true))
                    ->modalDescription('A parcela é baixada com o valor deste débito (com juros ou multa, se houver), na data do extrato.')
                    ->schema([
                        Select::make('lancamento_id')
                            ->label('Parcela a pagar')
                            ->options(fn (ExtratoBancarioItem $record): array => self::parcelasEmAberto($record))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (ExtratoBancarioItem $record, array $data, ConciliacaoBoletoService $conciliacao, Action $action): void {
                        try {
                            $conciliacao->conciliarManual($record, Lancamento::query()->findOrFail($data['lancamento_id']), auth()->user());
                        } catch (ValidationException $e) {
                            Notification::make()->title('Não foi possível conciliar')->body(collect($e->errors())->flatten()->first())->danger()->send();
                            $action->halt();

                            return;
                        }

                        Notification::make()->title('Débito conciliado e parcela baixada')->success()->send();
                    }),
                Action::make('ignorar')
                    ->label('Ignorar')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (ExtratoBancarioItem $record): bool => $record->status_conciliacao !== StatusConciliacaoExtrato::Conciliado
                        && $record->status_conciliacao !== StatusConciliacaoExtrato::Ignorado)
                    ->action(fn (ExtratoBancarioItem $record, ConciliacaoBoletoService $conciliacao) => $conciliacao->ignorar($record)),
            ]);
    }

    /**
     * Títulos a pagar em aberto com vencimento a até 30 dias do débito, os de
     * valor mais próximo primeiro.
     *
     * @return array<int, string>
     */
    private static function parcelasEmAberto(ExtratoBancarioItem $item): array
    {
        $data = $item->data_lancamento->copy();

        return Lancamento::query()
            ->pagar()
            ->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
            ->whereBetween('vencimento', [$data->copy()->subDays(30)->toDateString(), $data->copy()->addDays(30)->toDateString()])
            ->with('pagamentos')
            ->get()
            ->sortBy(fn (Lancamento $titulo): float => abs((float) $titulo->valorRestante - (float) $item->valor))
            ->take(50)
            ->mapWithKeys(fn (Lancamento $titulo): array => [$titulo->id => $titulo->descricao.' — '
                .Number::currency((float) $titulo->valorRestante, 'BRL', 'pt_BR').' — vence '.$titulo->vencimento?->format('d/m/Y')])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExtratoBancarioItens::route('/'),
        ];
    }
}
