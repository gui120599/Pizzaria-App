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

    /** Rateio do título entre planos de despesa (1 lançamento -> N despesas). */
    public function despesas(): HasMany
    {
        return $this->hasMany(LancamentoDespesa::class, 'lancamento_id');
    }

    public function scopePagar(Builder $query): Builder
    {
        return $query->where('tipo', TipoLancamento::Pagar);
    }

    public function scopeReceber(Builder $query): Builder
    {
        return $query->where('tipo', TipoLancamento::Receber);
    }

    public function scopePendentes(Builder $query): Builder
    {
        return $query->where('status', StatusLancamento::Pendente);
    }

    /** Pendentes com vencimento anterior a hoje ("vencido" é estado derivado). */
    public function scopeVencidos(Builder $query): Builder
    {
        return $query->where('status', StatusLancamento::Pendente)
            ->whereDate('vencimento', '<', now()->toDateString());
    }

    protected function estaVencido(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === StatusLancamento::Pendente
                && $this->vencimento !== null
                && $this->vencimento->lt(now()->startOfDay()),
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

    /** Nome do favorecido (fornecedor no pagar, cliente no receber) para exibição. */
    protected function nomeFavorecido(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->tipo === TipoLancamento::Pagar
                ? $this->favorecido?->nome_exibicao
                : $this->cliente?->cliente_nome,
        );
    }

    public function marcarComoPago(?Carbon $data = null, ?FormaPagamento $forma = null): bool
    {
        $this->status = StatusLancamento::Pago;
        $this->data_pagamento = $data ?? now();

        if ($forma !== null) {
            $this->forma_pagamento = $forma;
        }

        return $this->save();
    }

    /**
     * Reverte a baixa: volta para Pendente e limpa data/forma de pagamento.
     * Único jeito de corrigir um lançamento Pago, já que ele fica travado para
     * edição/exclusão direta (ver LancamentoResource::canEdit/canDelete).
     */
    public function estornarPagamento(): bool
    {
        $this->status = StatusLancamento::Pendente;
        $this->data_pagamento = null;
        $this->forma_pagamento = null;

        return $this->save();
    }
}
