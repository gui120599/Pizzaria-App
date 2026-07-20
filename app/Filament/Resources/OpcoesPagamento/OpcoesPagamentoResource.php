<?php

namespace App\Filament\Resources\OpcoesPagamento;

use App\Filament\Resources\OpcoesPagamento\Pages\ManageOpcoesPagamento;
use App\Models\OpcoesPagamento;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class OpcoesPagamentoResource extends Resource
{
    protected static ?string $model = OpcoesPagamento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Formas de Pagamento';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'opcaopag_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SchemaSection::make('Identificação')
                ->icon('heroicon-o-credit-card')
                ->schema([
                    TextInput::make('opcaopag_nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Ex: PIX, Dinheiro, Cartão de Débito'),

                    Toggle::make('opcaopag_aparece_cardapio')
                        ->label('Aparece no Cardápio Online')
                        ->default(true)
                        ->inline()
                        ->helperText('Quando desativado, esta forma não será exibida para pedidos online'),

                    Textarea::make('opcaopag_descricao')
                        ->label('Descrição para o Cliente')
                        ->placeholder('Ex: Pagamento recebido no ato da entrega. O entregador possui maquininha.')
                        ->rows(3)
                        ->helperText('Exibida no cardápio para orientar o cliente sobre como funciona este pagamento'),
                ]),

            SchemaSection::make('Configurações Fiscais')
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->schema([
                    Select::make('opcaopag_desc_nfe')
                        ->label('Código NF-e')
                        ->options([
                            'cash' => 'Dinheiro',
                            'cheque' => 'Cheque',
                            'creditCard' => 'Cartão de Crédito',
                            'debitCard' => 'Cartão de Débito',
                            'storeCredict' => 'Crédito em Loja',
                            'foodVouchers' => 'Vale Alimentação',
                            'mealVouchers' => 'Vale Refeição',
                            'giftVouchers' => 'Vale Presente',
                            'fuelVouchers' => 'Vale Combustível',
                            'bankBill' => 'Boleto Bancário',
                            'withoutPayment' => 'Sem Pagamento',
                            'InstantPayment' => 'PIX',
                            'others' => 'Outros',
                        ])
                        ->nullable()
                        ->placeholder('Selecione o código NFe'),

                    Select::make('opcaopag_tipo_taxa')
                        ->label('Tipo de Taxa')
                        ->options(OpcoesPagamento::TIPO_TAXA)
                        ->default('N/A')
                        ->required(),

                    TextInput::make('opcaopag_valor_percentual_taxa')
                        ->label('Taxa (%)')
                        ->numeric()
                        ->step(0.01)
                        ->suffix('%')
                        ->default(0)
                        ->helperText('Percentual aplicado sobre o total (acréscimo ou desconto)'),
                ]),

            SchemaSection::make('Operação no PDV')
                ->icon('heroicon-o-credit-card')
                ->collapsible()
                ->schema([
                    Toggle::make('opcaopag_requer_bandeira')
                        ->label('Exige bandeira do cartão')
                        ->default(false)
                        ->inline()
                        ->helperText('Quando ativado, o operador deve informar a bandeira ao registrar este pagamento na venda'),

                    Toggle::make('opcaopag_requer_autorizacao')
                        ->label('Exige nº de autorização')
                        ->default(false)
                        ->inline()
                        ->helperText('Quando ativado, o operador deve informar o número de autorização ao registrar este pagamento na venda'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('opcaopag_nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                ToggleColumn::make('opcaopag_aparece_cardapio')
                    ->label('No Cardápio'),

                TextColumn::make('opcaopag_descricao')
                    ->label('Descrição')
                    ->limit(60)
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('opcaopag_tipo_taxa')
                    ->label('Taxa')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'ACRESCENTAR' => 'warning',
                        'DESCONTAR' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('opcaopag_valor_percentual_taxa')
                    ->label('%')
                    ->suffix('%')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('opcaopag_aparece_cardapio')
                    ->label('No Cardápio')
                    ->trueLabel('Aparece no cardápio')
                    ->falseLabel('Não aparece'),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\CreateAction::make(),
                \Filament\Actions\BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOpcoesPagamento::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
