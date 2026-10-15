<?php

namespace App\Models;

use App\Enums\Ponto\ModalidadeRemuneracao;
use App\Models\Concerns\TemHistoricoPonto;
use App\Models\Concerns\TemVigencia;
use App\Observers\PontoAuditoriaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Remuneração do colaborador numa vigência. No máximo uma vigência aberta
 * por colaborador (validado no Service).
 */
#[ObservedBy([PontoAuditoriaObserver::class])]
class ColaboradorRemuneracao extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoPonto, TemVigencia;

    protected $table = 'colaborador_remuneracoes';

    protected $fillable = [
        'colaborador_id',
        'modalidade',
        'valor_base',
        'valor_por_entrega',
        'carga_diaria_minutos',
        'carga_semanal_minutos',
        'percentual_extra_util',
        'percentual_extra_domingo_feriado',
        'percentual_adicional_noturno',
        'considera_hora_noturna_reduzida',
        'vigente_desde',
        'vigente_ate',
    ];

    protected function casts(): array
    {
        return [
            'modalidade' => ModalidadeRemuneracao::class,
            'valor_base' => 'decimal:2',
            'valor_por_entrega' => 'decimal:2',
            'carga_diaria_minutos' => 'integer',
            'carga_semanal_minutos' => 'integer',
            'percentual_extra_util' => 'decimal:2',
            'percentual_extra_domingo_feriado' => 'decimal:2',
            'percentual_adicional_noturno' => 'decimal:2',
            'considera_hora_noturna_reduzida' => 'boolean',
            'vigente_desde' => 'date',
            'vigente_ate' => 'date',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id')->withTrashed();
    }
}
