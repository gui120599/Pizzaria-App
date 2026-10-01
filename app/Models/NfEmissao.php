<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Número de NF reservado para uma venda. Enquanto não for autorizado, o
 * reenvio da mesma venda reaproveita este registro (e o número); se nunca
 * for autorizado, vira lacuna a inutilizar (ver scopePendentesInutilizacao).
 */
class NfEmissao extends Model
{
    use SoftDeletes;

    public const STATUS_RESERVADO = 'Reservado';

    public const STATUS_FALHA_ENVIO = 'FalhaEnvio';

    public const STATUS_AUTORIZADA = 'Issued';

    /** Status em que o número já foi efetivamente usado na SEFAZ e não pode ser reaproveitado. */
    public const STATUS_NUMERO_CONSUMIDO = ['Issued', 'Cancelled', 'CancelamentoSolicitado'];

    public const DECISAO_AUTOMATICA = 'automatica';

    public const DECISAO_MANUAL = 'manual';

    protected $table = 'nf_emissoes';

    protected $fillable = [
        'venda_id',
        'numeracao_id',
        'modelo',
        'serie',
        'numero',
        'status',
        'nfeio_id',
        'decisao_envio',
        'user_id',
        'enviada_em',
        'autorizada_em',
    ];

    protected function casts(): array
    {
        return [
            'modelo' => 'integer',
            'serie' => 'integer',
            'numero' => 'integer',
            'enviada_em' => 'datetime',
            'autorizada_em' => 'datetime',
        ];
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_id');
    }

    public function numeracao(): BelongsTo
    {
        return $this->belongsTo(NfNumeracao::class, 'numeracao_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function numeroConsumido(): bool
    {
        return in_array($this->status, self::STATUS_NUMERO_CONSUMIDO, true);
    }

    /** Espelha o status vindo da NFe.io (webhook/polling/cancelamento). */
    public function atualizarStatus(string $status): void
    {
        $this->status = $status;

        if ($status === self::STATUS_AUTORIZADA && ! $this->autorizada_em) {
            $this->autorizada_em = now();
        }

        $this->save();
    }

    /** Mantém o status alinhado com a NFe.io a partir do id da nota lá (webhook, polling, cancelamento). */
    public static function espelharStatus(?string $nfeioId, string $status): void
    {
        if (blank($nfeioId)) {
            return;
        }

        static::where('nfeio_id', $nfeioId)->first()?->atualizarStatus($status);
    }

    /**
     * Números reservados há mais de 24h e nunca autorizados — candidatos à
     * inutilização na SEFAZ (a inutilização em si ainda não é feita pelo app).
     */
    public function scopePendentesInutilizacao(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::STATUS_NUMERO_CONSUMIDO)
            ->where('created_at', '<', now()->subDay());
    }
}
