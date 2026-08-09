<?php

namespace App\Models;

use App\Enums\Comportamento;
use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Lancamento extends Model
{
    protected $table = 'lancamentos';

    protected $fillable = [
        'tipo',
        'compra_id',
        'contrato_id',
        'prazo_pagamento_id',
        'parcela_numero',
        'parcela_total',
        'competencia',
        'plano_despesa_id',
        'plano_receita_id',
        'comportamento',
        'descricao',
        'favorecido_id',
        'cliente_id',
        'numero_documento',
        'valor',
        'vencimento',
        'data_pagamento',
        'status',
        'forma_pagamento',
        'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoLancamento::class,
            'status' => StatusLancamento::class,
            'forma_pagamento' => FormaPagamento::class,
            'comportamento' => Comportamento::class,
            'valor' => 'decimal:2',
            'vencimento' => 'date',
            'data_pagamento' => 'date',
            'competencia' => 'date',
            'parcela_numero' => 'integer',
            'parcela_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Snapshot do comportamento: preserva a classificação vigente no momento do
        // lançamento (protege a DRE histórica se a conta for reclassificada depois).
        static::saving(function (Lancamento $lancamento): void {
            // Receita não tem comportamento (fixo/variável não se aplica).
            if ($lancamento->tipo === TipoLancamento::Receber) {
                $lancamento->comportamento = null;

                return;
            }

            // Despesa: copia do plano só se ainda não houver snapshot definido.
            if ($lancamento->tipo === TipoLancamento::Pagar
                && $lancamento->plano_despesa_id
                && $lancamento->comportamento === null) {
                $plano = $lancamento->planoDespesa()->first();

                if ($plano) {
                    $lancamento->comportamento = $plano->comportamento;
                }
            }
        });

        // Se o valor total do título mudar depois de já ter pagamentos registrados,
        // o status (Pendente/Parcial/Pago) precisa ser recalculado contra o novo total.
        static::saved(function (Lancamento $lancamento): void {
            if ($lancamento->wasChanged('valor') && $lancamento->pagamentos()->exists()) {
                $lancamento->recalcularStatus();
            }
        });
    }

    public function planoDespesa(): BelongsTo
    {
        return $this->belongsTo(PlanoDespesa::class, 'plano_despesa_id');
    }

    public function planoReceita(): BelongsTo
    {
        return $this->belongsTo(PlanoReceita::class, 'plano_receita_id');
    }

    /** Fornecedor (Prestador, categoria fornecedor) — favorecido nos lançamentos a pagar. */
    public function favorecido(): BelongsTo
    {
        return $this->belongsTo(Prestador::class, 'favorecido_id');
    }

    /** Cliente — favorecido nos lançamentos a receber. */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    /** Compra que originou este título a pagar (quando gerado por confirmação de compra). */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra_id');
    }

    /** Contrato que gerou este título automaticamente (ver ContratoService::gerarLancamentoMensal). */
    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class, 'contrato_id');
    }

    /** Prazo de pagamento aplicado na confirmação da compra que gerou este título. */
    public function prazoPagamento(): BelongsTo
    {
        return $this->belongsTo(PrazoPagamento::class, 'prazo_pagamento_id');
    }

    /** Rateio do título entre planos de despesa (1 lançamento -> N despesas). */
    public function despesas(): HasMany
    {
        return $this->hasMany(LancamentoDespesa::class, 'lancamento_id');
    }

    /** Pagamentos (parciais ou totais) do título — 1 lançamento -> N pagamentos. */
    public function pagamentos(): HasMany
    {
        return $this->hasMany(LancamentoPagamento::class, 'lancamento_id')
            ->orderBy('data_pagamento')
            ->orderBy('id');
    }

    public function scopePagar(Builder $query): Builder
    {
        return $query->where('tipo', TipoLancamento::Pagar);
    }

    public function scopeReceber(Builder $query): Builder
    {
        return $query->where('tipo', TipoLancamento::Receber);
    }

    /** Pendente ou Parcial: ainda resta algo a pagar/receber. */
    public function scopePendentes(Builder $query): Builder
    {
        return $query->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial]);
    }

    /** Pendentes/parciais com vencimento anterior a hoje ("vencido" é estado derivado). */
    public function scopeVencidos(Builder $query): Builder
    {
        return $query->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
            ->whereDate('vencimento', '<', now()->toDateString());
    }

    protected function estaVencido(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => in_array($this->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true)
                && $this->vencimento !== null
                && $this->vencimento->lt(now()->startOfDay()),
        );
    }

    /** Soma dos pagamentos já registrados. Usa withSum('pagamentos','valor') se disponível (evita N+1). */
    protected function valorPago(): Attribute
    {
        return Attribute::make(
            get: fn (): float => (float) ($this->pagamentos_sum_valor ?? $this->pagamentos()->sum('valor')),
        );
    }

    /** Quanto ainda falta pagar/receber (nunca negativo). */
    protected function valorRestante(): Attribute
    {
        return Attribute::make(
            get: fn (): float => max(0.0, round((float) $this->valor - $this->valorPago, 2)),
        );
    }

    protected function estaQuitado(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->valorPago + 0.01 >= (float) $this->valor,
        );
    }

    /** Conta do plano correspondente ao tipo (despesa ou receita). */
    protected function plano(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->tipo === TipoLancamento::Pagar
                ? $this->planoDespesa
                : $this->planoReceita,
        );
    }

    /** "Parcela N/T" quando o título faz parte de um lote de parcelas de uma compra. */
    protected function parcelaLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => ($this->parcela_total !== null && $this->parcela_total > 1)
                ? "Parcela {$this->parcela_numero}/{$this->parcela_total}"
                : null,
        );
    }

    /** Nome do favorecido (fornecedor no pagar, cliente no receber) para exibição. */
    protected function nomeFavorecido(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->tipo === TipoLancamento::Pagar
                ? $this->favorecido?->nome_exibicao
                : $this->cliente?->cliente_nome,
        );
    }

    /**
     * Registra um pagamento (parcial ou total) do título. Dispara o recálculo do
     * status (Pendente/Parcial/Pago) via evento do LancamentoPagamento — ver
     * Lancamento::recalcularStatus().
     */
    public function registrarPagamento(
        float $valor,
        ?Carbon $data = null,
        ?FormaPagamento $forma = null,
        ?string $observacoes = null,
    ): LancamentoPagamento {
        return $this->pagamentos()->create([
            'valor' => $valor,
            'data_pagamento' => $data ?? now(),
            'forma_pagamento' => $forma,
            'observacoes' => $observacoes,
        ]);
    }

    /**
     * Atalho pra "Dar baixa" rápida: registra um pagamento pelo valor informado
     * (por padrão, o valor restante inteiro — quita o título de uma vez).
     */
    public function marcarComoPago(?Carbon $data = null, ?FormaPagamento $forma = null, ?float $valor = null): bool
    {
        $valor ??= $this->valorRestante > 0 ? $this->valorRestante : (float) $this->valor;

        return $this->registrarPagamento($valor, $data, $forma) !== null;
    }

    /**
     * Apaga todos os pagamentos do título e volta pra Pendente. Único jeito de
     * corrigir um lançamento Pago, já que ele fica travado para edição/exclusão
     * direta (ver LancamentoResource::canEdit/canDelete).
     */
    public function estornarPagamento(): bool
    {
        $this->pagamentos()->delete();

        return $this->recalcularStatus();
    }

    /**
     * Deriva o status (Pendente/Parcial/Pago) a partir da soma dos pagamentos,
     * e sincroniza data_pagamento/forma_pagamento com o último pagamento registrado
     * (mantidos por compatibilidade com exibição/relatórios que leem direto do
     * cabeçalho). Cancelado é estado manual — não é sobrescrito por pagamentos.
     */
    public function recalcularStatus(): bool
    {
        if ($this->status === StatusLancamento::Cancelado) {
            return true;
        }

        $pago = round((float) $this->pagamentos()->sum('valor'), 2);
        $total = round((float) $this->valor, 2);
        // reorder() limpa o orderBy ASC já definido em pagamentos() antes de aplicar o DESC.
        $ultimo = $this->pagamentos()->reorder()->orderByDesc('data_pagamento')->orderByDesc('id')->first();

        $this->status = match (true) {
            $pago <= 0.0 => StatusLancamento::Pendente,
            $pago + 0.01 < $total => StatusLancamento::Parcial,
            default => StatusLancamento::Pago,
        };
        $this->data_pagamento = $ultimo?->data_pagamento;
        $this->forma_pagamento = $ultimo?->forma_pagamento;

        return $this->save();
    }
}
