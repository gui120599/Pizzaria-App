<?php

namespace App\Models;

use App\Concerns\TemVigenciaRecorrente;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Campanha "leve outro produto por +R$X" (ex.: Dia do Cliente). Uma campanha
 * tem N regras (PromocaoAdicionalRegra), cada uma com seu próprio par
 * produto-gatilho → produto-oferta → valor adicional — ver
 * PromocaoAdicionalRegra para onde vivem preço e saldo.
 */
class PromocaoAdicional extends Model
{
    use HasFactory;
    use SoftDeletes;
    use TemVigenciaRecorrente;

    protected $table = 'promocoes_adicionais';

    protected $fillable = [
        'promoad_nome',
        'promoad_descricao',
        'promoad_ativa',
        'promoad_recorrente',
        'promoad_inicio',
        'promoad_fim',
        'promoad_dias_semana',
        'promoad_hora_inicio',
        'promoad_hora_fim',
        'promoad_data_final_recorrencia',
        'promoad_ultimo_reset_em',
        'promoad_aplica_fracionado',
        'promoad_ordem',
    ];

    protected function casts(): array
    {
        return [
            'promoad_ativa' => 'boolean',
            'promoad_recorrente' => 'boolean',
            'promoad_aplica_fracionado' => 'boolean',
            'promoad_inicio' => 'datetime',
            'promoad_fim' => 'datetime',
            'promoad_dias_semana' => 'array',
            'promoad_hora_inicio' => 'datetime:H:i:s',
            'promoad_hora_fim' => 'datetime:H:i:s',
            'promoad_data_final_recorrencia' => 'date',
            'promoad_ultimo_reset_em' => 'datetime',
        ];
    }

    public function regras(): HasMany
    {
        return $this->hasMany(PromocaoAdicionalRegra::class, 'par_promocao_id');
    }

    public function opcoesPagamento(): BelongsToMany
    {
        return $this->belongsToMany(
            OpcoesPagamento::class,
            'promocao_adicional_opcoes_pagamento',
            'promoad_id',
            'opcaopag_id',
        );
    }

    /**
     * Sem restrição cadastrada (relação vazia) = todas as formas valem. Com
     * restrição, $opcaoPagamentoId precisa estar entre as permitidas — null
     * (forma ainda não escolhida) nunca passa quando há restrição.
     */
    public function pagamentoPermitido(?int $opcaoPagamentoId): bool
    {
        if ($this->opcoesPagamento->isEmpty()) {
            return true;
        }

        return $opcaoPagamentoId !== null && $this->opcoesPagamento->contains('id', $opcaoPagamentoId);
    }

    /**
     * Ativa e dentro da janela de vigência. Mesmo padrão de
     * PromocaoRelampago::scopeVigente() — recorrência não é trivial em SQL
     * portável, então filtra grosso no banco e refina em PHP via vigente().
     */
    public function scopeVigente(Builder $query, ?CarbonInterface $momento = null): Builder
    {
        $momento ??= now();

        $ids = (clone $query)->where('promoad_ativa', true)
            ->get()
            ->filter(fn (self $promocao) => $promocao->vigente($momento))
            ->pluck('id');

        return $query->whereIn('promocoes_adicionais.id', $ids);
    }

    // ── Implementação dos acessores de TemVigenciaRecorrente ────────────────

    protected function vigAtiva(): bool
    {
        return (bool) $this->promoad_ativa;
    }

    protected function vigRecorrente(): bool
    {
        return (bool) $this->promoad_recorrente;
    }

    protected function vigInicio(): ?CarbonInterface
    {
        return $this->promoad_inicio;
    }

    protected function vigFim(): ?CarbonInterface
    {
        return $this->promoad_fim;
    }

    protected function vigDiasSemana(): array
    {
        return $this->promoad_dias_semana ?? [];
    }

    protected function vigHoraInicio(): ?CarbonInterface
    {
        return $this->promoad_hora_inicio;
    }

    protected function vigHoraFim(): ?CarbonInterface
    {
        return $this->promoad_hora_fim;
    }

    protected function vigDataFinalRecorrencia(): ?CarbonInterface
    {
        return $this->promoad_data_final_recorrencia;
    }

    protected function vigUltimoResetEm(): ?CarbonInterface
    {
        return $this->promoad_ultimo_reset_em;
    }

    /**
     * A campanha não tem pool próprio — o saldo vive por regra
     * (PromocaoAdicionalRegra::esgotada()). O status da campanha nunca é
     * "Esgotada"; quem decide se uma oferta específica pode ser aceita é
     * PromocaoAdicionalService, checando a regra.
     */
    protected function vigEsgotada(): bool
    {
        return false;
    }
}
