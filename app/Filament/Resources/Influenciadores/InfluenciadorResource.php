<?php

namespace App\Filament\Resources\Influenciadores;

use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Exceptions\InfluenciadorInvalidoException;
use App\Filament\Resources\Influenciadores\Pages\EditInfluenciador;
use App\Filament\Resources\Influenciadores\Pages\ManageInfluenciadores;
use App\Filament\Resources\Influenciadores\RelationManagers\CarteiraRelationManager;
use App\Filament\Resources\Influenciadores\RelationManagers\ComissoesRelationManager;
use App\Filament\Resources\Influenciadores\RelationManagers\CuponsRelationManager;
use App\Models\Empresa;
use App\Models\Influenciador;
use App\Services\InfluenciadorService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

class InfluenciadorResource extends Resource
{
    protected static ?string $model = Influenciador::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Influenciadores';

    // Sem isso, o Filament pluralizaria "influenciador" pelas regras do
    // inglês ("influenciadors") — mesma pegadinha corrigida no $table dos
    // models Influenciador/Cupom.
    protected static ?string $slug = 'influenciadores';

    protected static UnitEnum|string|null $navigationGroup = 'Pessoas';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'influenciador_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Influenciador')
                    ->description('Cadastro feito pelo próprio influenciador em /influenciadores/register — aprove mudando o status para Ativo.')
                    ->icon(Heroicon::OutlinedMegaphone)
                    ->columns(2)
                    ->schema([
                        TextInput::make('influenciador_nome')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true),

                        TextInput::make('influenciador_whatsapp')
                            ->label('WhatsApp')
                            ->tel(),

                        TextInput::make('influenciador_instagram')
                            ->label('Instagram'),

                        TextInput::make('influenciador_chave_pix')
                            ->label('Chave Pix')
                            ->helperText('Usada futuramente para cálculo de comissão.'),

                        Select::make('influenciador_status')
                            ->label('Status')
                            ->options([
                                StatusInfluenciadorEnum::PENDENTE->value => StatusInfluenciadorEnum::PENDENTE->label(),
                                StatusInfluenciadorEnum::ATIVO->value => StatusInfluenciadorEnum::ATIVO->label(),
                                StatusInfluenciadorEnum::INATIVO->value => StatusInfluenciadorEnum::INATIVO->label(),
                            ])
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                $empresa = Empresa::first();

                                if ($state === StatusInfluenciadorEnum::ATIVO->value && (float) $get('influenciador_valor_comissao') <= 0 && $empresa) {
                                    $set('influenciador_tipo_comissao', $empresa->empresa_influ_comissao_tipo);
                                    $set('influenciador_valor_comissao', $empresa->empresa_influ_comissao_valor);
                                }
                            }),

                        TextInput::make('password')
                            ->label('Senha')
                            ->autocomplete('new-password')
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->helperText('Deixe em branco para manter a senha atual.')
                            ->revealable()
                            ->password()
                            ->columnSpanFull(),
                    ]),

                Section::make('Comissão')
                    ->description('Regra de comissão sobre pedidos gerados pelos cupons deste influenciador. O influenciador não edita isso, só visualiza.')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(2)
                    ->schema([
                        Select::make('influenciador_tipo_comissao')
                            ->label('Tipo')
                            ->options([
                                TipoDescontoEnum::PERCENTUAL->value => TipoDescontoEnum::PERCENTUAL->label(),
                                TipoDescontoEnum::VALOR_FIXO->value => TipoDescontoEnum::VALOR_FIXO->label(),
                            ])
                            ->required()
                            ->native(false),

                        TextInput::make('influenciador_valor_comissao')
                            ->label('Valor da Comissão')
                            ->numeric()
                            ->minValue(fn (Get $get): float => $get('influenciador_status') === StatusInfluenciadorEnum::ATIVO->value ? 0.01 : 0)
                            ->helperText('Influenciador ativo precisa de comissão maior que zero.')
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('influenciador_nome')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('cupons'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum influenciador cadastrado')
            ->columns([
                TextColumn::make('influenciador_nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),

                TextColumn::make('influenciador_whatsapp')
                    ->label('WhatsApp')
                    ->placeholder('—'),

                TextColumn::make('influenciador_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => match ($state) {
                        StatusInfluenciadorEnum::ATIVO => 'success',
                        StatusInfluenciadorEnum::PENDENTE => 'warning',
                        StatusInfluenciadorEnum::INATIVO => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('cupons_count')
                    ->label('Cupons')
                    ->badge(),

                TextColumn::make('created_at')
                    ->label('Cadastro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('influenciador_status')
                    ->label('Status')
                    ->options([
                        StatusInfluenciadorEnum::PENDENTE->value => StatusInfluenciadorEnum::PENDENTE->label(),
                        StatusInfluenciadorEnum::ATIVO->value => StatusInfluenciadorEnum::ATIVO->label(),
                        StatusInfluenciadorEnum::INATIVO->value => StatusInfluenciadorEnum::INATIVO->label(),
                    ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('aprovar')
                    ->label('Aprovar')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Influenciador $record): bool => $record->influenciador_status !== StatusInfluenciadorEnum::ATIVO)
                    ->requiresConfirmation()
                    ->action(function (Influenciador $record, InfluenciadorService $service): void {
                        try {
                            $service->aprovar($record);
                        } catch (InfluenciadorInvalidoException $e) {
                            Notification::make()->title('Não foi possível aprovar')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Influenciador aprovado')
                            ->success()
                            ->send();
                    }),

                Action::make('inativar')
                    ->label('Inativar')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Influenciador $record): bool => $record->influenciador_status !== StatusInfluenciadorEnum::INATIVO)
                    ->requiresConfirmation()
                    ->modalDescription('O influenciador perde acesso ao painel imediatamente. Os cupons já criados continuam existindo.')
                    ->action(function (Influenciador $record): void {
                        $record->forceFill(['influenciador_status' => StatusInfluenciadorEnum::INATIVO])->save();

                        Notification::make()
                            ->title('Influenciador inativado')
                            ->success()
                            ->send();
                    }),

                EditAction::make()
                    ->url(fn (Influenciador $record): string => static::getUrl('edit', ['record' => $record]))
                    ->modal(false),
                DeleteAction::make()
                    ->before(function (DeleteAction $action, Influenciador $record): void {
                        self::barrarExclusaoSeBloqueada($action, $record);
                    }),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records): void {
                            $records->each(fn (Influenciador $record) => self::barrarExclusaoSeBloqueada($action, $record));
                        }),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function barrarExclusaoSeBloqueada(DeleteAction|DeleteBulkAction $action, Influenciador $record): void
    {
        $motivo = $record->motivoBloqueioExclusao();

        if ($motivo === null) {
            return;
        }

        Notification::make()
            ->title("Não é possível excluir {$record->influenciador_nome}")
            ->body($motivo)
            ->danger()
            ->send();

        $action->cancel();
    }

    public static function getRelations(): array
    {
        return [
            CuponsRelationManager::class,
            ComissoesRelationManager::class,
            CarteiraRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageInfluenciadores::route('/'),
            'edit' => EditInfluenciador::route('/{record}/edit'),
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
