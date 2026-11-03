<?php

namespace App\Models;

use Database\Factories\PlantaoFreelancerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dia de trabalho de um garçom freelancer, marcado pelo gerente. `pl_dia` é o
 * dia operacional (ver App\Support\DiaOperacional): a marcação perde o efeito
 * sozinha na virada do dia, e o histórico serve para conferir as diárias.
 */
class PlantaoFreelancer extends Model
{
    /** @use HasFactory<PlantaoFreelancerFactory> */
    use HasFactory;

    protected $table = 'plantoes_freelancer';

    protected $fillable = [
        'pl_user_id',
        'pl_dia',
        'pl_marcado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'pl_dia' => 'date',
        ];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pl_user_id');
    }

    public function marcadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pl_marcado_por_id');
    }
}
