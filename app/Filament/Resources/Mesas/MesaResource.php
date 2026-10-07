<?php

namespace App\Filament\Resources\Mesas;

use App\Enums\StatusMesa;
use App\Enums\TipoMesaEnum;
use App\Filament\Resources\Mesas\Pages\CreateMesa;
use App\Filament\Resources\Mesas\Pages\EditMesa;
use App\Filament\Resources\Mesas\Pages\ListMesas;
use App\Filament\Resources\Mesas\RelationManagers\SessoesRelationManager;
use App\Models\Mesa;
use App\Services\MesaCliente\QrCodeMesaService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class MesaResource extends Resource
{
    protected static ?string $model = Mesa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static UnitEnum|string|null $navigationGroup = 'Salão';

    protected static ?string $navigationLabel = 'Mesas';

    protected static ?string $modelLabel = 'mesa';

    protected static ?string $pluralModelLabel = 'mesas';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'mesa_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('mesa_nome')
                ->label('Nome')
                ->required()
                ->maxLength(100)
                ->placeholder('Ex.: Mesa 01, Varanda 2'),

            Select::make('mesa_tipo')
                ->label('Tipo')
                ->options(TipoMesaEnum::class)
                ->default(TipoMesaEnum::MESA)
                ->required()
                ->native(false)
                ->helperText('Comanda numerada usa o mesmo atendimento da mesa no Painel do Garçom.'),

            TextInput::make('mesa_numero')
                ->label('Número')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->helperText('Aparece grande no mapa do salão.')
                ->rule(fn (Get $get, ?Mesa $record) => Rule::unique('mesas', 'mesa_numero')
                    ->where('mesa_tipo', $get('mesa_tipo') instanceof TipoMesaEnum ? $get('mesa_tipo')->value : $get('mesa_tipo'))
                    ->whereNull('deleted_at')
                    ->ignore($record?->id)),

            TextInput::make('mesa_capacidade')
                ->label('Lugares')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(99),

            TextInput::make('mesa_area')
                ->label('Área')
                ->maxLength(60)
                ->placeholder('Ex.: Salão, Varanda')
                ->datalist(fn () => Mesa::query()->whereNotNull('mesa_area')->distinct()->orderBy('mesa_area')->pluck('mesa_area')->all())
                ->helperText('Agrupa as mesas no mapa do salão. Em branco = Salão.'),

            Select::make('mesa_status')
                ->label('Status')
                ->options(StatusMesa::class)
                ->default(StatusMesa::Liberada->value)
                ->required()
                ->native(false)
                ->helperText('O status normalmente é controlado automaticamente pela abertura/fechamento de sessão. Altere aqui só pra correção administrativa.'),

            Toggle::make('mesa_pedido_cliente_ativo')
                ->label('Cliente pede pelo celular (QR da mesa)')
                ->default(true)
                ->inline(false)
                ->helperText('Desligado, o QR mostra o cardápio e pede para chamar o garçom.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('mesa_nome')
            ->defaultSort('mesa_numero')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('sessoes'))
            ->columns([
                TextColumn::make('mesa_nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('mesa_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('mesa_numero')
                    ->label('Nº')
                    ->sortable(),

                TextColumn::make('mesa_area')
                    ->label('Área')
                    ->placeholder('Salão')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('mesa_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusMesa::from($state)->getLabel())
                    ->color(fn (string $state): string => StatusMesa::from($state)->getColor()),

                IconColumn::make('mesa_pedido_cliente_ativo')
                    ->label('Pedido pelo QR')
                    ->boolean(),

                TextColumn::make('sessoes_count')
                    ->label('Sessões')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('mesa_tipo')
                    ->label('Tipo')
                    ->options(TipoMesaEnum::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::verQrAction(),
                    self::imprimirQrAction(),
                    self::regerarQrAction(),
                ])->label('QR')->icon(Heroicon::OutlinedQrCode)->button()->color('gray'),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('imprimirQrs')
                        ->label('Imprimir QRs')
                        ->icon(Heroicon::OutlinedPrinter)
                        ->authorizeIndividualRecords('view')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records): StreamedResponse => self::baixarPdf($records)),
                    BulkAction::make('ligarPedidoQr')
                        ->label('Ligar pedido pelo QR')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->authorizeIndividualRecords('update')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => Mesa::whereKey($records->modelKeys())->update(['mesa_pedido_cliente_ativo' => true])),
                    BulkAction::make('desligarPedidoQr')
                        ->label('Desligar pedido pelo QR')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorizeIndividualRecords('update')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => Mesa::whereKey($records->modelKeys())->update(['mesa_pedido_cliente_ativo' => false])),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function verQrAction(): Action
    {
        return Action::make('verQr')
            ->label('Ver QR')
            ->icon(Heroicon::OutlinedQrCode)
            ->authorize('view')
            ->modalHeading(fn (Mesa $record): string => 'QR da '.$record->mesa_nome)
            ->modalWidth('sm')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (Mesa $record) => view('mesa-cliente.qr-modal', [
                'mesa' => $record,
                'qr' => app(QrCodeMesaService::class)->png($record),
                'url' => app(QrCodeMesaService::class)->url($record),
            ]));
    }

    public static function imprimirQrAction(): Action
    {
        return Action::make('imprimirQr')
            ->label('Imprimir QR (PDF)')
            ->icon(Heroicon::OutlinedPrinter)
            ->authorize('view')
            ->action(fn (Mesa $record): StreamedResponse => self::baixarPdf(collect([$record])));
    }

    /** Novo código: o QR impresso antes para de funcionar (ex.: foto que vazou). */
    public static function regerarQrAction(): Action
    {
        return Action::make('regerarQr')
            ->label('Gerar novo código')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('danger')
            ->authorize('update')
            ->requiresConfirmation()
            ->modalHeading('Gerar novo código do QR?')
            ->modalDescription('O QR impresso hoje nesta mesa deixa de funcionar. Imprima e troque o novo na mesa.')
            ->modalSubmitActionLabel('Gerar novo código')
            ->action(function (Mesa $record): void {
                $record->regenerarCodigoQr();
                Notification::make()->title('Novo código gerado. Imprima o QR novo.')->success()->send();
            });
    }

    /** @param  SupportCollection<int, Mesa>  $mesas */
    private static function baixarPdf(SupportCollection $mesas): StreamedResponse
    {
        $pdf = app(QrCodeMesaService::class)->pdf($mesas->sortBy([['mesa_numero', 'asc'], ['mesa_nome', 'asc']]));

        return response()->streamDownload(fn () => print ($pdf), 'qr-mesas.pdf', ['Content-Type' => 'application/pdf']);
    }

    public static function getRelations(): array
    {
        return [
            SessoesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMesas::route('/'),
            'create' => CreateMesa::route('/create'),
            'edit' => EditMesa::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
