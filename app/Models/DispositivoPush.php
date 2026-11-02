<?php

namespace App\Models;

use Database\Factories\DispositivoPushFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inscrição Web Push de um aparelho. Pertence ao aparelho, não à pessoa: o
 * vínculo com o usuário (`dp_user_id`) muda no login e se desfaz no logout.
 */
class DispositivoPush extends Model
{
    /** @use HasFactory<DispositivoPushFactory> */
    use HasFactory;

    protected $table = 'dispositivos_push';

    protected $fillable = [
        'dp_user_id',
        'dp_endpoint',
        'dp_endpoint_hash',
        'dp_chave_p256dh',
        'dp_chave_auth',
        'dp_content_encoding',
        'dp_plataforma',
        'dp_navegador',
        'dp_instalado',
        'dp_user_agent',
        'dp_ativo',
        'dp_pausado_ate',
        'dp_ultimo_sucesso_em',
        'dp_ultima_falha_em',
        'dp_falhas_consecutivas',
        'dp_desativado_em',
        'dp_motivo_desativacao',
    ];

    protected $hidden = [
        'dp_endpoint',
        'dp_chave_p256dh',
        'dp_chave_auth',
    ];

    protected function casts(): array
    {
        return [
            'dp_endpoint' => 'encrypted',
            'dp_chave_p256dh' => 'encrypted',
            'dp_chave_auth' => 'encrypted',
            'dp_instalado' => 'boolean',
            'dp_ativo' => 'boolean',
            'dp_pausado_ate' => 'datetime',
            'dp_ultimo_sucesso_em' => 'datetime',
            'dp_ultima_falha_em' => 'datetime',
            'dp_desativado_em' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dp_user_id');
    }

    public function envios(): HasMany
    {
        return $this->hasMany(PushEnvio::class, 'pe_dispositivo_id');
    }

    /** Ativo e fora da pausa: pode receber avisos agora. */
    public function scopeRecebendo(Builder $query): Builder
    {
        return $query->where('dp_ativo', true)
            ->where(fn (Builder $q) => $q->whereNull('dp_pausado_ate')->orWhere('dp_pausado_ate', '<=', now()));
    }

    public function estaPausado(): bool
    {
        return $this->dp_pausado_ate !== null && $this->dp_pausado_ate->isFuture();
    }

    public static function hashDoEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
