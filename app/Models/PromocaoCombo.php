<?php

namespace App\Models;

use App\Concerns\TemVigenciaRecorrente;
use App\Enums\TipoBeneficioComboEnum;
use Carbon\CarbonInterface;
use Database\Factories\PromocaoComboFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Promoção Combo: N itens participantes juntos por um preço fechado
 * (ex.: 2 pizzas grandes por R$ 87,99). Quem identifica e aplica é o
 * PromocaoComboService, sobre as linhas já precificadas do pedido.
 */
class PromocaoCombo extends Model
{
    /** @use HasFactory<PromocaoComboFactory> */
    use HasFactory;

    use SoftDeletes;
    use TemVigenciaRecorrente;

    protected $table = 'promocoes_combo';

    protected $fillable = [
        'promocombo_nome',
        'promocombo_descricao',
        'promocombo_ativa',
        'promocombo_tipo_beneficio',
        'promocombo_quantidade',
        'promocombo_preco',
        'promocombo_recorrente',
        'promocombo_inicio',
        'promocombo_fim',
        'promocombo_dias_semana',
        'promocombo_hora_inicio',
        'promocombo_hora_fim',
        'promocombo_data_final_recorrencia',
        'promocombo_ultimo_reset_em',
        'promocombo_opcoes_entrega',
        'promocombo_aplica_fracionado',
        'promocombo_cumula_relampago',
        'promocombo_cumula_promo_adicional',
        'promocombo_sugerir',
        'promocombo_limite_por_pedido',
        'promocombo_prioridade',
    ];

    protected function casts(): array
    {
        return [
            'promocombo_ativa' => 'boolean',
            'promocombo_tipo_beneficio' => TipoBeneficioComboEnum::class,
            'promocombo_quantidade' => 'integer',
            'promocombo_preco' => 'decimal:2',
            'promocombo_recorrente' => 'boolean',
            'promocombo_inicio' => 'datetime',
            'promocombo_fim' => 'datetime',
            'promocombo_dias_semana' => 'array',
            'promocombo_hora_inicio' => 'datetime:H:i:s',
            'promocombo_hora_fim' => 'datetime:H:i:s',
            'promocombo_data_final_recorrencia' => 'date',
            'promocombo_ultimo_reset_em' => 'datetime',
            'promocombo_opcoes_entrega' => 'array',
            'promocombo_aplica_fracionado' => 'boolean',
            'promocombo_cumula_relampago' => 'boolean',
            'promocombo_cumula_promo_adicional' => 'boolean',
            'promocombo_sugerir' => 'boolean',
            'promocombo_limite_por_pedido' => 'integer',
            'promocombo_prioridade' => 'integer',
        ];
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(PromocaoComboParticipante::class, 'pcp_promocao_combo_id');
    }

    public function aplicacoes(): HasMany
    {
        return $this->hasMany(PedidoComboAplicacao::class, 'pca_promocao_combo_id');
    }

    /**
     * Ativa e dentro da janela. A recorrência é refinada em PHP, como na
     * promoção relâmpago (são poucas promoções cadastradas).
     */
    public function scopeVigente(Builder $query, ?CarbonInterface $momento = null): Builder
    {
        $momento ??= now();

        $ids = (clone $query)->where('promocombo_ativa', true)
            ->get()
            ->filter(fn (self $promocao): bool => $promocao->vigente($momento))
            ->pluck('id');

        return $query->whereIn('promocoes_combo.id', $ids);
    }

    public function restringeEntrega(): bool
    {
        return array_filter((array) $this->promocombo_opcoes_entrega) !== [];
    }

    /**
     * Sem restrição vale em tudo, inclusive mesa. Com restrição, a mesa fica
     * fora e a opção precisa estar na lista. Opção ainda não escolhida (null:
     * rascunho do garçom, sacola do cardápio) passa: o combo é reaplicado
     * quando a opção é gravada, e cai sozinho se ela não for aceita.
     */
    public function entregaPermitida(?int $opcaoEntregaId, bool $ehMesa = false): bool
    {
        if (! $this->restringeEntrega()) {
            return true;
        }

        if ($ehMesa) {
            return false;
        }

        return $opcaoEntregaId === null
            || in_array($opcaoEntregaId, array_map('intval', (array) $this->promocombo_opcoes_entrega), true);
    }

    // ── Implementação dos acessores de TemVigenciaRecorrente ────────────────

    protected function vigAtiva(): bool
    {
        return (bool) $this->promocombo_ativa;
    }

    protected function vigRecorrente(): bool
    {
        return (bool) $this->promocombo_recorrente;
    }

    protected function vigInicio(): ?CarbonInterface
    {
        return $this->promocombo_inicio;
    }

    protected function vigFim(): ?CarbonInterface
    {
        return $this->promocombo_fim;
    }

    protected function vigDiasSemana(): array
    {
        return $this->promocombo_dias_semana ?? [];
    }

    protected function vigHoraInicio(): ?CarbonInterface
    {
        return $this->promocombo_hora_inicio;
    }

    protected function vigHoraFim(): ?CarbonInterface
    {
        return $this->promocombo_hora_fim;
    }

    protected function vigDataFinalRecorrencia(): ?CarbonInterface
    {
        return $this->promocombo_data_final_recorrencia;
    }

    protected function vigUltimoResetEm(): ?CarbonInterface
    {
        return $this->promocombo_ultimo_reset_em;
    }

    /** Sem quantidade limitada por enquanto (Fase 4 do plano). */
    protected function vigEsgotada(): bool
    {
        return false;
    }
}
