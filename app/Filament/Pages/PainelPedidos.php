<?php

namespace App\Filament\Pages;

use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\UrgenciaPedidoEnum;
use App\Filament\Support\PedidoStatusActions;
use App\Models\LinhaProducao;
use App\Models\Pedido;
use App\Models\User;
use App\Support\JanelaOperacional;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session as LivewireSession;
use Livewire\Attributes\Url;
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
 * Aqui são poucas queries por render, um partial de card só, polling com
 * guarda de assinatura, e as transições passando pelo PedidoStatusService, que
 * serializa concorrência com lockForUpdate.
 *
 * A Page já É um componente Livewire. Não há componente filho por coluna de
 * propósito: seis filhos custariam seis hidratações e seis queries por ciclo de
 * poll, além de precisarem conversar entre si quando um card muda de coluna.
 *
 * Filtros (busca, tipo, entregador, período, atrasados, cancelados) sobrevivem
 * a um F5 via #[Session] — sem tabela nova. A exceção é `linhaProducaoId`, que
 * só vem de `#[Url]`: é o mecanismo de fixar um tablet numa estação (ex.:
 * .../pedidos/painel?linha=3), e um valor de sessão concorrendo com isso só
 * confundiria qual dos dois "ganha" ao trocar de tablet.
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

    /** Data de abertura do turno cujos pedidos finalizados a coluna final mostra. */
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

    /**
     * Rodadas de mesa (Painel do Garçom) já entregues ao navegador para
     * impressão automática. Quem imprime é o JS, e só se o aparelho ligou a
     * opção — ver painel-pedidos.blade.php.
     *
     * @var array<int, int>
     */
    public array $rodadasMesaImpressas = [];

    /** Rodadas enviadas antes de a tela abrir não são reimpressas. */
    public ?string $impressaoMesaDesde = null;

    // ─────────────────────────────────────────────────────────────────────────
    // Filtros — ver o docblock da classe sobre por que linhaProducaoId é à parte.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 'todos'|'delivery'|'retirada'|'mesa'|'novos'|'preparando'|'prontos'|
     * 'em_entrega'|'finalizados' — um único filtro rápido cobre tipo de
     * atendimento OU etapa do fluxo, nunca os dois ao mesmo tempo (mesmo
     * desenho do quickFilter da Central de Pedidos do RazelFood).
     */
    #[LivewireSession]
    public string $filtroRapido = 'todos';

    #[LivewireSession]
    public string $busca = '';

    #[LivewireSession]
    public ?int $entregadorId = null;

    #[LivewireSession]
    public ?string $periodoDe = null;

    #[LivewireSession]
    public ?string $periodoAte = null;

    #[LivewireSession]
    public bool $somenteAtrasados = false;

    #[LivewireSession]
    public bool $mostrarCancelados = false;

    #[Url(as: 'linha')]
    public ?int $linhaProducaoId = null;

    /**
     * Colunas de status que o usuário escolheu esconder — preferência gravada
     * no próprio usuário (users.preferencias), vale em qualquer aparelho.
     *
     * @var array<int, string>
     */
    public array $colunasOcultas = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('view_any:pedido');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->dataEntregue ??= Carbon::now()->toDateString();

        $this->colunasOcultas = array_values(Auth::user()?->preferencias['painel_pedidos']['colunas_ocultas'] ?? []);

        // linhaProducaoId não usa #[Session] (ver docblock da classe), mas
        // ainda assim sobrevive a navegação sem ?linha= na URL, contanto que
        // nenhuma URL explícita tenha vindo primeiro.
        if ($this->linhaProducaoId === null) {
            $this->linhaProducaoId = Session::get($this->sessaoChaveLinha());
        }

        $this->assinatura = $this->assinaturaAtual();
        $this->contagemEntrada = $this->contarEntrada();
        $this->impressaoMesaDesde = Carbon::now()->toDateTimeString();
    }

    public function updatedLinhaProducaoId(): void
    {
        Session::put($this->sessaoChaveLinha(), $this->linhaProducaoId);
    }

    private function sessaoChaveLinha(): string
    {
        return 'painel-pedidos.linha_producao_id.'.Auth::id();
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

    public function verDetalhesAction(): Action
    {
        return PedidoStatusActions::verDetalhes();
    }

    public function linkEntregaAction(): Action
    {
        return PedidoStatusActions::linkEntrega();
    }

    public function trocarEntregadorAction(): Action
    {
        return PedidoStatusActions::trocarEntregador();
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
        unset($this->colunas, $this->entregues, $this->foraDoTurno, $this->totalForaDoTurno, $this->cancelados);

        $entrada = $this->contarEntrada();

        if ($entrada > $this->contagemEntrada && $this->contagemEntrada > 0) {
            $this->dispatch('novo-pedido');
        }

        $this->contagemEntrada = $entrada;

        $this->despacharRodadasMesaParaImpressao();
    }

    /**
     * Rodadas de mesa que chegaram à cozinha desde a última checagem: o
     * navegador imprime cada uma (pedido.imprimir) se a auto-impressão estiver
     * ligada neste aparelho.
     */
    private function despacharRodadasMesaParaImpressao(): void
    {
        $novas = Pedido::query()
            ->where('pedido_origem', PedidoOrigemEnum::MESA->value)
            ->whereNotIn('pedido_status', [StatusPedidoEnum::INICIADO->value, StatusPedidoEnum::CANCELADO->value])
            ->where('pedido_datahora_abertura', '>=', $this->impressaoMesaDesde ?? Carbon::now()->toDateTimeString())
            ->whereNotIn('id', $this->rodadasMesaImpressas)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($novas === []) {
            return;
        }

        $this->rodadasMesaImpressas = [...$this->rodadasMesaImpressas, ...$novas];

        $this->dispatch('imprimir-rodadas-mesa', urls: array_map(
            fn (int $id) => route('pedido.imprimir', ['id' => $id]),
            $novas,
        ));
    }

    /**
     * Contagem por status + MAX(updated_at) das colunas de fluxo (mais
     * CANCELADO quando a faixa de cancelados está visível, senão uma mudança
     * ali passaria batido pelo poll).
     *
     * A contagem é indispensável: EntregaService::aceitar() e
     * ConfirmacoesPedidos::confirmar() gravam por query builder, que NÃO toca
     * updated_at. Só o timestamp deixaria essas transições invisíveis ao poll.
     * Qualquer mudança de status move contagem entre grupos.
     *
     * dataEntregue, entregueExpandido e os filtros ficam de fora de propósito:
     * mudá-los já dispara um request Livewire próprio, que recalcula os
     * #[Computed] do zero — incluí-los aqui só geraria comparação inútil.
     */
    private function assinaturaAtual(): string
    {
        $status = StatusPedidoEnum::valoresKanban();

        if ($this->mostrarCancelados) {
            $status[] = 'CANCELADO';
        }

        return Pedido::query()
            ->whereIn('pedido_status', $status)
            ->selectRaw('pedido_status, COUNT(*) AS total, COALESCE(MAX(updated_at), 0) AS ultimo')
            ->groupBy('pedido_status')
            ->orderBy('pedido_status')
            ->get()
            ->map(fn ($linha) => "{$linha->pedido_status}:{$linha->total}:{$linha->ultimo}")
            ->implode('|');
    }

    /**
     * Fila de entrada: pedidos ABERTO mais os INICIADO do cardápio (aguardando
     * confirmação). Rascunho de outras origens — rodada do garçom ainda não
     * enviada, AtenderPedido/PDV — não entra, senão o alerta tocaria quando o
     * garçom abre a mesa e não quando a rodada chega.
     */
    private function contarEntrada(): int
    {
        return Pedido::query()
            ->where(fn (Builder $q) => $q
                ->where('pedido_status', StatusPedidoEnum::ABERTO->value)
                ->orWhere(fn (Builder $qq) => $qq
                    ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
                    ->where('pedido_origem', PedidoOrigemEnum::CARDAPIO->value)))
            ->count();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dados das colunas
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Colunas de fluxo ativo, em UMA query, agrupadas em memória.
     *
     * Um filtro rápido de etapa única (novos/preparando/prontos/em_entrega)
     * restringe a UMA coluna — as demais ficam vazias, como no board do
     * RazelFood. "finalizados" esvazia todas (o foco passa pra coluna final).
     *
     * @return array<string, Collection<int, Pedido>>
     */
    #[Computed]
    public function colunas(): array
    {
        $vazias = collect(StatusPedidoEnum::valoresEmAndamento())
            ->mapWithKeys(fn (string $status) => [$status => collect()]);

        if ($this->filtroRapido === 'finalizados') {
            return $vazias->all();
        }

        [$inicio] = JanelaOperacional::atual();
        $statusUnico = $this->statusFilterValue();

        $pedidos = $this->baseQuery()
            ->when(
                $statusUnico,
                fn (Builder $q, string $s) => $q->where('pedido_status', $s),
                fn (Builder $q) => $q->whereIn('pedido_status', StatusPedidoEnum::valoresEmAndamento()),
            )
            // INICIADO de outras origens é rascunho em construção no
            // AtenderPedido/PDV — nunca deve aparecer na fila da cozinha.
            ->where(fn (Builder $q) => $q
                ->where('pedido_status', '!=', StatusPedidoEnum::INICIADO->value)
                ->orWhere('pedido_origem', PedidoOrigemEnum::CARDAPIO->value))
            ->when(
                $this->periodoDe || $this->periodoAte,
                fn (Builder $q) => $this->aplicarPeriodo($q),
                fn (Builder $q) => $q->when(! $this->mostrarForaDoTurno, fn (Builder $qq) => $qq->where(
                    fn (Builder $sub) => $sub
                        ->where('pedido_datahora_abertura', '>=', $inicio)
                        ->orWhereNull('pedido_datahora_abertura')
                )),
            )
            ->orderBy('pedido_datahora_abertura')
            ->orderBy('id')
            ->get()
            ->pipe(fn (EloquentCollection $c) => $this->somenteComItens($c))
            ->pipe(fn (Collection $c) => $this->somenteAtrasados ? $this->apenasAtrasados($c) : $c)
            ->groupBy('pedido_status');

        return $vazias->merge($pedidos)->all();
    }

    /**
     * Coluna final: ENTREGUE + FINALIZADO juntos (pedido pago direto no caixa,
     * sem passar por "Em transporte", nunca tinha pedido_datahora_entrega — só
     * pedido_datahora_finalizado — e por isso sumia do board antes).
     *
     * Janela do turno escolhido, mais recentes primeiro e com limite — é a
     * única coluna que cresce sem parar ao longo do dia.
     *
     * @return Collection<int, Pedido>
     */
    #[Computed]
    public function entregues(): Collection
    {
        [$inicio, $fim] = JanelaOperacional::paraDiaDeAbertura($this->dataEntregue ?? Carbon::now());

        return $this->baseQuery()
            ->whereIn('pedido_status', [StatusPedidoEnum::ENTREGUE->value, StatusPedidoEnum::FINALIZADO->value])
            ->where(fn (Builder $q) => $q
                ->whereBetween('pedido_datahora_entrega', [$inicio, $fim])
                ->orWhereBetween('pedido_datahora_finalizado', [$inicio, $fim]))
            ->orderByRaw('COALESCE(pedido_datahora_entrega, pedido_datahora_finalizado) DESC')
            ->limit($this->limiteEntregue())
            ->get()
            ->pipe(fn (EloquentCollection $c) => $this->somenteComItens($c));
    }

    /**
     * Pedidos cancelados no turno atual — escondidos por padrão porque
     * cancelamento é exceção, não fluxo normal. Carrega quem cancelou pro
     * motivo aparecer no card.
     *
     * @return Collection<int, Pedido>
     */
    #[Computed]
    public function cancelados(): Collection
    {
        if (! $this->mostrarCancelados) {
            return collect();
        }

        [$inicio] = JanelaOperacional::atual();

        return $this->baseQuery()
            ->with('usuarioCancelou:id,name_first')
            ->where('pedido_status', StatusPedidoEnum::CANCELADO->value)
            ->where('pedido_datahora_cancelado', '>=', $inicio)
            ->orderByDesc('pedido_datahora_cancelado')
            ->limit(20)
            ->get();
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
        return array_values(array_filter(
            StatusPedidoEnum::colunasKanban(),
            fn (StatusPedidoEnum $status): bool => ! in_array($status->value, $this->colunasOcultas, true),
        ));
    }

    /**
     * Todas as colunas possíveis (respeitando a config do estágio Em
     * Transporte), para o seletor de colunas visíveis.
     *
     * @return array<int, StatusPedidoEnum>
     */
    public function colunasDisponiveis(): array
    {
        return StatusPedidoEnum::colunasKanban();
    }

    public function alternarColuna(string $status): void
    {
        $disponiveis = array_map(fn (StatusPedidoEnum $s): string => $s->value, StatusPedidoEnum::colunasKanban());

        if (! in_array($status, $disponiveis, true)) {
            return;
        }

        $ocultas = in_array($status, $this->colunasOcultas, true)
            ? array_values(array_diff($this->colunasOcultas, [$status]))
            : [...$this->colunasOcultas, $status];

        if (array_diff($disponiveis, $ocultas) === []) {
            Notification::make()->warning()->title('Mantenha ao menos uma coluna visível.')->send();

            return;
        }

        $this->colunasOcultas = $ocultas;
        unset($this->colunasKanban);

        $user = Auth::user();
        $preferencias = $user->preferencias ?? [];
        $preferencias['painel_pedidos']['colunas_ocultas'] = $ocultas;
        $user->forceFill(['preferencias' => $preferencias])->save();
    }

    public function usaEstagioTransporte(): bool
    {
        return in_array(StatusPedidoEnum::EM_TRANSPORTE, StatusPedidoEnum::colunasKanban(), true);
    }

    /** Entregadores pra o filtro e pro modal de troca — só quando a operação atribui entregador. */
    #[Computed]
    public function entregadores(): Collection
    {
        if (! $this->atribuiEntregador()) {
            return collect();
        }

        return User::role('Entregador')->orderBy('name_first')->pluck('name_first', 'id');
    }

    #[Computed]
    public function linhasProducaoOpcoes(): Collection
    {
        return LinhaProducao::orderBy('linha_nome')->pluck('linha_nome', 'id');
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
                $pedido->usaEstagioTransporte() && $this->atribuiEntregador() => 'Despachar',
                $pedido->usaEstagioTransporte() => 'Saída para entrega',
                default => 'Finalizar',
            },
            StatusPedidoEnum::EM_TRANSPORTE => 'Confirmar entrega',
            default => 'Ver detalhes',
        };
    }

    public function urgenciaDe(Pedido $pedido): UrgenciaPedidoEnum
    {
        $status = $pedido->status();

        return $status ? UrgenciaPedidoEnum::paraPedido($pedido, $status) : UrgenciaPedidoEnum::NORMAL;
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
    // Filtros
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<int, string> */
    private function statusOptionsMap(): array
    {
        return [
            'novos' => StatusPedidoEnum::INICIADO->value,
            'preparando' => StatusPedidoEnum::PREPARANDO->value,
            'prontos' => StatusPedidoEnum::PRONTO->value,
            'em_entrega' => StatusPedidoEnum::EM_TRANSPORTE->value,
        ];
    }

    private function statusFilterValue(): ?string
    {
        return $this->statusOptionsMap()[$this->filtroRapido] ?? null;
    }

    private function fulfillmentFilterValue(): ?string
    {
        return in_array($this->filtroRapido, ['delivery', 'retirada', 'mesa'], true)
            ? $this->filtroRapido
            : null;
    }

    private function atribuiEntregador(): bool
    {
        return (bool) config('pizzaria.pedidos.atribui_entregador', true);
    }

    /**
     * Select enxuto + eager load do que o card realmente mostra, com os
     * filtros que se aplicam em qualquer seção do board (busca, entregador,
     * tipo de atendimento, linha de produção). Cada seção (colunas ativas,
     * finalizados, cancelados) acrescenta por cima o próprio filtro de
     * status/data.
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
                'pedido_descricao_pagamento',
                'pedido_observacao_pagamento',
                'pedido_motivo_cancelamento',
                'pedido_usuario_cancelou_id',
                'pedido_datahora_abertura',
                'pedido_datahora_preparo',
                'pedido_datahora_pronto',
                'pedido_datahora_transporte',
                'pedido_datahora_entrega',
                'pedido_datahora_finalizado',
                'pedido_datahora_cancelado',
                'created_at',
                'updated_at',
            ])
            ->with([
                'opcaoEntrega:id,opcaoentrega_nome,opcaoentrega_requer_endereco',
                'sessaoMesa:id,sessao_mesa_mesa_id',
                'sessaoMesa.mesa:id,mesa_nome',
                'entregador:id,name_first',
                'cliente:id,cliente_nome,cliente_celular',
                // O filtro de itens vai para a query. A tela legada trazia os
                // itens cancelados pela rede e filtrava em JavaScript, a cada
                // ciclo de poll.
                'item_pedido_pedido_id' => fn ($q) => $q
                    ->where('item_pedido_status', 'INSERIDO')
                    ->select([
                        'id',
                        'item_pedido_pedido_id',
                        'item_pedido_produto_id',
                        'item_pedido_origem_id',
                        'item_pedido_quantidade',
                        'item_pedido_valor',
                        'item_pedido_observacao',
                        'item_pedido_sabores',
                    ])
                    ->with([
                        'produto:id,produto_descricao,produto_categoria_id',
                        'produto.categoria:id,categoria_nome',
                        'adicionaisItemPedido:id,aip_item_pedido_id,aip_adicional_id,aip_quantidade',
                        'adicionaisItemPedido.adicional:id,adicional_nome',
                    ]),
            ])
            ->when(trim($this->busca) !== '', fn (Builder $q) => $this->aplicarBusca($q))
            ->when(
                $this->entregadorId && $this->atribuiEntregador(),
                fn (Builder $q) => $q->where('pedido_usuario_entrega_id', $this->entregadorId),
            )
            ->when(
                $this->fulfillmentFilterValue(),
                fn (Builder $q, string $tipo) => $this->aplicarTipoAtendimento($q, $tipo),
            )
            ->when($this->linhaProducaoId, fn (Builder $q) => $this->aplicarLinhaProducao($q));
    }

    /** Busca por número do pedido (id numérico) ou nome/celular do cliente. */
    private function aplicarBusca(Builder $query): Builder
    {
        $termo = trim($this->busca);

        return $query->where(function (Builder $inner) use ($termo): void {
            if (is_numeric($termo)) {
                $inner->orWhere('id', (int) $termo);
            }

            $inner->orWhereHas('cliente', function (Builder $clienteQuery) use ($termo): void {
                $clienteQuery->where('cliente_nome', 'like', "%{$termo}%")
                    ->orWhere('cliente_celular', 'like', "%{$termo}%");
            });
        });
    }

    /**
     * Mesma regra de Pedido::tipoAtendimento(), em SQL: mesa tem prioridade,
     * delivery exige a flag da opção de entrega, retirada é o resto.
     */
    private function aplicarTipoAtendimento(Builder $query, string $tipo): Builder
    {
        return match ($tipo) {
            'mesa' => $query->whereNotNull('pedido_sessao_mesa_id'),
            'delivery' => $query->whereNull('pedido_sessao_mesa_id')
                ->whereHas('opcaoEntrega', fn (Builder $q) => $q->where('opcaoentrega_requer_endereco', true)),
            'retirada' => $query->whereNull('pedido_sessao_mesa_id')
                ->where(fn (Builder $q) => $q
                    ->whereNull('pedido_opcaoentrega_id')
                    ->orWhereHas('opcaoEntrega', fn (Builder $q2) => $q2->where('opcaoentrega_requer_endereco', false))),
            default => $query,
        };
    }

    /**
     * Pedido com item de alguma categoria da linha (ou de uma subcategoria
     * dela) — mostra o pedido inteiro, não só os itens da linha, igual ao
     * comportamento do ProductionLine do RazelFood.
     */
    private function aplicarLinhaProducao(Builder $query): Builder
    {
        $linha = LinhaProducao::find($this->linhaProducaoId);

        if (! $linha) {
            return $query;
        }

        $ids = $linha->idsCategoriasComDescendentes();

        return $query->whereHas(
            'item_pedido_pedido_id',
            fn (Builder $q) => $q->where('item_pedido_status', 'INSERIDO')
                ->whereHas('produto', fn (Builder $p) => $p->whereIn('produto_categoria_id', $ids)),
        );
    }

    /** Período preenchido substitui a janela do turno nas colunas ativas. */
    private function aplicarPeriodo(Builder $query): Builder
    {
        return $query
            ->when($this->periodoDe, fn (Builder $q, string $de) => $q->whereDate('created_at', '>=', $de))
            ->when($this->periodoAte, fn (Builder $q, string $ate) => $q->whereDate('created_at', '<=', $ate));
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

    /**
     * @param  Collection<int, Pedido>  $pedidos
     * @return Collection<int, Pedido>
     */
    private function apenasAtrasados(Collection $pedidos): Collection
    {
        return $pedidos->filter(
            fn (Pedido $pedido) => $this->urgenciaDe($pedido) === UrgenciaPedidoEnum::ATRASADO
        )->values();
    }

    private function limiteEntregue(): int
    {
        return (int) ($this->entregueExpandido
            ? config('pizzaria.pedidos.limite_entregue_expandido', 200)
            : config('pizzaria.pedidos.limite_entregue', 50));
    }
}
