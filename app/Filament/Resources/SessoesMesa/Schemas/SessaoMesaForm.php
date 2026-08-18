<?php

namespace App\Filament\Resources\SessoesMesa\Schemas;

use App\Enums\StatusSessaoMesa;
use App\Models\Mesa;
use App\Models\SessaoMesa;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class SessaoMesaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Abertura')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make('sessao_mesa_mesa_id')
                        ->label('Mesa')
                        ->options(fn (?SessaoMesa $record): array => self::opcoesMesa($record))
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->disabled(fn (?SessaoMesa $record): bool => $record?->exists ?? false)
                        ->dehydrated()
                        ->helperText('Depois de aberta, use a ação "Trocar de mesa" pra mover a sessão — editar aqui não atualiza o status das mesas.'),

                    Select::make('sessao_mesa_usuario_id')
                        ->label('Garçom')
                        ->relationship('garcom', 'name')
                        ->default(fn (): ?int => auth()->id())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->disabled(fn (): bool => ! (auth()->user()?->hasRole('Gerente') ?? false))
                        ->dehydrated(),

                    Select::make('sessao_mesa_cliente_id')
                        ->label('Cliente principal')
                        ->relationship('cliente', 'cliente_nome')
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->helperText('Opcional — outros clientes podem ser adicionados na aba "Clientes" depois de salvar.'),

                    Text::make(fn (SessaoMesa $record): string => 'Status: '.StatusSessaoMesa::from($record->sessao_mesa_status)->getLabel())
                        ->visible(fn (?SessaoMesa $record): bool => $record?->exists ?? false),
                ]),

            Section::make('Cancelamento')
                ->columnSpanFull()
                ->visible(fn (?SessaoMesa $record): bool => $record?->sessao_mesa_status === 'CANCELADA')
                ->schema([
                    Textarea::make('sessao_mesa_motivo_cancelamento')
                        ->label('Motivo do cancelamento')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Na abertura só mesas LIBERADA; em edição, mantém a mesa já vinculada mesmo que tenha mudado de status por fora. */
    private static function opcoesMesa(?SessaoMesa $record): array
    {
        return Mesa::query()
            ->where(function ($query) use ($record): void {
                $query->where('mesa_status', 'LIBERADA');

                if ($record?->sessao_mesa_mesa_id) {
                    $query->orWhere('id', $record->sessao_mesa_mesa_id);
                }
            })
            ->orderBy('mesa_nome')
            ->pluck('mesa_nome', 'id')
            ->toArray();
    }
}
