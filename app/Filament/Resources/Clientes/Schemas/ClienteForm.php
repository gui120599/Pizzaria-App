<?php

namespace App\Filament\Resources\Clientes\Schemas;

use App\Models\Cliente;
use App\Services\IBGEServices;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Leandrocfe\FilamentPtbrFormFields\Money;

class ClienteForm
{
    private const UFS = [
        'AC' => 'Acre',
        'AL' => 'Alagoas',
        'AP' => 'Amapá',
        'AM' => 'Amazonas',
        'BA' => 'Bahia',
        'CE' => 'Ceará',
        'DF' => 'Distrito Federal',
        'ES' => 'Espírito Santo',
        'GO' => 'Goiás',
        'MA' => 'Maranhão',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais',
        'PA' => 'Pará',
        'PB' => 'Paraíba',
        'PR' => 'Paraná',
        'PE' => 'Pernambuco',
        'PI' => 'Piauí',
        'RJ' => 'Rio de Janeiro',
        'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia',
        'RR' => 'Roraima',
        'SC' => 'Santa Catarina',
        'SP' => 'São Paulo',
        'SE' => 'Sergipe',
        'TO' => 'Tocantins',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Perfil do Cliente')
                    ->description('Dados principais para identificação e atendimento.')
                    ->icon('heroicon-o-user-circle')
                    ->columnSpan([
                        'default' => 12,
                        'lg' => 8,
                    ])
                    ->schema([
                        Grid::make(6)
                            ->schema([

                                ToggleButtons::make('cliente_tipo')
                                    ->label('Tipo')
                                    ->options([
                                        'PF' => 'Pessoa Física',
                                        'PJ' => 'Pessoa Jurídica',
                                    ])
                                    ->icons([
                                        'PF' => 'heroicon-o-identification',
                                        'PJ' => 'heroicon-o-building-office-2',
                                    ])
                                    ->colors([
                                        'PF' => 'primary',
                                        'PJ' => 'warning',
                                    ])
                                    ->default('PF')
                                    ->required()
                                    ->inline()
                                    ->grouped()
                                    ->live()
                                    ->columnSpan(2)
                                    ->afterStateHydrated(function ($state, callable $set): void {
                                        $set('cliente_tipo', self::normalizarTipo((string) $state));
                                    })
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        $tipo = self::normalizarTipo((string) $state);

                                        if ($tipo === 'PF') {
                                            $set('cliente_cnpj', null);
                                        }

                                        if ($tipo === 'PJ') {
                                            $set('cliente_cpf', null);
                                            $set('cliente_rg', null);
                                        }
                                    }),

                                TextInput::make('cliente_nome')
                                    ->label('Nome do Cliente')
                                    ->placeholder('Ex: João da Silva')
                                    ->prefixIcon('heroicon-o-user')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(5),

                                DatePicker::make('cliente_data_nascimento')
                                    ->label('Data de Nascimento')
                                    ->displayFormat('d/m/Y')
                                    ->native(false)
                                    ->maxDate(now())
                                    ->prefixIcon('heroicon-o-calendar-days')
                                    ->visible(fn ($get): bool => self::normalizarTipo((string) $get('cliente_tipo')) === 'PF')
                                    ->dehydrated(fn ($get): bool => self::normalizarTipo((string) $get('cliente_tipo')) === 'PF')
                                    ->columnSpan(2),

                                TextInput::make('cliente_celular')
                                    ->label('Celular / WhatsApp')
                                    ->tel()
                                    ->mask('(99) 99999-9999')
                                    ->placeholder('(00) 00000-0000')
                                    ->prefixIcon('heroicon-o-device-phone-mobile')
                                    ->columnSpan(2),

                                TextInput::make('cliente_email')
                                    ->label('E-mail')
                                    ->email()
                                    ->placeholder('cliente@exemplo.com')
                                    ->prefixIcon('heroicon-o-envelope')
                                    ->maxLength(255)
                                    ->columnSpan(2),

                                Placeholder::make('resumo_cliente')
                                    ->label('Resumo de Compras')
                                    ->content(function (?Cliente $record): string {
                                        if (! $record) {
                                            return 'Após salvar, vamos exibir os indicadores do cliente.';
                                        }

                                        $totalPedidos = $record->pedidos()->count();
                                        $ultimoPedido = $record->pedidos()->latest('id')->first();
                                        $ultimoPedidoTexto = $ultimoPedido?->pedido_datahora_abertura?->format('d/m/Y H:i')
                                            ?? 'Sem pedidos';

                                        return "{$totalPedidos} pedido(s) registrado(s) • Último: {$ultimoPedidoTexto}";
                                    })
                                    ->columnSpan(6),
                            ]),
                    ]),

                Section::make('Foto')
                    ->description('Imagem usada para identificar o cliente com rapidez.')
                    ->icon('heroicon-o-camera')
                    ->columnSpan([
                        'default' => 12,
                        'lg' => 4,
                    ])
                    ->schema([
                        FileUpload::make('cliente_foto')
                            ->label('Foto do Cliente')
                            ->disk('public')
                            ->directory('fotos_clientes')
                            ->image()
                            ->imageEditor()
                            ->imageResizeMode('cover')
                            ->imageCropAspectRatio('1:1')
                            ->imageResizeTargetWidth(600)
                            ->imageResizeTargetHeight(600)
                            ->helperText('JPG/PNG até 5MB')
                            ->maxSize(5120)
                            ->getUploadedFileNameForStorageUsing(
                                fn ($file): string => Str::uuid().'.'.$file->getClientOriginalExtension()
                            )
                            ->columnSpanFull(),
                    ]),

                Section::make('Documentação')
                    ->description('Campos dinâmicos de acordo com o tipo de pessoa.')
                    ->icon('heroicon-o-document-text')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(6)
                            ->schema([
                                TextInput::make('cliente_cpf')
                                    ->label('CPF')
                                    ->mask('999.999.999-99')
                                    ->placeholder('000.000.000-00')
                                    ->visible(fn ($get): bool => self::normalizarTipo((string) $get('cliente_tipo')) === 'PF')
                                    ->dehydrated(fn ($get): bool => self::normalizarTipo((string) $get('cliente_tipo')) === 'PF')
                                    ->columnSpan(2),

                                TextInput::make('cliente_rg')
                                    ->label('RG')
                                    ->placeholder('Ex: 12.345.678-9')
                                    ->visible(fn ($get): bool => self::normalizarTipo((string) $get('cliente_tipo')) === 'PF')
                                    ->dehydrated(fn ($get): bool => self::normalizarTipo((string) $get('cliente_tipo')) === 'PF')
                                    ->columnSpan(2),

                                TextInput::make('cliente_cnpj')
                                    ->label('CNPJ')
                                    ->mask('99.999.999/9999-99')
                                    ->placeholder('00.000.000/0000-00')
                                    ->visible(fn ($get): bool => self::normalizarTipo((string) $get('cliente_tipo')) === 'PJ')
                                    ->dehydrated(fn ($get): bool => self::normalizarTipo((string) $get('cliente_tipo')) === 'PJ')
                                    ->columnSpan(2),
                            ]),
                    ]),

                Section::make('Crédito')
                    ->description('Limite para vendas fiado/a prazo no PDV. Sem limite cadastrado, o PDV bloqueia venda fiado pra este cliente.')
                    ->icon('heroicon-o-banknotes')
                    ->columnSpanFull()
                    ->schema([
                        Money::make('cliente_limite_credito')
                            ->label('Limite de crédito (fiado)')
                            ->minValue(0)
                            ->columnSpan(3),
                    ]),

                Section::make('Endereço')
                    ->description('Use o CEP para preencher dados automaticamente.')
                    ->icon('heroicon-o-map-pin')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(6)
                            ->schema([
                                TextInput::make('cliente_cep')
                                    ->label('CEP')
                                    ->mask('99999-999')
                                    ->placeholder('00000-000')
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        $cep = preg_replace('/[^0-9]/', '', (string) $state);

                                        if (strlen($cep) !== 8) {
                                            return;
                                        }

                                        $dados = IBGEServices::buscaCep($cep);

                                        if (! empty($dados['erro'])) {
                                            return;
                                        }

                                        $uf = strtoupper((string) ($dados['uf'] ?? ''));
                                        $set('cliente_endereco', (string) ($dados['logradouro'] ?? ''));
                                        $set('cliente_bairro', (string) ($dados['bairro'] ?? ''));
                                        $set('cliente_cidade', (string) ($dados['localidade'] ?? ''));
                                        $set('cliente_uf_estado', $uf);
                                        $set('cliente_estado', self::UFS[$uf] ?? (string) ($dados['estado'] ?? ''));
                                    })
                                    ->columnSpan(1),

                                TextInput::make('cliente_endereco')
                                    ->label('Logradouro')
                                    ->placeholder('Rua, avenida, travessa...')
                                    ->columnSpan(3),

                                TextInput::make('cliente_numero_endereco')
                                    ->label('Número')
                                    ->placeholder('Ex: 123')
                                    ->columnSpan(1),

                                TextInput::make('cliente_bairro')
                                    ->label('Bairro')
                                    ->columnSpan(1),

                                TextInput::make('cliente_cidade')
                                    ->label('Cidade')
                                    ->columnSpan(2),

                                Select::make('cliente_uf_estado')
                                    ->label('UF')
                                    ->options(self::UFS)
                                    ->searchable()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set): void {
                                        $uf = strtoupper((string) $state);
                                        $set('cliente_uf_estado', $uf);
                                        $set('cliente_estado', self::UFS[$uf] ?? null);
                                    })
                                    ->columnSpan(1),

                                TextInput::make('cliente_estado')
                                    ->label('Estado')
                                    ->columnSpan(2),
                            ]),
                    ]),
            ])
            ->columns(12);
    }

    private static function normalizarTipo(string $tipo): string
    {
        $tipoNormalizado = Str::upper(trim($tipo));

        if (in_array($tipoNormalizado, ['PF', 'FISICA', 'PESSOA FISICA', 'PESSOA FÍSICA'], true)) {
            return 'PF';
        }

        if (in_array($tipoNormalizado, ['PJ', 'JURIDICA', 'JURÍDICA', 'PESSOA JURIDICA', 'PESSOA JURÍDICA'], true)) {
            return 'PJ';
        }

        return 'PF';
    }
}
