<?php

namespace App\Models;

use App\Enums\Cozinha\AcaoProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Exceptions\RegistroImutavelException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento da linha do tempo de um item de produção — append-only. É a base
 * dos relatórios de tempo e de refação; nunca é editado nem apagado.
 */
class ProducaoEvento extends Model
{
    use HasFactory;

    protected $table = 'producao_eventos';

    public const UPDATED_AT = null;

    protected $fillable = [
        'producao_item_id',
        'pedido_id',
        'setor_producao_id',
        'acao',
        'de_status',
        'para_status',
        'user_id',
        'operador_id',
        'dispositivo_cozinha_id',
        'motivo',
        'dados',
    ];

    protected function casts(): array
    {
        return [
            'acao' => AcaoProducao::class,
            'de_status' => StatusItemProducao::class,
            'para_status' => StatusItemProducao::class,
            'dados' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw RegistroImutavelException::alteracao('O evento de produção', 'Registre um novo evento.');
        });

        static::deleting(function (): void {
            throw RegistroImutavelException::alteracao('O evento de produção', 'Registre um novo evento.');
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProducaoItem::class, 'producao_item_id')->withTrashed();
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function setor(): BelongsTo
    {
        return $this->belongsTo(SetorProducao::class, 'setor_producao_id')->withTrashed();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operador_id');
    }

    public function dispositivo(): BelongsTo
    {
        return $this->belongsTo(DispositivoCozinha::class, 'dispositivo_cozinha_id')->withTrashed();
    }
}
