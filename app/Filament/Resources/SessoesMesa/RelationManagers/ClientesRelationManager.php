<?php

namespace App\Filament\Resources\SessoesMesa\RelationManagers;

use App\Models\Cliente;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Gestão dos clientes extras vinculados à sessão (SessaoMesaCliente) — espelha
 * SessaoMesaController::adicionarClientesSessao/removerClienteSessao, parte do
 * ciclo de vida da sessão (não do lançamento de itens).
 */
class ClientesRelationManager extends RelationManager
{
    protected static string $relationship = 'clientes';

    protected static ?string $title = 'Clientes da sessão';

    protected static ?string $modelLabel = 'cliente';

    protected static ?string $pluralModelLabel = 'clientes';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('smc_cliente_id')
                ->label('Cliente')
                ->relationship('cliente', 'cliente_nome')
                ->searchable()
                ->preload()
                ->createOptionForm([
                    TextInput::make('cliente_nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(150),
                    TextInput::make('cliente_celular')
                        ->label('Celular')
                        ->tel(),
                ])
                ->createOptionUsing(fn (array $data): int => Cliente::create([
                    'cliente_nome' => $data['cliente_nome'],
                    'cliente_celular' => $data['cliente_celular'] ?: null,
                    'cliente_tipo' => 'Física',
                ])->id)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('smc_cliente_id')
            ->columns([
                TextColumn::make('cliente.cliente_nome')
                    ->label('Nome'),
                TextColumn::make('cliente.cliente_celular')
                    ->label('Celular')
                    ->placeholder('—'),
            ])
            ->filters([])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }
}
