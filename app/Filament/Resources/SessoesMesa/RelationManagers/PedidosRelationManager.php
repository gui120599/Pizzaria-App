<?php

namespace App\Filament\Resources\SessoesMesa\RelationManagers;

use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\PedidosSessaoMesaService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Pedidos vinculados a esta sessão de mesa. Lançar itens continua no Painel
 * do Garçom; aqui dá para juntar à conta um pedido já existente (balcão,
 * cardápio, entrega ou outra mesa) e tirar da mesa um pedido lançado errado —
 * mesmas regras da tela legada e do garçom (PedidosSessaoMesaService).
 */
class PedidosRelationManager extends RelationManager
{
    protected static string $relationship = 'pedidos';

    protected static ?string $title = 'Pedidos da sessão';

    protected static ?string $modelLabel = 'pedido';

    protected static ?string $pluralModelLabel = 'pedidos';

    /** @var array<int, int>|null Pedidos removíveis, calculado uma vez por request. */
    private ?array $removiveisCache = null;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('cliente.cliente_nome')
                    ->label('Cliente')
                    ->placeholder('—'),
                TextColumn::make('garcom.name_first')
                    ->label('Garçom')
                    ->placeholder('—'),
                TextColumn::make('pedido_status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('pedido_valor_total')
                    ->label('Valor total')
                    ->money('BRL'),
                TextColumn::make('pedido_datahora_abertura')
                    ->label('Aberto em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([])
            ->headerActions([
                Action::make('adicionarExistentes')
                    ->label('Adicionar pedido existente')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Adicionar pedidos à conta da mesa')
                    ->modalDescription('Pedidos ativos de outras mesas ou sem mesa, ainda não lançados no caixa.')
                    ->modalSubmitActionLabel('Adicionar selecionados')
                    ->visible(fn (): bool => $this->sessaoAberta())
                    ->authorize(fn (): bool => $this->podeAlterar())
                    ->schema(function (): array {
                        $opcoes = $this->servico()->opcoesParaAdicionar($this->getOwnerRecord(), Auth::user());

                        return [
                            CheckboxList::make('pedidos')
                                ->hiddenLabel()
                                ->options($opcoes['opcoes'])
                                ->descriptions($opcoes['descricoes'])
                                ->searchable()
                                ->bulkToggleable()
                                ->noSearchResultsMessage('Nenhum pedido encontrado.')
                                ->required()
                                ->validationMessages(['required' => 'Selecione ao menos um pedido.']),
                        ];
                    })
                    ->action(fn (array $data) => $this->executar(
                        fn (PedidosSessaoMesaService $servico, SessaoMesa $sessao, User $usuario) => $servico->adicionar($sessao, $data['pedidos'], $usuario),
                        'adicionado(s) à mesa',
                    )),
            ])
            ->recordActions([
                Action::make('removerDaMesa')
                    ->label('Remover da mesa')
                    ->icon('heroicon-o-minus-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Pedido $record): string => "Remover o pedido #{$record->id} da mesa?")
                    ->modalDescription('Ele fica sem mesa e vai para Pedidos avulsos no caixa.')
                    ->modalSubmitActionLabel('Remover')
                    ->visible(fn (Pedido $record): bool => $this->sessaoAberta() && in_array($record->id, $this->idsRemoviveis(), true))
                    ->authorize(fn (): bool => $this->podeAlterar())
                    ->action(fn (Pedido $record) => $this->executar(
                        fn (PedidosSessaoMesaService $servico, SessaoMesa $sessao, User $usuario) => $servico->remover($sessao, [$record->id], $usuario),
                        'removido(s) da mesa',
                    )),
            ])
            ->toolbarActions([
                BulkAction::make('removerSelecionadosDaMesa')
                    ->label('Remover da mesa')
                    ->icon('heroicon-o-minus-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Os pedidos ficam sem mesa e vão para Pedidos avulsos no caixa. Pedidos já lançados no caixa são ignorados.')
                    ->visible(fn (): bool => $this->sessaoAberta())
                    ->authorize(fn (): bool => $this->podeAlterar())
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->executar(
                        fn (PedidosSessaoMesaService $servico, SessaoMesa $sessao, User $usuario) => $servico->remover($sessao, $records->modelKeys(), $usuario),
                        'removido(s) da mesa',
                    )),
            ]);
    }

    /** @param  callable(PedidosSessaoMesaService, SessaoMesa, User): int  $operacao */
    private function executar(callable $operacao, string $sufixo): void
    {
        try {
            /** @var SessaoMesa $sessao */
            $sessao = $this->getOwnerRecord();
            $quantidade = $operacao($this->servico(), $sessao, Auth::user());
            $this->removiveisCache = null;
        } catch (RuntimeException|AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title("{$quantidade} pedido(s) {$sufixo}.")->success()->send();
    }

    private function sessaoAberta(): bool
    {
        return $this->getOwnerRecord()->sessao_mesa_status === 'ABERTA';
    }

    private function podeAlterar(): bool
    {
        return (bool) Auth::user()?->can('update', $this->getOwnerRecord());
    }

    /** @return array<int, int> */
    private function idsRemoviveis(): array
    {
        return $this->removiveisCache ??= $this->servico()->queryRemoviveis($this->getOwnerRecord())->pluck('id')->all();
    }

    private function servico(): PedidosSessaoMesaService
    {
        return app(PedidosSessaoMesaService::class);
    }
}
