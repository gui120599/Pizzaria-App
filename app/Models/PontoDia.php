<?php

namespace App\Models;

use App\Enums\Ponto\SituacaoDia;
use App\Services\Ponto\ApuracaoService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apuração de um colaborador num dia operacional. Derivada: gravada só pelo
 * ApuracaoService (upsert) e recalculável enquanto o período estiver aberto.
 */
class PontoDia extends Model
{
    use HasFactory;

    protected $table = 'ponto_dias';

    protected $fillable = [
        'colaborador_id',
        'data_referencia',
        'ponto_periodo_id',
        'situacao',
        'minutos_previstos',
        'minutos_trabalhados',
        'minutos_intervalo',
        'minutos_noturnos',
        'minutos_acrescimo_noturno',
        'minutos_extra_50',
        'minutos_extra_100',
        'minutos_atraso',
        'minutos_falta',
        'minutos_abonados',
        'entregas_qtd',
        'inconsistencias',
        'apurado_em',
    ];

    protected function casts(): array
    {
        return [
            'data_referencia' => 'date',
            'situacao' => SituacaoDia::class,
            'minutos_previstos' => 'integer',
            'minutos_trabalhados' => 'integer',
            'minutos_intervalo' => 'integer',
            'minutos_noturnos' => 'integer',
            'minutos_acrescimo_noturno' => 'integer',
            'minutos_extra_50' => 'integer',
            'minutos_extra_100' => 'integer',
            'minutos_atraso' => 'integer',
            'minutos_falta' => 'integer',
            'minutos_abonados' => 'integer',
            'entregas_qtd' => 'integer',
            'inconsistencias' => 'array',
            'apurado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id')->withTrashed();
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PontoPeriodo::class, 'ponto_periodo_id');
    }

    public function temInconsistencias(): bool
    {
        return ! empty($this->inconsistencias);
    }

    /** @return list<string> inconsistências do dia em português, para tela, impressão e CSV */
    public function rotulosInconsistencias(): array
    {
        return array_map(self::rotuloInconsistencia(...), array_values($this->inconsistencias ?? []));
    }

    public static function rotuloInconsistencia(string $codigo): string
    {
        return match ($codigo) {
            ApuracaoService::MARCACAO_FALTANTE => 'Marcação faltante',
            ApuracaoService::INTERVALO_INSUFICIENTE => 'Intervalo insuficiente',
            ApuracaoService::JORNADA_EXCEDIDA => 'Jornada acima de 10 h',
            ApuracaoService::FORA_DA_CERCA => 'Fora da loja',
            'marcacao_em_periodo_fechado' => 'Marcação em período fechado',
            default => ucfirst(str_replace('_', ' ', $codigo)),
        };
    }
}
