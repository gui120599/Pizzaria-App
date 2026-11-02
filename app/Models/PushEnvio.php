<?php

namespace App\Models;

use App\Enums\EventoPushGarcomEnum;
use Database\Factories\PushEnvioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de cada push enviado a um aparelho: garante a idempotência
 * (único por chave + aparelho), mostra o que falhou e permite o reenvio.
 */
class PushEnvio extends Model
{
    /** @use HasFactory<PushEnvioFactory> */
    use HasFactory;

    use MassPrunable;

    public const PENDENTE = 'PENDENTE';

    public const ENVIADO = 'ENVIADO';

    public const FALHOU = 'FALHOU';

    public const EXPIRADO = 'EXPIRADO';

    public const DESCARTADO = 'DESCARTADO';

    /** Primeiro envio + reenvios de falha transitória. */
    public const MAX_TENTATIVAS = 3;

    protected $table = 'push_envios';

    protected $fillable = [
        'pe_chave',
        'pe_evento',
        'pe_user_id',
        'pe_dispositivo_id',
        'pe_status',
        'pe_tentativas',
        'pe_http_status',
        'pe_erro',
        'pe_payload',
        'pe_expira_em',
    ];

    protected function casts(): array
    {
        return [
            'pe_evento' => EventoPushGarcomEnum::class,
            'pe_payload' => 'array',
            'pe_expira_em' => 'datetime',
        ];
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoPush::class, 'pe_dispositivo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pe_user_id');
    }

    public function prunable()
    {
        return static::query()->where('created_at', '<', now()->subDays(30));
    }
}
