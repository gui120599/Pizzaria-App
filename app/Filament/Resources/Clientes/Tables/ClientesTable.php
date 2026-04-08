<?php

namespace App\Filament\Resources\Clientes\Tables;

use App\Models\Cliente;
use Filament\Actions\Action as ActionsAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientesTable
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

    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('cliente_nome')
            ->modifyQueryUsing(fn(Builder $query) => $query
                ->withCount('pedidos')
                ->withMax('pedidos as ultimo_pedido_em', 'pedido_datahora_abertura')
            )
            ->defaultSort('cliente_nome')
            ->searchPlaceholder('Buscar por nome, documento, celular ou e-mail...')
            ->emptyStateHeading('Nenhum cliente encontrado')
            ->emptyStateDescription('Cadastre clientes para acelerar o atendimento e reaproveitar dados.')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('60px'),

                TextColumn::make('cliente_nome')
                    ->label('Cliente')
                    ->description(fn(Cliente $record): string => self::tipoLabel($record->cliente_tipo))
                    ->searchable(['cliente_nome', 'cliente_celular', 'cliente_email', 'cliente_cpf', 'cliente_cnpj'])
                    ->sortable(),

                TextColumn::make('cliente_cpf')
                    ->label('Documento')
                    ->state(fn(Cliente $record): string => self::documento($record))
                    ->searchable(['cliente_cpf', 'cliente_cnpj']),

                TextColumn::make('cliente_celular')
                    ->label('Celular')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Celular copiado'),

                TextColumn::make('cliente_email')
                    ->label('E-mail')
                    ->searchable(),

                TextColumn::make('cliente_cidade')
                    ->label('Cidade / UF')
                    ->state(function (Cliente $record): string {
                        $cidade = (string) ($record->cliente_cidade ?? '');
                        $uf = (string) ($record->cliente_uf_estado ?? '');

                        return trim("{$cidade} / {$uf}", ' /') ?: 'Não informado';
                    })
                    ->searchable(),

                TextColumn::make('pedidos_count')
                    ->label('Pedidos')
                    ->badge()
                    ->sortable(),

                TextColumn::make('ultimo_pedido_em')
                    ->label('Último Pedido')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sem pedidos')
                    ->sortable(),

                TextColumn::make('cliente_endereco')
                    ->label('Endereço')
                    ->state(function (Cliente $record): string {
                        $logradouro = trim((string) ($record->cliente_endereco ?? ''));
                        $numero = trim((string) ($record->cliente_numero_endereco ?? ''));
                        $bairro = trim((string) ($record->cliente_bairro ?? ''));

                        return trim("{$logradouro}, {$numero} - {$bairro}", ', -') ?: 'Não informado';
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),

                TextColumn::make('cliente_data_nascimento')
                    ->label('Nascimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Cadastro')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Atualizado')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('deleted_at')
                    ->label('Excluído')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('cliente_tipo')
                    ->label('Tipo de Cliente')
                    ->options([
                        'PF' => 'Pessoa Física',
                        'PJ' => 'Pessoa Jurídica',
                    ])
                    ->query(function (Builder $query, $value): Builder {
                        return match ((string) $value) {
                            'PF' => $query->whereIn('cliente_tipo', ['PF', 'FISICA', 'PESSOA FISICA', 'PESSOA FÍSICA']),
                            'PJ' => $query->whereIn('cliente_tipo', ['PJ', 'JURIDICA', 'JURÍDICA', 'PESSOA JURIDICA', 'PESSOA JURÍDICA']),
                            default => $query,
                        };
                    }),

                SelectFilter::make('cliente_uf_estado')
                    ->label('UF')
                    ->options(self::UFS)
                    ->searchable(),

                TernaryFilter::make('com_email')
                    ->label('Com E-mail')
                    ->trueLabel('Somente com e-mail')
                    ->falseLabel('Somente sem e-mail')
                    ->query(function (Builder $query, $value): Builder {
                        return match ($value) {
                            true => $query->whereNotNull('cliente_email')->where('cliente_email', '!=', ''),
                            false => $query->where(function (Builder $subQuery): void {
                                $subQuery->whereNull('cliente_email')->orWhere('cliente_email', '');
                            }),
                            default => $query,
                        };
                    }),

                TernaryFilter::make('com_celular')
                    ->label('Com Celular')
                    ->trueLabel('Somente com celular')
                    ->falseLabel('Somente sem celular')
                    ->query(function (Builder $query, $value): Builder {
                        return match ($value) {
                            true => $query->whereNotNull('cliente_celular')->where('cliente_celular', '!=', ''),
                            false => $query->where(function (Builder $subQuery): void {
                                $subQuery->whereNull('cliente_celular')->orWhere('cliente_celular', '');
                            }),
                            default => $query,
                        };
                    }),

                TernaryFilter::make('com_pedidos')
                    ->label('Histórico de Pedidos')
                    ->trueLabel('Somente com pedidos')
                    ->falseLabel('Somente sem pedidos')
                    ->query(function (Builder $query, $value): Builder {
                        return match ($value) {
                            true => $query->has('pedidos'),
                            false => $query->doesntHave('pedidos'),
                            default => $query,
                        };
                    }),

                Filter::make('cidade')
                    ->label('Cidade')
                    ->form([
                        TextInput::make('cidade')
                            ->label('Nome da cidade')
                            ->placeholder('Ex: São Paulo'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $cidade = trim((string) ($data['cidade'] ?? ''));

                        if ($cidade === '') {
                            return $query;
                        }

                        return $query->where('cliente_cidade', 'like', '%' . $cidade . '%');
                    }),

                Filter::make('periodo_cadastro')
                    ->label('Período de Cadastro')
                    ->form([
                        DatePicker::make('data_inicio')->label('De'),
                        DatePicker::make('data_fim')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $dataInicio = $data['data_inicio'] ?? null;
                        $dataFim = $data['data_fim'] ?? null;

                        if (! $dataInicio && ! $dataFim) {
                            return $query;
                        }

                        return $query
                            ->when($dataInicio, fn(Builder $subQuery) => $subQuery->whereDate('created_at', '>=', $dataInicio))
                            ->when($dataFim, fn(Builder $subQuery) => $subQuery->whereDate('created_at', '<=', $dataFim));
                    }),

                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionsAction::make('abrir_whatsapp')
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->visible(fn(Cliente $record): bool => filled($record->cliente_celular))
                    ->url(fn(Cliente $record): string => self::whatsAppUrl((string) $record->cliente_celular))
                    ->openUrlInNewTab(),

                ActionsAction::make('enviar_email')
                    ->label('E-mail')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->visible(fn(Cliente $record): bool => filled($record->cliente_email))
                    ->url(fn(Cliente $record): string => 'mailto:' . trim((string) $record->cliente_email))
                    ->openUrlInNewTab(),

                EditAction::make()
                    ->label('Editar'),

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

    private static function tipoLabel(?string $tipo): string
    {
        $tipo = strtoupper(trim((string) $tipo));

        if (in_array($tipo, ['PJ', 'JURIDICA', 'JURÍDICA', 'PESSOA JURIDICA', 'PESSOA JURÍDICA'], true)) {
            return 'Pessoa Jurídica';
        }

        return 'Pessoa Física';
    }

    private static function documento(Cliente $cliente): string
    {
        $tipo = strtoupper(trim((string) $cliente->cliente_tipo));
        $documento = in_array($tipo, ['PJ', 'JURIDICA', 'JURÍDICA', 'PESSOA JURIDICA', 'PESSOA JURÍDICA'], true)
            ? (string) ($cliente->cliente_cnpj ?? '')
            : (string) ($cliente->cliente_cpf ?? '');

        $documento = trim($documento);

        return $documento !== '' ? $documento : 'Não informado';
    }

    private static function whatsAppUrl(string $celular): string
    {
        $digits = preg_replace('/\D+/', '', $celular) ?: '';
        $phone = str_starts_with($digits, '55') ? $digits : ('55' . $digits);

        return "https://wa.me/{$phone}";
    }
}
