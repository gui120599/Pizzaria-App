<?php

namespace App\Filament\Pages;

use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Support\PedidoStatusActions;
use App\Models\Pedido;
use App\Support\JanelaOperacional;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Kanban operacional de pedidos — substitui resources/views/app/pedido/abertos.blade.php.
 *
 * A tela legada tinha 1574 linhas de Blade com jQuery: cinco endpoints AJAX que
 * devolviam o model inteiro serializado sem filtro de data, o markup do card
 * duplicado quatro vezes, polling só na primeira coluna e handlers de clique
 * re-registrados a cada refresh (que acumulavam e podiam disparar a mesma
 * transição duas vezes — logo, baixa dupla de estoque).
 *
 * Aqui são 3 queries por render, um partial de card só, polling com guarda de
 * assinatura, e as transições passando pelo PedidoStatusService, que serializa
 * concorrência com lockForUpdate.
 *
 * A Page já É um componente Livewire. Não há componente filho por coluna de
 * propósito: seis filhos custariam seis hidratações e seis queries por ciclo de
 * poll, além de precisarem conversar entre si quando um card muda de coluna.
 */
class PainelPedidos extends Page implements HasActions
{
    use InteractsWithActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Painel de Pedidos';

    protected static UnitEnum|string|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 19;

    protected static ?string $title = 'Painel de Pedidos';

    protected static ?string $slug = 'pedidos/painel';

    protected string $view = 'filament.pages.painel-pedidos';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** Data de abertura do turno cujos pedidos entregues a coluna final mostra. */
    public ?string $dataEntregue = null;

    public bool $mostrarForaDoTurno = false;

    public bool $entregueExpandido = false;

    /**
     * Impressão digital do estado do board. Enquanto não muda, o poll devolve
     * resposta vazia — ver atualizar().
     */
    public string $assinatura = '';

    /** Contagem da fila de entrada, para tocar o alerta só quando ela cresce. */
    public int $contagemEntrada = 0;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_any:pedido');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->dataEntregue ??= Carbon::now()->toDateString();
        $this->assinatura = $this->assinaturaAtual();
        $this->contagemEntrada = $this->contarEntrada();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ações (catálogo compartilhado com PedidosTable/AtenderPedido)
    // ─────────────────────────────────────────────────────────────────────────

    public function confirmarAction(): Action
    {
        return PedidoStatusActions::confirmar();
    }

    public function aceitarAction(): Action
    {
        return PedidoStatusActions::aceitar();
    }

    public function avancarAction(): Action
    {
        return PedidoStatusActions::avancar();
    }

    public function despacharAction(): Action
    {
        return PedidoStatusActions::despachar();
    }

    public function rejeitarAction(): Action
    {
        return PedidoStatusActions::rejeitar();
    }

    public function cancelarAction(): Action
    {
        return PedidoStatusActions::cancelar();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdfEntregues')
                ->label('PDF do dia')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('pedidosEntreguesFinalizadosCanceladosPDF.imprimir', [
                    'datahora_abertura' => $this->dataEntregue,
                ]), shouldOpenInNewTab: true),

            Action::make('pdfEntregas')
                ->label('PDF de entregas')
                ->icon(Heroicon::OutlinedTruck)
                ->color('gray')
                ->url(fn (): string => route('pedidosEntregasPDF.imprimir', [
                    'datahora_abertura' => $this->dataEntregue,
                ]), shouldOpenInNewTab: true),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tempo real
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Chamado pelo wire:poll. Só invalida as listas quando a assinatura muda —
     * no estado estacionário a resposta é vazia, sem DOM diff nem as queries de
     * card.
     */
    public function atualizar(): void
    {
        $nova = $this->assinaturaAtual();

        if ($nova === $this->assinatura) {
            $this->skipRender();

            return;
        }

        $this->assinatura = $nova;
        unset($this->colunas, $this->entregues, $this->foraDoTurno, $this->totalForaDoTurno);

        $entrada = $this->contarEntrada();

        if ($entrada > $this->contagemEntrada && $this->contagemEntrada > 0) {
            $this->dispatch('novo-pedido');
        }

        $this->contagemEntrada = $entrada;
    }

    /**
     * Contagem por status + MAX(updated_at) das colunas de fluxo.
     *
     * A contagem é indispensável: EntregaService::aceitar() e
     * ConfirmacoesPedidos::confirmar() gravam por query builder, que NÃO toca
     * updated_at. Só o timestamp deixaria essas transições invisíveis ao poll.
     * Qualquer mudança de status move contagem entre grupos.
     *
     * dataEntregue e entregueExpandido ficam de fora de propósito: mudá-los já
     * re-renderiza por si, e incluí-los aqui viraria loop.
     */
    private function assinaturaAtual(): string
    {
        return Pedido::query()
            ->whereIn('pedido_status', StatusPedidoEnum::valoresKanban())
            ->selectRaw('pedido_status, COUNT(*) AS total, COALESCE(MAX(updated_at), 0) AS ultimo')
            ->groupBy('pedido_status')
            ->orderBy('pedido_status')
            ->get()
            ->map(fn ($linha) => "{$linha->pedido_status}:{$linha->total}:{$linha->ultimo}")
            ->implode('|');
    }

    private function contarEntrada(): int
    {
        return Pedido::query()
            ->whereIn('pedido_status', [StatusPedidoEnum::INICIADO->value, StatusPedidoEnum::ABERTO->value])
            ->count();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dados das colunas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Colunas de fluxo ativo, em UMA query, agrupadas em memória.
     *
     * @return array<string, Collection<int, Pedido>>
     */
    #[Computed]
    public function colunas(): array
    {
        [$inicio] = JanelaOperacional::atual();

        $pedidos = $this->baseQuery()
            ->whereIn('pedido_status', StatusPedidoEnum::valoresEmAndamento())
            // INICIADO de outras origens é rascunho em construção no
            // AtenderPedido/PDV — nunca deve aparecer na fila da cozinha.
            ->where(fn (Builder $q) => $q
                ->where('pedido_status', '!=', StatusPedidoEnum::INICIADO->value)
                ->orWhere('pedido_origem', PedidoOrigemEnum::CARDAPIO->value))
            ->when(! $this->mostrarForaDoTurno, fn (Builder $q) => $q->where(
                fn (Builder $sub) => $sub
                    ->where('pedido_datahora_abertura', '>=', $inicio)
                    ->orWhereNull('pedido_datahora_abertura')
            ))
            ->orderBy('pedido_datahora_abertura')
            ->orderBy('id')
            ->get()
            ->pipe(fn (EloquentCollection $c) => $this->somenteComItens($c))
            ->groupBy('pedido_status');

        // Pré-preenche todas as colunas: sem isso, coluna vazia sai do grid.
        $vazias = collect(StatusPedidoEnum::valoresEmAndamento())
            ->mapWithKeys(fn (string $status) => [$status => collect()]);

        return $vazias->merge($pedidos)->all();
    }

    /**
     * Coluna "Entregue": janela do turno escolhido, mais recentes primeiro e com
     * limite — é a única coluna que cresce sem parar ao longo do dia.
     *
     * @return Collection<int, Pedido>
     */
    #[Computed]
    public function entregues(): Collection
    {
        [$inicio, $fim] = JanelaOperacional::paraDiaDeAbertura($this->dataEntregue ?? Carbon::now());

        return $this->baseQuery()
            ->where('pedido_status', StatusPedidoEnum::ENTREGUE->value)
            ->whereBetween('pedido_datahora_entrega', [$inicio, $fim])
            ->orderByDesc('pedido_datahora_entrega')
            ->limit($this->limiteEntregue())
            ->get()
            ->pipe(fn (EloquentCollection $c) => $this->somenteComItens($c));
    }

    /**
     * Quantos pedidos estão presos em status de fluxo de turnos anteriores.
     * Só a contagem — a listagem só é carregada se o operador abrir.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function foraDoTurno(): array
    {
        [$inicio] = JanelaOperacional::atual();

        return Pedido::query()
            ->whereIn('pedido_status', StatusPedidoEnum::valoresEmAndamento())
            ->where('pedido_datahora_abertura', '<', $inicio)
            ->selectRaw('pedido_status, COUNT(*) AS total')
            ->groupBy('pedido_status')
            ->pluck('total', 'pedido_status')
            ->all();
    }

    #[Computed]
    public function totalForaDoTurno(): int
    {
        return (int) array_sum($this->foraDoTurno);
    }

    /**
     * Colunas a renderizar. Sai da config, então a view não decide isso.
     *
     * @return array<int, StatusPedidoEnum>
     */
    #[Computed]
    public function colunasKanban(): array
    {
        return StatusPedidoEnum::colunasKanban();
    }

    public function usaEstagioTransporte(): bool
    {
        return in_array(StatusPedidoEnum::EM_TRANSPORTE, StatusPedidoEnum::colunasKanban(), true);
    }

    /**
     * Ação do botão principal do card, decidida POR PEDIDO.
     *
     * Na coluna Prontos convivem pedidos de delivery e de mesa/balcão, que têm
     * destinos diferentes: o delivery é despachado para EM TRANSPORTE, o de
     * mesa vai direto para ENTREGUE. Decidir pela coluna mandava pedido de mesa
     * para EM TRANSPORTE — despachar() salta a bifurcação de propósito, porque
     * é intenção explícita do operador.
     */
    public function acaoPrimaria(Pedido $pedido): ?string
    {
        return match ($pedido->status()) {
            StatusPedidoEnum::INICIADO => 'confirmar',
            StatusPedidoEnum::ABERTO => 'aceitar',
            StatusPedidoEnum::PREPARANDO, StatusPedidoEnum::EM_TRANSPORTE => 'avancar',
            StatusPedidoEnum::PRONTO => $pedido->usaEstagioTransporte() ? 'despachar' : 'avancar',
            default => null,
        };
    }

    public function rotuloPrimario(Pedido $pedido): string
    {
        return match ($pedido->status()) {
            StatusPedidoEnum::INICIADO => 'Confirmar',
            StatusPedidoEnum::ABERTO => 'Iniciar preparo',
            StatusPedidoEnum::PREPARANDO => 'Marcar pronto',
            StatusPedidoEnum::PRONTO => match (true) {
                $pedido->usaEstagioTransporte() && config('pizzaria.pedidos.atribui_entregador', true) => 'Despachar',
                $pedido->usaEstagioTransporte() => 'Saída para entrega',
                default => 'Finalizar',
            },
            StatusPedidoEnum::EM_TRANSPORTE => 'Confirmar entrega',
            default => 'Ver detalhes',
        };
    }

    public function alternarForaDoTurno(): void
    {
        $this->mostrarForaDoTurno = ! $this->mostrarForaDoTurno;
        unset($this->colunas);
    }

    public function expandirEntregues(): void
    {
        $this->entregueExpandido = true;
        unset($this->entregues);
    }

    public function updatedDataEntregue(): void
    {
        $this->entregueExpandido = false;
        unset($this->entregues);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Select enxuto + eager load do que o card realmente mostra.
     *
     * Toda lista de colunas numa relação inclui a FK — sem ela o eager load
     * devolve vazio silenciosamente (é o que PainelPedidosQueryCountTest pega).
     */
    private function baseQuery(): Builder
    {
        return Pedido::query()
            ->select([
                'id',
                'pedido_status',
                'pedido_origem',
                'pedido_opcaoentrega_id',
                'pedido_sessao_mesa_id',
                'pedido_cliente_id',
                'pedido_usuario_entrega_id',
                'pedido_endereco_entrega',
                'pedido_venda_id',
                'pedido_valor_total',
                'pedido_datahora_abertura',
                'pedido_datahora_preparo',
                'pedido_datahora_pronto',
                'pedido_datahora_transporte',
                'pedido_datahora_entrega',
                'pedido_datahora_finalizado',
                'created_at',
                'updated_at',
            ])
            ->with([
                'opcaoEntrega:id,opcaoentrega_nome,opcaoentrega_requer_endereco',
                'sessaoMesa:id,sessao_mesa_mesa_id',
                'sessaoMesa.mesa:id,mesa_nome',
                'entregador:id,name_first',
                'cliente:id,cliente_nome',
                // O filtro de itens vai para a query. A tela legada trazia os
                // itens cancelados pela rede e filtrava em JavaScript, a cada
                // ciclo de poll.
                'item_pedido_pedido_id' => fn ($q) => $q
                    ->where('item_pedido_status', 'INSERIDO')
                    ->select([
                        'id',
                        'item_pedido_pedido_id',
                        'item_pedido_produto_id',
                        'item_pedido_quantidade',
                        'item_pedido_valor',
                        'item_pedido_observacao',
                    ])
                    ->with([
                        'produto:id,produto_descricao,produto_categoria_id',
                        'produto.categoria:id,categoria_nome',
                        'adicionaisItemPedido:id,aip_item_pedido_id,aip_adicional_id,aip_quantidade',
                        'adicionaisItemPedido.adicional:id,adicional_nome',
                    ]),
            ]);
    }

    /**
     * Descarta pedido sem nenhum item INSERIDO — mesma regra da tela legada,
     * que pulava o card quando a lista filtrada ficava vazia. Feito em PHP: os
     * itens já vieram carregados, e um whereHas correlacionado custaria mais.
     *
     * @param  EloquentCollection<int, Pedido>  $pedidos
     * @return Collection<int, Pedido>
     */
    private function somenteComItens(EloquentCollection $pedidos): Collection
    {
        return $pedidos->reject(
            fn (Pedido $pedido) => $pedido->item_pedido_pedido_id->isEmpty()
        )->values();
    }

    private function limiteEntregue(): int
    {
        return (int) ($this->entregueExpandido
            ? config('pizzaria.pedidos.limite_entregue_expandido', 200)
            : config('pizzaria.pedidos.limite_entregue', 50));
    }
}
