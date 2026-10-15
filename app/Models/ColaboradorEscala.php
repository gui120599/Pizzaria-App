<?php

namespace App\Models;

use App\Models\Concerns\TemHistoricoPonto;
use App\Models\Concerns\TemVigencia;
use App\Observers\PontoAuditoriaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Escala que o colaborador segue numa vigência.
 */
#[ObservedBy([PontoAuditoriaObserver::class])]
class ColaboradorEscala extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoPonto, TemVigencia;

    protected $table = 'colaborador_escalas';

    protected $fillable = [
        'colaborador_id',
        'escala_id',
        'vigente_desde',
        'vigente_ate',
    ];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_ate' => 'date',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id')->withTrashed();
    }

    public function escala(): BelongsTo
    {
        return $this->belongsTo(Escala::class, 'escala_id')->withTrashed();
    }
}
