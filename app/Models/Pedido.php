<?php

namespace App\Models;

use App\Enums\Cozinha\PrioridadeProducao;
use App\Enums\Cozinha\StatusProducaoPedido;
use App\Enums\MotivoAprovacaoMesaEnum;
use App\Enums\MotivoCancelamentoEnum;
use App\Enums\MotivoExclusaoPedidoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\SituacaoPagamentoPedidoEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\StonePedidoStatus;
use App\Enums\TipoAtendimentoEnum;
use App\Observers\PedidoProducaoObserver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

#[ObservedBy([PedidoProducaoObserver::class])]
class Pedido extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'pedido_cliente_id',
        'pedido_sessao_mesa_id',
        'pedido_mesa_participante_id',
        'pedido_chave_idempotencia',
        'pedido_usuario_garcom_id',
        'pedido_usuario_entrega_id',
        'pedido_opcaoentrega_id',
        'pedido_venda_id',
        'pedido_descricao_pagamento',
        'pedido_observacao_pagamento',
        'pedido_endereco_entrega',
        'pedido_valor_itens',
        'pedido_valor_desconto',
        'pedido_valor_frete',
        'pedido_valor_total',
        'pedido_status',
        'pedido_motivo_cancelamento',
        'pedido_motivo_cancelamento_observacao',
        'pedido_usuario_cancelou_id',
        'pedido_motivo_exclusao',
        'pedido_exclusao_observacao',
        'pedido_usuario_excluiu_id',
        'pedido_origem',
        'pedido_aprovacao_status',
        'pedido_aprovacao_motivos',
        'pedido_aprovado_por_id',
        'pedido_aprovado_em',
        'pedido_recusa_motivo',
        'pedido_datahora_abertura',
        'pedido_datahora_preparo',
        'pedido_datahora_pronto',
        'pedido_datahora_transporte',
        'pedido_datahora_entrega',
        'pedido_datahora_finalizado',
        'pedido_datahora_cancelado',
        'pedido_producao_status',
        'pedido_prioridade_producao',
        'pedido_producao_iniciada_em',
        'pedido_producao_concluida_em',
    ];

    protected $casts = [
        'pedido_origem' => PedidoOrigemEnum::class,
        'pedido_aprovacao_status' => StatusAprovacaoPedidoEnum::class,
        'pedido_aprovacao_motivos' => AsEnumCollection::class.':'.MotivoAprovacaoMesaEnum::class,
        'pedido_aprovado_em' => 'datetime',
        'pedido_motivo_cancelamento' => MotivoCancelamentoEnum::class,
        'pedido_motivo_exclusao' => MotivoExclusaoPedidoEnum::class,
        'pedido_datahora_abertura' => 'datetime',
        'pedido_datahora_preparo' => 'datetime',
        'pedido_datahora_pronto' => 'datetime',
        'pedido_datahora_transporte' => 'datetime',
        'pedido_datahora_entrega' => 'datetime',
        'pedido_datahora_finalizado' => 'datetime',
        'pedido_datahora_cancelado' => 'datetime',
        'pedido_producao_status' => StatusProducaoPedido::class,
        'pedido_prioridade_producao' => PrioridadeProducao::class,
        'pedido_producao_iniciada_em' => 'datetime',
        'pedido_producao_concluida_em' => 'datetime',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'pedido_cliente_id')->withDefault([
            'cliente_nome' => 'Não informado',
        ]);
    }

    public function sessaoMesa()
    {
        return $this->belongsTo(SessaoMesa::class, 'pedido_sessao_mesa_id')->withDefault([
            'mesa_nome' => 'S/M',
        ]);
    }

    /** Celular que fez o pedido pelo QR da mesa. */
    public function participante()
    {
        return $this->belongsTo(MesaParticipante::class, 'pedido_mesa_participante_id');
    }

    /** Garçom que aprovou ou recusou o pedido do QR. */
    public function aprovador()
    {
        return $this->belongsTo(User::class, 'pedido_aprovado_por_id');
    }

    /** Pedido do QR parado esperando o garçom (ainda INICIADO, fora da conta e da cozinha). */
    public function aguardandoAprovacao(): bool
    {
        return $this->pedido_aprovacao_status === StatusAprovacaoPedidoEnum::PENDENTE;
    }

    public function garcom()
    {
        return $this->belongsTo(User::class, 'pedido_usuario_garcom_id')->withDefault([
            'name' => 'S/G',
        ]);
    }

    public function entregador()
    {
        return $this->belongsTo(User::class, 'pedido_usuario_entrega_id')->withDefault([
            'name' => 'S/E',
        ]);
    }

    public function usuarioCancelou()
    {
        return $this->belongsTo(User::class, 'pedido_usuario_cancelou_id');
    }

    public function usuarioExcluiu()
    {
        return $this->belongsTo(User::class, 'pedido_usuario_excluiu_id');
    }

    public function opcaoEntrega()
    {
        return $this->belongsTo(OpcoesEntregas::class, 'pedido_opcaoentrega_id')->withDefault([
            'opcaoentrega_nome' => 'S/OPE',
        ]);
    }

    public function produtos()
    {
        return $this->belongsToMany(Produto::class, 'itens_pedidos', 'item_pedido_pedido_id', 'item_pedido_produto_id')
            ->withPivot('item_pedido_quantidade', 'item_pedido_valor', 'item_pedido_observacao', 'item_pedido_sabores', 'item_pedido_status', 'item_pedido_usuario_removeu');
    }

    public function produtosInseridosPedido()
    {
        return $this->belongsToMany(Produto::class, 'itens_pedidos', 'item_pedido_pedido_id', 'item_pedido_produto_id')
            ->withPivot('id', 'item_pedido_quantidade', 'item_pedido_valor', 'item_pedido_observacao', 'item_pedido_sabores', 'item_pedido_status', 'item_pedido_usuario_removeu')
            ->wherePivot('item_pedido_status', 'INSERIDO')
            ->with('categoria');
    }

    public function item_pedido_pedido_id()
    {
        return $this->hasMany(ItensPedido::class, 'item_pedido_pedido_id');
    }

    public function vendas()
    {
        return $this->belongsTo(Venda::class, 'pedido_venda_id')->withDefault([
            'venda_id' => 'N/Venda']);
    }

    public function mov_pedido()
    {
        return $this->hasMany(MovimentacaoPedido::class, 'mov_pedido_pedido_id');
    }

    /** Forma de pagamento combinada com o cliente no atendimento — dado informativo, não uma cobrança real (ver PagamentosPedido). */
    public function pagamentosCombinados()
    {
        return $this->hasMany(PagamentosPedido::class, 'pg_pedido_pedido_id')->orderBy('pg_pedido_ordem');
    }

    /** Cobranças na maquininha ainda em aberto (aguardando ou paga em parte), da mais recente para a mais antiga. */
    public function stonePedidosPendentes()
    {
        return $this->hasMany(StonePedido::class, 'stp_pedido_id')
            ->whereIn('stp_status', [StonePedidoStatus::Aguardando->value, StonePedidoStatus::PagoParcial->value])
            ->latest('id');
    }

    /** Só pedidos combinados em forma integrada à Stone podem ser cobrados na maquininha. */
    public function temPagamentoCombinadoStone(): bool
    {
        if ($this->relationLoaded('pagamentosCombinados')) {
            return $this->pagamentosCombinados->contains(
                fn (PagamentosPedido $pagamento): bool => (bool) $pagamento->opcaoPagamento?->opcaopag_stone_integrada
            );
        }

        return $this->pagamentosCombinados()
            ->whereHas('opcaoPagamento', fn ($query) => $query->where('opcaopag_stone_integrada', true))
            ->exists();
    }

    /**
     * URL assinada (sem expiração) que o QR code do ticket/painel do
     * entregador codifica — abrir o link já é a prova de posse do QR, sem
     * precisar de token/coluna extra no banco.
     */
    public function linkScanEntrega(): string
    {
        return URL::signedRoute('entregador.scan', ['pedido' => $this->id]);
    }

    /**
     * Filtra por tipo de atendimento — a mesma regra de tipoAtendimento(), em
     * SQL: mesa tem prioridade, delivery exige a flag da opção de entrega e
     * retirada é o resto. Aceita um tipo ou vários (OU entre eles).
     *
     * @param  TipoAtendimentoEnum|array<int, TipoAtendimentoEnum>  $tipos
     */
    public function scopeTipoAtendimento(Builder $query, TipoAtendimentoEnum|array $tipos): Builder
    {
        $tipos = array_values(array_unique(Arr::wrap($tipos), SORT_REGULAR));

        if ($tipos === [] || count($tipos) === count(TipoAtendimentoEnum::cases())) {
            return $query;
        }

        return $query->where(function (Builder $grupo) use ($tipos): void {
            foreach ($tipos as $tipo) {
                $grupo->orWhere(fn (Builder $q) => match ($tipo) {
                    TipoAtendimentoEnum::MESA => $q->whereNotNull('pedido_sessao_mesa_id'),
                    TipoAtendimentoEnum::DELIVERY => $q->whereNull('pedido_sessao_mesa_id')
                        ->whereHas('opcaoEntrega', fn (Builder $o) => $o->where('opcaoentrega_requer_endereco', true)),
                    TipoAtendimentoEnum::RETIRADA => $q->whereNull('pedido_sessao_mesa_id')
                        ->where(fn (Builder $r) => $r
                            ->whereNull('pedido_opcaoentrega_id')
                            ->orWhereHas('opcaoEntrega', fn (Builder $o) => $o->where('opcaoentrega_requer_endereco', false))),
                });
            }
        });
    }

    /**
     * Pedido que entrou na operação: tudo menos o rascunho (INICIADO).
     *
     * @return Builder<self>
     */
    public function scopeReais(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('pedido_status'), '!=', StatusPedidoEnum::INICIADO->value);
    }

    /**
     * Pedido em produção ou a caminho (ABERTO a EM TRANSPORTE): os status do
     * fluxo ativo do painel, sem o rascunho e sem ENTREGUE.
     *
     * @return Builder<self>
     */
    public function scopeEmAndamento(Builder $query): Builder
    {
        $status = array_values(array_diff(StatusPedidoEnum::valoresEmAndamento(), [StatusPedidoEnum::INICIADO->value]));

        return $query->whereIn($query->qualifyColumn('pedido_status'), $status);
    }

    /**
     * Pedido com algum pagamento: venda vinculada ou item lançado numa venda.
     * Mesma regra de temPagamento(), em SQL.
     *
     * @return Builder<self>
     */
    public function scopePagos(Builder $query): Builder
    {
        return $query->where(fn (Builder $pago) => $pago
            ->whereNotNull($pago->qualifyColumn('pedido_venda_id'))
            ->orWhereHas('item_pedido_pedido_id', fn (Builder $item) => $item->whereNotNull('item_pedido_venda_id')));
    }

    /**
     * Inverso de scopePagos(): sem venda vinculada e sem item lançado numa venda.
     *
     * @return Builder<self>
     */
    public function scopeSemPagamento(Builder $query): Builder
    {
        return $query
            ->whereNull($query->qualifyColumn('pedido_venda_id'))
            ->whereDoesntHave('item_pedido_pedido_id', fn (Builder $item) => $item->whereNotNull('item_pedido_venda_id'));
    }

    /**
     * ENTREGUE sem pagamento algum — o pedido que ficou pendurado (ex.: venda
     * desfeita por estorno). É a mesma regra do guard de
     * PedidoStatusService::cancelarEntregue().
     *
     * @return Builder<self>
     */
    public function scopeNaoPagos(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('pedido_status'), StatusPedidoEnum::ENTREGUE->value)
            ->semPagamento();
    }

    /**
     * Pedido em andamento parado no status atual além do limite "atrasado" do
     * SLA (config pizzaria.pedidos.sla). Segue UrgenciaPedidoEnum: mede o tempo
     * desde a entrada no status, e sem data de entrada nunca acusa atraso.
     *
     * @return Builder<self>
     */
    public function scopeParados(Builder $query, ?Carbon $agora = null): Builder
    {
        $agora ??= Carbon::now();
        $limites = [];

        foreach ((array) config('pizzaria.pedidos.sla', []) as $valor => $faixas) {
            $status = StatusPedidoEnum::tryFrom((string) $valor);

            if ($status === null || $status === StatusPedidoEnum::INICIADO || $status->campoDataHora() === null || ! isset($faixas['atrasado'])) {
                continue;
            }

            $limites[] = [$status, $agora->copy()->subMinutes((int) $faixas['atrasado'])];
        }

        if ($limites === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $grupo) use ($limites): void {
            foreach ($limites as [$status, $limite]) {
                $grupo->orWhere(fn (Builder $q) => $q
                    ->where($q->qualifyColumn('pedido_status'), $status->value)
                    ->where($q->qualifyColumn($status->campoDataHora()), '<=', $limite));
            }
        });
    }

    /**
     * O que pede decisão de alguém: entregue sem receber ou parado além do SLA.
     *
     * @return Builder<self>
     */
    public function scopePendencias(Builder $query, ?Carbon $agora = null): Builder
    {
        return $query->where(fn (Builder $grupo) => $grupo
            ->naoPagos()
            ->orWhere(fn (Builder $parado) => $parado->parados($agora)));
    }

    /**
     * Aberto dentro do período, medido como a coluna "Abertura" da lista: a
     * data de abertura, ou a de criação quando o pedido nunca foi aberto
     * (rascunho cancelado, por exemplo). Datas inclusivas, null = sem limite.
     *
     * @return Builder<self>
     */
    public function scopeAbertoEntre(Builder $query, ?CarbonInterface $de, ?CarbonInterface $ate): Builder
    {
        $de = $de?->copy()->startOfDay();
        $ate = $ate?->copy()->endOfDay();

        $janela = fn (Builder $q, string $coluna): Builder => $q
            ->when($de, fn (Builder $b) => $b->where($b->qualifyColumn($coluna), '>=', $de))
            ->when($ate, fn (Builder $b) => $b->where($b->qualifyColumn($coluna), '<=', $ate));

        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $aberto) => $janela($aberto->whereNotNull($aberto->qualifyColumn('pedido_datahora_abertura')), 'pedido_datahora_abertura'))
            ->orWhere(fn (Builder $criado) => $janela($criado->whereNull($criado->qualifyColumn('pedido_datahora_abertura')), 'created_at')));
    }

    /**
     * Já tem algum pagamento? Venda vinculada ou item lançado numa venda — a
     * regra de scopePagos(), que o guard de cancelarEntregue() e o de exclusão
     * compartilham.
     */
    public function temPagamento(): bool
    {
        return $this->pedido_venda_id !== null
            || $this->item_pedido_pedido_id()->whereNotNull('item_pedido_venda_id')->exists();
    }

    /**
     * Pago: venda vinculada, ou FINALIZADO anterior ao vínculo (pedido_venda_id
     * só passou a ser gravado depois). Parcial: parte dos itens já foi lançada
     * numa venda. A receber: nada lançado. Rascunho e cancelado não têm o que
     * receber: null.
     *
     * Usa o atributo `tem_item_vendido` quando a consulta o traz (withExists),
     * para a lista não consultar linha a linha.
     */
    public function situacaoPagamento(): ?SituacaoPagamentoPedidoEnum
    {
        $status = $this->status();

        if ($status === null || in_array($status, [StatusPedidoEnum::INICIADO, StatusPedidoEnum::CANCELADO], true)) {
            return null;
        }

        if ($status === StatusPedidoEnum::FINALIZADO || $this->pedido_venda_id !== null) {
            return SituacaoPagamentoPedidoEnum::PAGO;
        }

        $temItemVendido = $this->getAttribute('tem_item_vendido')
            ?? $this->item_pedido_pedido_id()->whereNotNull('item_pedido_venda_id')->exists();

        return $temItemVendido ? SituacaoPagamentoPedidoEnum::PARCIAL : SituacaoPagamentoPedidoEnum::A_RECEBER;
    }

    /**
     * Link do ticket de impressão, assinado e com prazo (validade configurada
     * na Empresa). Quem imprime sem sessão precisa dele; telas autenticadas
     * abrem o ticket pela permissão view:pedido (ver PDFController::pedidoPDF).
     */
    public function linkImpressao(): string
    {
        return self::linkImpressaoDoId($this->id);
    }

    /** Mesmo link de linkImpressao() quando só se tem o id (evita reler o pedido). */
    public static function linkImpressaoDoId(int $id): string
    {
        return URL::temporarySignedRoute(
            'pedido.imprimir',
            now()->addHours(Empresa::validadeLinkTicketHoras()),
            ['id' => $id],
        );
    }

    /** Itens de produção da cozinha (todas as etapas, unidades e gerações). */
    public function producaoItens()
    {
        return $this->hasMany(ProducaoItem::class, 'pedido_id');
    }

    /** Timeline de transições de status — ver HistoricoStatusPedido. */
    public function historicoStatus()
    {
        // Duas transições no mesmo segundo (created_at é timestamp sem
        // microssegundos) empatam na ordenação — id como desempate estável.
        return $this->hasMany(HistoricoStatusPedido::class, 'hsp_pedido_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * O pedido sai para entrega (delivery), em oposição a mesa/balcão?
     *
     * A fonte é a flag `opcaoentrega_requer_endereco` da opção de entrega — não
     * o `id === 3` hardcoded que a view legada de pedidos abertos usa. Decide a
     * bifurcação do fluxo em PRONTO (ver StatusPedidoEnum::proximo()).
     */
    public function exigeEntrega(): bool
    {
        return (bool) $this->opcaoEntrega?->opcaoentrega_requer_endereco;
    }

    /**
     * O estágio EM TRANSPORTE se aplica a este pedido? Combina a natureza do
     * pedido com a config que permite desligar o estágio para a operação toda.
     */
    public function usaEstagioTransporte(): bool
    {
        return $this->exigeEntrega() && (bool) config('pizzaria.pedidos.usa_estagio_transporte', true);
    }

    /** Status atual como enum. Null só se o banco tiver valor fora do enum. */
    public function status(): ?StatusPedidoEnum
    {
        return StatusPedidoEnum::tryFrom((string) $this->pedido_status);
    }

    /**
     * Como este pedido é atendido — não é uma coluna nova, é leitura de
     * conveniência pra badge/filtro. Mesa tem prioridade (uma sessão de mesa
     * nunca é delivery, mesmo que a opção de entrega tenha endereço).
     */
    public function tipoAtendimento(): TipoAtendimentoEnum
    {
        return match (true) {
            $this->pedido_sessao_mesa_id !== null => TipoAtendimentoEnum::MESA,
            $this->exigeEntrega() => TipoAtendimentoEnum::DELIVERY,
            default => TipoAtendimentoEnum::RETIRADA,
        };
    }
}
