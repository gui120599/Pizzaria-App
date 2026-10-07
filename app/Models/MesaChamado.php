<?php

namespace App\Models;

use App\Enums\StatusChamadoMesaEnum;
use App\Enums\TipoChamadoMesaEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chamado do QR da mesa para o garçom: chamar, pedir a conta ou pedir para
 * abrir a mesa. Fica PENDENTE até alguém atender (quem e quando ficam aqui).
 */
class MesaChamado extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'mesa_chamados';

    protected $fillable = [
        'mc_mesa_id',
        'mc_sessao_mesa_id',
        'mc_mesa_participante_id',
        'mc_tipo',
        'mc_status',
        'mc_ip',
        'mc_atendido_por_id',
        'mc_atendido_em',
    ];

    protected function casts(): array
    {
        return [
            'mc_tipo' => TipoChamadoMesaEnum::class,
            'mc_status' => StatusChamadoMesaEnum::class,
            'mc_atendido_em' => 'datetime',
        ];
    }

    public function mesa(): BelongsTo
    {
        return $this->belongsTo(Mesa::class, 'mc_mesa_id');
    }

    public function sessaoMesa(): BelongsTo
    {
        return $this->belongsTo(SessaoMesa::class, 'mc_sessao_mesa_id');
    }

    public function participante(): BelongsTo
    {
        return $this->belongsTo(MesaParticipante::class, 'mc_mesa_participante_id');
    }

    public function atendidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mc_atendido_por_id');
    }

    public function scopePendentes(Builder $query): Builder
    {
        return $query->where('mc_status', StatusChamadoMesaEnum::PENDENTE->value);
    }
}
