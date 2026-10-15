<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Trilha de auditoria do módulo de ponto. Imutável: só `created_at`.
 */
class PontoHistorico extends Model
{
    protected $table = 'ponto_historicos';

    public const UPDATED_AT = null;

    protected $fillable = [
        'auditavel_type',
        'auditavel_id',
        'evento',
        'dados',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'dados' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function auditavel(): MorphTo
    {
        return $this->morphTo();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withDefault([
            'name_first' => 'Sistema',
        ]);
    }

    /** @param  array<string, mixed>|null  $dados */
    public static function registrar(Model $auditavel, string $evento, ?array $dados = null, ?int $userId = null): self
    {
        return static::create([
            'auditavel_type' => $auditavel->getMorphClass(),
            'auditavel_id' => $auditavel->getKey(),
            'evento' => $evento,
            'dados' => $dados,
            'user_id' => $userId ?? auth()->id(),
        ]);
    }
}
