<?php

namespace App\Models;

use App\Enums\Cozinha\PrioridadeProducao;
use App\Enums\Cozinha\StatusProducaoPedido;
use App\Enums\MotivoAprovacaoMesaEnum;
use App\Enums\MotivoCancelamentoEnum;
use App\Enums\MotivoExclusaoPedidoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\TipoAtendimentoEnum;
use App\Observers\PedidoProducaoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
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
