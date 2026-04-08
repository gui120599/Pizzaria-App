<?php

namespace App\Filament\Tables;

use App\Models\Cliente;
use Filament\Actions\Action as ActionsAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class Clientes
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Cliente::query()
                ->withCount('pedidos')
                ->withMax('pedidos as ultimo_pedido_em', 'pedido_datahora_abertura')
            )
            ->defaultSort('cliente_nome')
            ->searchPlaceholder('Buscar cliente por nome, documento ou celular...')
            ->emptyStateHeading('Nenhum cliente disponível')
            ->emptyStateDescription('Cadastre um cliente para vinculá-lo ao pedido.')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cliente_nome')
                    ->label('Cliente')
                    ->searchable(['cliente_nome', 'cliente_cpf', 'cliente_cnpj', 'cliente_celular'])
                    ->sortable(),

                TextColumn::make('cliente_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->state(function (Cliente $record): string {
                        $tipo = strtoupper(trim((string) $record->cliente_tipo));

                        return in_array($tipo, ['PJ', 'JURIDICA', 'JURÍDICA', 'PESSOA JURIDICA', 'PESSOA JURÍDICA'], true)
                            ? 'PJ'
                            : 'PF';
                    }),

                TextColumn::make('cliente_cpf')
                    ->label('Documento')
                    ->state(function (Cliente $record): string {
                        $tipo = strtoupper((string) $record->cliente_tipo);
                        $documento = $tipo === 'PJ' ? $record->cliente_cnpj : $record->cliente_cpf;

                        return $documento ?: 'Não informado';
                    }),

                TextColumn::make('cliente_celular')
                    ->label('Celular')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Celular copiado'),

                TextColumn::make('cliente_cidade')
                    ->label('Cidade / UF')
                    ->state(function (Cliente $record): string {
                        $cidade = trim((string) ($record->cliente_cidade ?? ''));
                        $uf = trim((string) ($record->cliente_uf_estado ?? ''));

                        return trim("{$cidade} / {$uf}", ' /') ?: 'Não informado';
                    })
                    ->searchable(),

                TextColumn::make('pedidos_count')
                    ->label('Pedidos')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('ultimo_pedido_em')
                    ->label('Último Pedido')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sem pedidos')
                    ->sortable(),
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
                    ->options(function (): array {
                        return Cliente::query()
                            ->whereNotNull('cliente_uf_estado')
                            ->where('cliente_uf_estado', '!=', '')
                            ->orderBy('cliente_uf_estado')
                            ->pluck('cliente_uf_estado', 'cliente_uf_estado')
                            ->toArray();
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

                TernaryFilter::make('cadastro_entrega_completo')
                    ->label('Cadastro para Entrega')
                    ->trueLabel('Somente completos')
                    ->falseLabel('Somente incompletos')
                    ->query(function (Builder $query, $value): Builder {
                        $scope = fn (Builder $subQuery): Builder => $subQuery
                            ->whereNotNull('cliente_endereco')->where('cliente_endereco', '!=', '')
                            ->whereNotNull('cliente_numero_endereco')->where('cliente_numero_endereco', '!=', '')
                            ->whereNotNull('cliente_bairro')->where('cliente_bairro', '!=', '')
                            ->whereNotNull('cliente_cidade')->where('cliente_cidade', '!=', '')
                            ->whereNotNull('cliente_uf_estado')->where('cliente_uf_estado', '!=', '')
                            ->whereNotNull('cliente_cep')->where('cliente_cep', '!=', '');

                        return match ($value) {
                            true => $scope($query),
                            false => $query->where(function (Builder $subQuery): void {
                                $subQuery
                                    ->whereNull('cliente_endereco')->orWhere('cliente_endereco', '')
                                    ->orWhereNull('cliente_numero_endereco')->orWhere('cliente_numero_endereco', '')
                                    ->orWhereNull('cliente_bairro')->orWhere('cliente_bairro', '')
                                    ->orWhereNull('cliente_cidade')->orWhere('cliente_cidade', '')
                                    ->orWhereNull('cliente_uf_estado')->orWhere('cliente_uf_estado', '')
                                    ->orWhereNull('cliente_cep')->orWhere('cliente_cep', '');
                            }),
                            default => $query,
                        };
                    }),

                Filter::make('com_pedido_recente')
                    ->label('Pedido recente (30 dias)')
                    ->query(fn (Builder $query): Builder => $query->whereHas('pedidos', fn (Builder $subQuery): Builder => $subQuery
                        ->whereDate('pedido_datahora_abertura', '>=', now()->subDays(30)->toDateString())
                    ))
                    ->toggle(),

                TernaryFilter::make('com_pedidos')
                    ->label('Com Pedidos')
                    ->trueLabel('Somente com pedidos')
                    ->falseLabel('Somente sem pedidos')
                    ->query(function (Builder $query, $value): Builder {
                        return match ($value) {
                            true => $query->has('pedidos'),
                            false => $query->doesntHave('pedidos'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ActionsAction::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->visible(fn(Cliente $record): bool => filled($record->cliente_celular))
                    ->url(fn(Cliente $record): string => self::whatsAppUrl((string) $record->cliente_celular))
                    ->openUrlInNewTab(),
            ]);
    }

    private static function whatsAppUrl(string $celular): string
    {
        $digits = preg_replace('/\D+/', '', $celular) ?: '';
        $phone = str_starts_with($digits, '55') ? $digits : ('55' . $digits);

        return "https://wa.me/{$phone}";
    }
}
