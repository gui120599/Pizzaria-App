<?php

namespace App\Filament\Resources\Clientes;

use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Models\Cliente;
use App\Services\IBGEServices;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Leandrocfe\FilamentPtbrFormFields\Document;
use Leandrocfe\FilamentPtbrFormFields\PhoneNumber;

class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'cliente_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informações do Cliente')
                    ->icon(Heroicon::OutlinedUser)
                    ->columns(3)
                    ->schema([
                        TextInput::make('cliente_nome')
                            ->label('Nome do Cliente')
                            ->required(),
                        DatePicker::make('cliente_data_nascimento'),
                        Select::make('cliente_tipo')
                            ->options([
                                'Fíisica' => 'Pessoa Física',
                                'Jurídica' => 'Pessoa Jurídica',
                            ])
                            ->label('Tipo de Cliente')
                            ->required(),
                        Document::make('cliente_cpf')
                            ->cpf()
                            ->label('CPF'),
                        Document::make('cliente_cnpj')
                            ->cnpj()
                            ->label('CNPJ'),
                    ]),
                Section::make('Contato')
                    ->icon(Heroicon::OutlinedPhone)
                    ->columns(2)
                    ->schema([
                        PhoneNumber::make('cliente_celular')
                            ->mask('(99) 99999-9999')
                            ->label('Celular'),
                        TextInput::make('cliente_email')
                            ->label('E-mail')
                            ->email(),
                    ]),
                Section::make('Endereço')
                    ->icon(Heroicon::OutlinedMapPin)
                    ->columns(6)
                    ->schema([
                        TextInput::make('cliente_cep')->mask('99999-999')
                            ->label('CEP')
                            ->live() // Garante que as mudanças no campo disparem a ação.
                            ->afterStateUpdated(function ($state, callable $set) {
                                // Limpa o CEP para conter apenas números.
                                $cepLimpo = preg_replace('/[^0-9]/', '', $state);
                                if (strlen($cepLimpo) === 8) {

                                    $dadosEndereco = IBGEServices::buscaCep($cepLimpo);

                                    if ($dadosEndereco) {
                                        $set('cliente_endereco', $dadosEndereco['logradouro'] ?? '');
                                        $set('cliente_bairro', $dadosEndereco['bairro'] ?? '');
                                        $set('cliente_estado', $dadosEndereco['uf'] ?? '');
                                        $set('cliente_cidade', $dadosEndereco['localidade'] ?? '');
                                    } else {
                                        // Opcional: Limpar campos se a busca falhar
                                        $set('endereco', '');
                                        $set('cliente_bairro', '');
                                        $set('cliente_estado', '');
                                        $set('cliente_cidade', '');
                                        // Opcional: Adicionar uma notificação de erro
                                    }
                                }
                            }),
                        TextInput::make('cliente_endereco')
                            ->label('Logradouro')
                            ->columnSpan(2),
                        TextInput::make('cliente_bairro')
                            ->label('Bairro')
                            ->columnSpan(2),
                        Select::make('cliente_estado')
                            ->label('Estado')
                            ->live()
                            ->preload(false)
                            ->options(IBGEServices::ufs())
                            ->searchable()
                            ->columnSpan(2),
                        Select::make('cliente_cidade')
                            ->label('Cidade')
                            ->preload()
                            ->searchable()
                            ->options(function (Get $get) {
                                // Pega a sigla (valor) selecionada no campo 'estado'
                                $uf = $get('cliente_estado');
                                // Se o estado não estiver selecionado, não retorna nenhuma opção
                                if (empty($uf)) {
                                    return [];
                                }
                                // Chama o novo método no seu serviço para buscar as cidades da UF
                                return IBGEServices::cidadesPorUf($uf);
                            })
                            ->columnSpan(2),
                    ]),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('cliente_nome')
            ->columns([
                TextColumn::make('cliente_nome')
                    ->searchable(),
                TextColumn::make('cliente_data_nascimento')
                    ->date()
                    ->sortable(),
                TextColumn::make('cliente_tipo')
                    ->searchable(),
                TextColumn::make('cliente_cpf')
                    ->searchable(),
                TextColumn::make('cliente_rg')
                    ->searchable(),
                TextColumn::make('cliente_cnpj')
                    ->searchable(),
                TextColumn::make('cliente_celular')
                    ->searchable(),
                TextColumn::make('cliente_email')
                    ->searchable(),
                TextColumn::make('cliente_endereco')
                    ->searchable(),
                TextColumn::make('cliente_numero_endereco')
                    ->searchable(),
                TextColumn::make('cliente_bairro')
                    ->searchable(),
                TextColumn::make('cliente_cidade')
                    ->searchable(),
                TextColumn::make('cliente_estado')
                    ->searchable(),
                TextColumn::make('cliente_uf_estado')
                    ->searchable(),
                TextColumn::make('cliente_cep')
                    ->searchable(),
                TextColumn::make('cliente_foto')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
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
            'index' => ManageClientes::route('/'),
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
