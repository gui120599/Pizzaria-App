<?php

namespace App\Models;

use App\Enums\AcaoAutorizadaEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Trilha de auditoria das ações sensíveis do salão (cancelar item, remover
 * taxa de serviço, transferir mesa): quem pediu, quem autorizou e o que mudou.
 */
class AutorizacaoGerente extends Model
{
    use SoftDeletes;

    protected $table = 'autorizacoes_gerente';

    protected $fillable = [
        'autorizacao_acao',
        'autorizacao_solicitante_id',
        'autorizacao_autorizador_id',
        'autorizacao_auditavel_type',
        'autorizacao_auditavel_id',
        'autorizacao_motivo',
        'autorizacao_dados',
    ];

    protected $casts = [
        'autorizacao_acao' => AcaoAutorizadaEnum::class,
        'autorizacao_dados' => 'array',
    ];

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizacao_solicitante_id');
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizacao_autorizador_id');
    }

    public function auditavel(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'autorizacao_auditavel_type', 'autorizacao_auditavel_id');
    }
}
