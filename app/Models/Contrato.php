<?php

namespace App\Models;

use App\Enums\FormaPagamento;
use App\Enums\StatusContrato;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Contrato extends Model
{
    use SoftDeletes;

    protected $table = 'contratos';

    protected $fillable = [
        'favorecido_id',
        'plano_despesa_id',
        'descricao',
        'numero_documento',
        'valor',
        'dia_vencimento',
        'forma_pagamento',
        'data_inicio',
        'data_fim',
        'status',
        'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusContrato::class,
            'forma_pagamento' => FormaPagamento::class,
            'valor' => 'decimal:2',
            'dia_vencimento' => 'integer',
            'data_inicio' => 'date',
            'data_fim' => 'date',
        ];
    }

    /** Fornecedor (Prestador, categoria fornecedor) que presta o serviço contratado. */
    public function favorecido(): BelongsTo
    {
        return $this->belongsTo(Prestador::class, 'favorecido_id');
    }

    public function planoDespesa(): BelongsTo
    {
        return $this->belongsTo(PlanoDespesa::class, 'plano_despesa_id');
    }

    /** Lançamentos mensais já gerados a partir deste contrato. */
    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class, 'contrato_id');
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('status', StatusContrato::Ativo);
    }

    /** Contratos dentro da vigência (data_inicio..data_fim) na data informada (default hoje). */
    public function scopeVigentes(Builder $query, ?Carbon $data = null): Builder
    {
        $data ??= now();

        return $query->whereDate('data_inicio', '<=', $data)
            ->where(function (Builder $q) use ($data): void {
                $q->whereNull('data_fim')->orWhereDate('data_fim', '>=', $data);
            });
    }

    /**
     * Data de vencimento do lançamento da competência informada, a partir de
     * `dia_vencimento` — clampado ao último dia do mês em meses mais curtos
     * (ex.: dia_vencimento=31 em fevereiro vence no último dia de fevereiro).
     */
    public function vencimentoParaCompetencia(Carbon $competencia): Carbon
    {
        $dia = min($this->dia_vencimento, $competencia->daysInMonth);

        return $competencia->copy()->startOfMonth()->addDays($dia - 1);
    }

    /** Próximo vencimento a partir de hoje (usado só para exibição na tabela). */
    protected function proximoVencimento(): Attribute
    {
        return Attribute::make(
            get: function (): Carbon {
                $hoje = now()->startOfDay();
                $venceEsteMes = $this->vencimentoParaCompetencia($hoje);

                return $venceEsteMes->gte($hoje)
                    ? $venceEsteMes
                    : $this->vencimentoParaCompetencia($hoje->copy()->addMonthNoOverflow());
            },
        );
    }
}
