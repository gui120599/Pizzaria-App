<?php

namespace App\Filament\Resources\OpcoesPagamento;

use App\Enums\FormaTrocoEnum;
use App\Filament\Components\ContaFinanceiraSelect;
use App\Filament\Resources\OpcoesPagamento\Pages\ManageOpcoesPagamento;
use App\Models\OpcoesPagamento;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
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
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
                ->columnSpanFull()
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
                ->columnSpanFull()
                ->icon('heroicon-o-document-text')
                ->collapsible()
                ->collapsed()
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
                        ->live()
                        ->placeholder('Selecione o código NFe'),

                    Toggle::make('opcaopag_envio_automatico_nfe')
                        ->label('Envia NFC-e automaticamente')
                        ->default(false)
                        ->inline()
                        ->helperText('Venda finalizada no PDV com pelo menos um pagamento nesta forma emite a NFC-e automaticamente. Deixe desligado para formas cuja nota é emitida fora do sistema, como cartão na maquininha Stone.'),

                    Select::make('plano_receita_id')
                        ->label('Plano de receita')
                        ->relationship('planoReceita', 'nome')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Conta do plano de receita que classifica as vendas recebidas nesta forma de pagamento no Contas a Receber.'),

                    ContaFinanceiraSelect::make('conta_financeira_id')
                        ->label('Conta onde cai o dinheiro')
                        ->semCaixaPdv()
                        ->placeholder('Selecione a conta')
                        ->helperText('Para formas que não passam por maquininha, como PIX CNPJ e vales. Cartão e Pix de maquininha usam a conta da maquininha; dinheiro vai sempre para o Caixa PDV.')
                        ->visible(fn (Get $get): bool => $get('opcaopag_desc_nfe') !== 'cash'),

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
                ->columnSpanFull()
                ->icon('heroicon-o-credit-card')
                ->collapsible()
                ->collapsed()
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

                    Toggle::make('opcaopag_stone_integrada')
                        ->label('Permite cobrar na maquininha Stone (Connect)')
                        ->default(false)
                        ->inline()
                        ->helperText('No PDV, o operador escolhe entre "Lançar manual" (qualquer maquininha) e "Cobrar na Stone" (envia a cobrança à maquininha e lança o pagamento pelo webhook). Exige Código NF-e = Cartão de Crédito, Cartão de Débito ou PIX; só uma forma por Código NF-e.')
                        ->rule(fn (Get $get, ?Model $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                            if (! $value) {
                                return;
                            }

                            if (! in_array($get('opcaopag_desc_nfe'), ['creditCard', 'debitCard', 'InstantPayment'], true)) {
                                $fail('Formas integradas à Stone precisam de Código NF-e "Cartão de Crédito", "Cartão de Débito" ou "PIX".');

                                return;
                            }

                            $jaPermite = OpcoesPagamento::query()
                                ->where('opcaopag_stone_integrada', true)
                                ->where('opcaopag_desc_nfe', $get('opcaopag_desc_nfe'))
                                ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                                ->value('opcaopag_nome');

                            if ($jaPermite !== null) {
                                $fail("A forma \"{$jaPermite}\" já permite cobrar na Stone com este Código NF-e. Desmarque lá antes, para o pagamento da maquininha cair numa forma só.");
                            }
                        }),

                    Toggle::make('opcaopag_pix_cnpj')
                        ->label('Pix direto na conta (CNPJ)')
                        ->default(false)
                        ->inline()
                        ->live()
                        ->helperText('Pix na chave CNPJ da empresa, sem maquininha. No fechamento de caixa é conferido pelo extrato do banco, numa seção própria.')
                        ->visible(fn (Get $get): bool => $get('opcaopag_desc_nfe') === 'InstantPayment'),

                    Toggle::make('opcaopag_carteira_influenciador')
                        ->label('Carteira de influenciador (consumo em pizza)')
                        ->default(false)
                        ->inline()
                        ->helperText('Só vale no PDV, com o código de resgate do próprio influenciador, e debita o saldo da carteira dele. Cadastre uma conta financeira de compensação ("Carteira de influenciadores") e escolha-a em "Conta financeira"; o fechamento lança a receita da venda e a despesa de marketing de mesmo valor, sem efeito no caixa. Não aparece no cardápio nem no pedido.')
                        ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                            if ($value && blank($get('conta_financeira_id'))) {
                                $fail('Escolha a conta financeira de compensação para a carteira de influenciador.');
                            }
                        }),

                    ToggleButtons::make('opcaopag_troco_padrao')
                        ->label('Troco padrão')
                        ->options(FormaTrocoEnum::class)
                        ->default(FormaTrocoEnum::Dinheiro)
                        ->required()
                        ->inline()
                        ->helperText('De onde sai o troco quando o cliente entrega mais que o valor. O operador pode trocar no PDV; Pix sai da conta do PIX CNPJ.'),

                    Toggle::make('opcaopag_confirmavel_no_pedido')
                        ->label('Pode ser confirmada no pedido')
                        ->default(false)
                        ->inline()
                        ->helperText('O atendente pode marcar no pedido que o pagamento já foi recebido (ex.: PIX conferido na hora). A comanda sai como PAGO.'),

                    TextInput::make('opcaopag_tarifa_percentual')
                        ->label('Tarifa bancária do PIX CNPJ (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->suffix('%')
                        ->helperText('Abatida no Relatório de Fechamento de Caixa. Em branco = sem tarifa.')
                        ->visible(fn (Get $get): bool => $get('opcaopag_desc_nfe') === 'InstantPayment' && (bool) $get('opcaopag_pix_cnpj')),
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

                ToggleColumn::make('opcaopag_stone_integrada')
                ->label('Connect Stone'),

                TextColumn::make('planoReceita.nome')
                    ->label('Plano de receita')
                    ->placeholder('Sem plano')
                    ->color(fn (OpcoesPagamento $record): ?string => $record->plano_receita_id === null && ! $record->trashed() ? 'danger' : null)
                    ->icon(fn (OpcoesPagamento $record): ?string => $record->plano_receita_id === null && ! $record->trashed() ? 'heroicon-o-exclamation-triangle' : null)
                    ->tooltip(fn (OpcoesPagamento $record): ?string => $record->plano_receita_id === null && ! $record->trashed() ? 'Informe o plano de receita: sem ele a receita do caixa não é classificada.' : null),

                TextColumn::make('contaFinanceira.nome')
                    ->label('Conta')
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('opcaopag_envio_automatico_nfe')
                    ->label('NFC-e automática')
                    ->boolean(),

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
                CreateAction::make(),
                BulkActionGroup::make([
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
