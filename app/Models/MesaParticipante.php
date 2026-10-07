<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Um celular identificado (nome + celular) numa sessão de mesa, pedindo pelo
 * QR. O token vai num cookie do celular; o banco guarda só o hash
 * (mp_token_hash). O acesso vale enquanto a sessão estiver ABERTA e o
 * garçom não bloquear o celular.
 */
class MesaParticipante extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'mesa_participantes';

    protected $fillable = [
        'mp_sessao_mesa_id',
        'mp_cliente_id',
        'mp_nome',
        'mp_celular',
        'mp_token_hash',
        'mp_ip',
        'mp_user_agent',
        'mp_ultimo_acesso_em',
        'mp_bloqueado_em',
        'mp_bloqueado_por_id',
    ];

    protected $hidden = ['mp_token_hash'];

    protected function casts(): array
    {
        return [
            'mp_ultimo_acesso_em' => 'datetime',
            'mp_bloqueado_em' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Celular só com dígitos, como no cadastro de clientes (ClienteObserver).
        static::saving(function (MesaParticipante $participante): void {
            if ($participante->isDirty('mp_celular')) {
                $participante->mp_celular = preg_replace('/\D/', '', (string) $participante->mp_celular);
            }
        });
    }

    /** Token novo para o cookie do celular (40 caracteres aleatórios). */
    public static function gerarToken(): string
    {
        return Str::random(40);
    }

    public static function hashDoToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function porToken(string $token): ?self
    {
        return static::where('mp_token_hash', self::hashDoToken($token))->first();
    }

    public function sessaoMesa(): BelongsTo
    {
        return $this->belongsTo(SessaoMesa::class, 'mp_sessao_mesa_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'mp_cliente_id');
    }

    public function bloqueadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mp_bloqueado_por_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'pedido_mesa_participante_id');
    }

    public function chamados(): HasMany
    {
        return $this->hasMany(MesaChamado::class, 'mc_mesa_participante_id');
    }

    public function estaBloqueado(): bool
    {
        return $this->mp_bloqueado_em !== null;
    }
}
