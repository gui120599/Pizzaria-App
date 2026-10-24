<?php

namespace App\Models;

use App\Enums\MotivoCancelamentoEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venda extends Model
{
    use HasFactory;

    protected $table = 'vendas';

    protected $fillable = [
        'venda_id_nfe',
        'venda_sessao_caixa_id',
        'venda_cliente_id',
        'venda_valor_base_calculo',
        'venda_valor_icms',
        'venda_valor_pis',
        'venda_valor_cofins',
        'venda_valor_frete',
        'venda_valor_seguro',
        'venda_valor_itens',
        'venda_valor_acrescimo',
        'venda_valor_taxa_servico',
        'venda_taxa_servico_removida',
        'venda_imposto_nfe_percentual',
        'venda_valor_desconto',
        'venda_desconto_percentual',
        'venda_valor_total',
        'venda_valor_pago',
        'venda_valor_troco',
        'venda_status',
        'venda_motivo_cancelamento',
        'venda_usuario_cancelou_id',
        'venda_status_nfe',
        'venda_datahora_iniciada',
        'venda_datahora_finalizada',
    ];

    protected $casts = [
        'venda_valor_base_calculo' => 'decimal:2',
        'venda_valor_icms' => 'decimal:2',
        'venda_valor_pis' => 'decimal:2',
        'venda_valor_cofins' => 'decimal:2',
        'venda_valor_frete' => 'decimal:2',
        'venda_valor_seguro' => 'decimal:2',
        'venda_valor_itens' => 'decimal:2',
        'venda_valor_acrescimo' => 'decimal:2',
        'venda_valor_taxa_servico' => 'decimal:2',
        'venda_taxa_servico_removida' => 'boolean',
        'venda_imposto_nfe_percentual' => 'decimal:2',
        'venda_valor_desconto' => 'decimal:2',
        'venda_desconto_percentual' => 'decimal:2',
        'venda_valor_total' => 'decimal:2',
        'venda_valor_pago' => 'decimal:2',
        'venda_valor_troco' => 'decimal:2',
        'venda_motivo_cancelamento' => MotivoCancelamentoEnum::class,
        'venda_datahora_iniciada' => 'datetime',
        'venda_datahora_finalizada' => 'datetime',
    ];

    /**
     * Quando a NFC-e é autorizada (webhook, polling ou emissão), guarda o
     * percentual de imposto da NFC-e vigente — os relatórios de fechamento de
     * caixa e de taxa por garçom descontam por esse retrato. Com a empresa sem
     * percentual (0) não grava: fica nulo e os relatórios usam o percentual
     * que vier a ser cadastrado.
     */
    protected static function booted(): void
    {
        static::saving(function (Venda $venda): void {
            if ($venda->isDirty('venda_status_nfe')
                && $venda->venda_status_nfe === NfEmissao::STATUS_AUTORIZADA
                && $venda->venda_imposto_nfe_percentual === null) {
                $percentual = (float) (Empresa::query()->value('empresa_percentual_imposto_nfe') ?? 0);
                $venda->venda_imposto_nfe_percentual = $percentual > 0 ? $percentual : null;
            }
        });
    }

    public function sessaoCaixa()
    {
        return $this->belongsTo(SessaoCaixa::class, 'venda_sessao_caixa_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'venda_cliente_id');
    }

    public function usuarioCancelou()
    {
        return $this->belongsTo(User::class, 'venda_usuario_cancelou_id');
    }

    public function itensVenda()
    {
        return $this->hasMany(ItensVenda::class, 'item_venda_venda_id', 'id');
    }

    /**
     * Tem algum item que é venda de fato? Cortesia fica fora da NFC-e; venda só
     * de cortesia não tem o que emitir.
     */
    public function temItemFiscal(): bool
    {
        // Só é "sem item fiscal" quando há cortesia e nada cobrado (venda sem item algum não é caso nosso).
        return ! $this->itensVenda()->where('item_venda_cortesia', true)->exists()
            || $this->itensVenda()->where('item_venda_cortesia', false)->exists();
    }

    public function pagamentos()
    {
        return $this->hasMany(PagamentosVenda::class, 'pg_venda_venda_id');
    }

    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'pedido_venda_id', 'id');
    }

    public function movimentacoesSessaoCaixa()
    {
        return $this->hasMany(MovimentacoesSessaoCaixa::class, 'mov_venda_id', 'id');
    }

    /** Títulos a receber (fiado/saldo em aberto) originados desta venda. */
    public function lancamentos()
    {
        return $this->hasMany(Lancamento::class, 'venda_id');
    }

    public function alteracoes()
    {
        return $this->hasMany(VendaAlteracao::class, 'venda_id');
    }

    /** Alteração completa em andamento (venda reaberta no PDV), se houver. */
    public function alteracaoAberta()
    {
        return $this->hasOne(VendaAlteracao::class, 'venda_id')->where('status', VendaAlteracao::ABERTA)->latestOfMany();
    }

    public function scopeEmAlteracao(Builder $query): Builder
    {
        return $query->where('venda_status', 'INICIADA')->whereHas('alteracoes', fn (Builder $a) => $a->where('status', VendaAlteracao::ABERTA));
    }

    /** Correções de lançamento feitas depois de finalizada (Relatório de Sessão de Caixa). */
    public function correcoesPagamento()
    {
        return $this->hasMany(CorrecaoPagamento::class, 'venda_id');
    }

    /** Números de NF reservados para esta venda (normalmente um; mais de um só se a nota anterior foi cancelada). */
    public function nfEmissoes()
    {
        return $this->hasMany(NfEmissao::class, 'venda_id');
    }

    /** Situações da NFC-e usadas nas abas e no filtro da tabela de vendas (ver scopeSituacaoNfe). */
    public const SITUACOES_NFE = [
        'pendente' => 'Pendente de envio',
        'falha' => 'Com falha',
        'andamento' => 'Em andamento',
        'autorizada' => 'Autorizada',
        'cancelada' => 'Cancelada',
        'sem_nota' => 'Sem NFC-e (nunca emitida)',
        'sem_cpf' => 'Emitida sem CPF do cliente',
    ];

    /**
     * Filtra pela situação da NFC-e. "Pendente" = a forma de pagamento exige
     * envio e a venda nunca teve tentativa, só considerando vendas a partir da
     * 1ª emissão registrada em nf_emissoes (o controle de envio por forma de
     * pagamento é dessa época; antes, o envio era decidido caso a caso).
     * "Falha" inclui a tentativa que ficou em FalhaEnvio/Reservado, que não
     * grava status na venda.
     *
     * @param  Builder<Venda>  $query
     * @return Builder<Venda>
     */
    public function scopeSituacaoNfe(Builder $query, string $situacao): Builder
    {
        $semStatus = fn (Builder $q): Builder => $q->where(fn (Builder $s) => $s->whereNull('venda_status_nfe')->orWhere('venda_status_nfe', ''));
        $exigeEnvio = fn (Builder $q): Builder => $q->whereHas('pagamentos.opcaoPagamento', fn (Builder $o) => $o->withTrashed()->where('opcaopag_envio_automatico_nfe', true));

        return match ($situacao) {
            'pendente' => $query->where('venda_status', 'FINALIZADA')
                ->where($semStatus)
                ->whereDoesntHave('nfEmissoes')
                ->where($exigeEnvio)
                ->whereRaw('vendas.venda_datahora_finalizada >= (select min(ne.created_at) from nf_emissoes ne)'),
            'falha' => $query->where(fn (Builder $q) => $q
                ->whereIn('venda_status_nfe', ['Error', 'IssueFailed', 'Failed'])
                ->orWhere(fn (Builder $e) => $semStatus($e)->whereHas('nfEmissoes', fn (Builder $n) => $n->whereIn('status', [NfEmissao::STATUS_FALHA_ENVIO, NfEmissao::STATUS_RESERVADO])))),
            'andamento' => $query->whereIn('venda_status_nfe', ['Processing', 'Created', 'CancelamentoSolicitado']),
            'autorizada' => $query->where('venda_status_nfe', NfEmissao::STATUS_AUTORIZADA),
            'cancelada' => $query->where('venda_status_nfe', 'Cancelled'),
            'sem_nota' => $query->where($semStatus)->whereDoesntHave('nfEmissoes'),
            'sem_cpf' => $query->whereHas('nfEmissoes', fn (Builder $n) => $n->whereNotNull('comprador_motivo_omissao')),
            default => $query,
        };
    }

    /** Venda com saldo a prazo: título a receber ou pago abaixo do total. */
    public function scopeComFiado(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereHas('lancamentos')
            ->orWhereColumn('venda_valor_pago', '<', 'venda_valor_total'));
    }

    public function scopeComCortesia(Builder $query): Builder
    {
        return $query->whereHas('itensVenda', fn (Builder $itens) => $itens->where('item_venda_cortesia', true));
    }

    public function scopeComDesconto(Builder $query): Builder
    {
        return $query->where('venda_valor_desconto', '>', 0);
    }

    public function scopeComTaxaServico(Builder $query): Builder
    {
        return $query->where('venda_valor_taxa_servico', '>', 0);
    }

    /** @param  array<int, int|string>  $opcaoIds */
    public function scopeComFormaPagamento(Builder $query, array $opcaoIds): Builder
    {
        return $query->whereHas('pagamentos', fn (Builder $p) => $p->whereIn('pg_venda_opcaopagamento_id', $opcaoIds));
    }

    public function scopeDoCaixa(Builder $query, int $caixaId): Builder
    {
        return $query->whereHas('sessaoCaixa', fn (Builder $s) => $s->where('sessaocaixa_caixa_id', $caixaId));
    }

    public function scopeDoOperador(Builder $query, int $userId): Builder
    {
        return $query->whereHas('sessaoCaixa', fn (Builder $s) => $s->where('sessaocaixa_user_id', $userId));
    }

    /** Status da NFC-e para exibir: o da venda; sem ele, o da tentativa de emissão (FalhaEnvio, Reservado). */
    public function statusNfeExibicao(): ?string
    {
        return filled($this->venda_status_nfe) ? $this->venda_status_nfe : $this->nfEmissaoAtual?->status;
    }

    public function nfEmissaoAtual()
    {
        return $this->hasOne(NfEmissao::class, 'venda_id')->latestOfMany();
    }

    /**
     * Regra de envio automático: basta um pagamento numa forma marcada com
     * opcaopag_envio_automatico_nfe (ex.: Pix/Dinheiro) para a venda ir à
     * NFe.io. Venda paga só em formas com NF emitida fora do sistema (ex.:
     * cartão na maquininha Stone) não é enviada.
     */
    public function exigeEnvioNfe(): bool
    {
        return $this->pagamentos()
            ->whereHas('opcaoPagamento', fn ($query) => $query->withTrashed()->where('opcaopag_envio_automatico_nfe', true))
            ->exists();
    }
}
