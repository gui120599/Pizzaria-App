<?php

namespace App\Filament\Resources\Prestadors;

use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Filament\Resources\Prestadors\Pages\ManagePrestadors;
use App\Models\Prestador;
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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
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

class PrestadorResource extends Resource
{
    protected static ?string $model = Prestador::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'nome_fantasia';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Identificação')
                    ->description('Informações principais do prestador')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->schema([
                        Select::make('tipo')
                            ->label('Tipo de Pessoa')
                            ->options(collect(PrestadorTipoEnum::cases())
                                ->mapWithKeys(fn($case) => [
                                    $case->value => $case->label(),
                                ])
                                ->toArray())
                            ->required()
                            ->live()
                            ->columnSpan(1),

                        Select::make('categoria')
                            ->label('Categoria')
                            ->options(collect(PrestadorCategoriaEnum::cases())
                                ->mapWithKeys(fn($case) => [
                                    $case->value => $case->label(),
                                ])
                                ->toArray())
                            ->columnSpan(1),

                        TextInput::make('razao_social')
                            ->label('Razão Social')
                            ->visible(fn(Get $get) => $get('tipo') === PrestadorTipoEnum::PJ->value)
                            ->columnSpan(1),

                        TextInput::make('nome_fantasia')
                            ->label('Nome Fantasia')
                            ->visible(fn(Get $get) => $get('tipo') === PrestadorTipoEnum::PJ->value)
                            ->columnSpan(1),

                        TextInput::make('nome')
                            ->label('Nome Completo')
                            ->visible(fn(Get $get) => $get('tipo') === PrestadorTipoEnum::PF->value)
                            ->columnSpan(2),
                    ]),

                Section::make('Documentos')
                    ->description('CPF, CNPJ e inscrições')
                    ->icon('heroicon-o-document-text')
                    ->columns(2)
                    ->schema([
                        TextInput::make('cpf_cnpj')
                            ->label(fn(Get $get) => $get('tipo') === PrestadorTipoEnum::PJ->value ? 'CNPJ' : 'CPF')
                            ->mask(fn(Get $get) => $get('tipo') === PrestadorTipoEnum::PJ->value
                                ? '99.999.999/9999-99'
                                : '999.999.999-99')
                            ->columnSpan(1),

                        TextInput::make('inscricao_estadual')
                            ->label('Inscrição Estadual')
                            ->visible(fn(Get $get) => $get('tipo') === PrestadorTipoEnum::PJ->value)
                            ->columnSpan(1),
                    ]),

                Section::make('Contato')
                    ->description('E-mail e telefones para contato')
                    ->icon('heroicon-o-phone')
                    ->columns(3)
                    ->schema([
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->prefixIcon('heroicon-o-envelope')
                            ->columnSpan(3),

                        TextInput::make('telefone')
                            ->label('Telefone Fixo')
                            ->tel()
                            ->mask('(99) 9999-9999')
                            ->prefixIcon('heroicon-o-phone')
                            ->columnSpan(1),

                        TextInput::make('celular')
                            ->label('Celular / WhatsApp')
                            ->tel()
                            ->mask('(99) 99999-9999')
                            ->prefixIcon('heroicon-o-device-phone-mobile')
                            ->columnSpan(1),
                    ]),

                Section::make('Endereço')
                    ->description('Localização do prestador')
                    ->icon('heroicon-o-map-pin')
                    ->columns(6)
                    ->schema([
                    TextInput::make('cep')
                        ->mask('99999-999')
                        ->live() // Garante que as mudanças no campo disparem a ação.
                        ->afterStateUpdated(function ($state, callable $set) {
                            // Limpa o CEP para conter apenas números.
                            $cepLimpo = preg_replace('/[^0-9]/', '', $state);
                            if (strlen($cepLimpo) === 8) {

                                $dadosEndereco = IBGEServices::buscaCep($cepLimpo);

                                if ($dadosEndereco) {
                                    $set('endereco', $dadosEndereco['logradouro'] ?? '');
                                    $set('bairro', $dadosEndereco['bairro'] ?? '');
                                    $set('estado', $dadosEndereco['uf'] ?? '');
                                    $set('cidade', $dadosEndereco['localidade'] ?? '');
                                } else {
                                    // Opcional: Limpar campos se a busca falhar
                                    $set('endereco', '');
                                    $set('bairro', '');
                                    $set('estado', '');
                                    $set('cidade', '');
                                    // Opcional: Adicionar uma notificação de erro
                                }
                            }
                        }),
                    TextInput::make('endereco')
                        ->columnSpan(2)
                        ->label('Logradouro'),
                    TextInput::make('complemento_endereco')
                        ->columnSpan(3)
                        ->label('Complemento'),
                    TextInput::make('bairro')
                        ->columnSpan(2),
                    Select::make('estado')
                        ->live()
                        ->preload(false)
                        ->options(IBGEServices::ufs())
                        ->searchable()
                        ->columnSpan(2),
                    Select::make('cidade')
                        ->label('Cidade')
                        ->preload()
                        ->searchable()
                        ->options(function (Get $get) {
                            // Pega a sigla (valor) selecionada no campo 'estado'
                            $uf = $get('estado');
                            // Se o estado não estiver selecionado, não retorna nenhuma opção
                            if (empty($uf)) {
                                return [];
                            }
                            // Chama o novo método no seu serviço para buscar as cidades da UF
                            return IBGEServices::cidadesPorUf($uf);
                        })
                        ->columnSpan(2),
                    ]),

                Section::make('Observações')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->collapsed()
                    ->schema([
                        Textarea::make('observacoes')
                            ->label('Observações')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('nome_fantasia')
            ->columns([
                TextColumn::make('tipo')
                    ->badge()
                    ->searchable(),
                TextColumn::make('categoria')
                    ->badge()
                    ->searchable(),
                TextColumn::make('razao_social')
                    ->searchable(),
                TextColumn::make('nome_fantasia')
                    ->searchable(),
                TextColumn::make('nome')
                    ->searchable(),
                TextColumn::make('cpf_cnpj')
                    ->searchable(),
                TextColumn::make('inscricao_estadual')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('telefone')
                    ->searchable(),
                TextColumn::make('celular')
                    ->searchable(),
                TextColumn::make('cep')
                    ->searchable(),
                TextColumn::make('endereco')
                    ->searchable(),
                TextColumn::make('complemento')
                    ->searchable(),
                TextColumn::make('numero')
                    ->searchable(),
                TextColumn::make('bairro')
                    ->searchable(),
                TextColumn::make('cidade')
                    ->searchable(),
                TextColumn::make('uf')
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
            'index' => ManagePrestadors::route('/'),
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
