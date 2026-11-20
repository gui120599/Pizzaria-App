<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pausa temporária de um produto (App\Services\Gestao\PausaProdutoService).
 * `canais` são os campos que a pausa desligou (produto_cardapio e/ou
 * produto_cardapio_garcom): a retomada religa só esses.
 */
class ProdutoPausa extends Model
{
    public const CANAL_ONLINE = 'online';

    public const CANAL_SALAO = 'salao';

    /** Canal da pausa → coluna do produto. */
    public const CAMPOS = [
        self::CANAL_ONLINE => 'produto_cardapio',
        self::CANAL_SALAO => 'produto_cardapio_garcom',
    ];

    protected $table = 'produto_pausas';

    protected $fillable = [
        'produto_id',
        'user_id',
        'canais',
        'motivo',
        'pausado_ate',
        'retomada_em',
        'retomada_por',
    ];

    protected function casts(): array
    {
        return [
            'canais' => 'array',
            'pausado_ate' => 'datetime',
            'retomada_em' => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeEmAberto(Builder $query): Builder
    {
        return $query->whereNull('retomada_em');
    }

    public static function rotuloCanal(string $canal): string
    {
        return match ($canal) {
            self::CANAL_ONLINE => 'cardápio online',
            self::CANAL_SALAO => 'atendimento/garçom',
            default => $canal,
        };
    }
}
