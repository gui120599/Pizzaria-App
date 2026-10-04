<?php

namespace App\Models;

use App\Enums\OperadoraMaquininha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Maquininha extends Model
{
    use SoftDeletes;

    protected $table = 'maquininhas';

    protected $fillable = [
        'nome',
        'operadora',
        'numero_serie',
        'identificador_externo',
        'maquininha_padrao',
    ];

    protected function casts(): array
    {
        return [
            'operadora' => OperadoraMaquininha::class,
            'maquininha_padrao' => 'boolean',
        ];
    }

    /** Só uma maquininha padrão por vez. */
    protected static function booted(): void
    {
        static::saved(function (Maquininha $maquininha): void {
            if ($maquininha->maquininha_padrao && ($maquininha->wasRecentlyCreated || $maquininha->wasChanged('maquininha_padrao'))) {
                static::whereKeyNot($maquininha->getKey())->where('maquininha_padrao', true)->update(['maquininha_padrao' => false]);
            }
        });
    }

    public function taxas(): HasMany
    {
        return $this->hasMany(MaquininhaTaxa::class, 'mt_maquininha_id');
    }

    /** Maquininha usada quando o pagamento não informa qual foi. */
    public static function padrao(): ?self
    {
        return static::where('maquininha_padrao', true)->first();
    }

    /**
     * Maquininhas aptas a receber um pedido Stone: operadora Stone e com
     * número de série cadastrado (é o devices_serial_number que endereça o
     * POST /orders ao aparelho físico — sem ele a Stone não sabe pra onde
     * mandar). Critério único usado em todo ponto de disparo (PDV, balcão,
     * entregador, mesa) — ver OperarVenda::maquininhasStone() (origem deste
     * scope, extraído para reaproveitar fora do PDV).
     */
    public function scopeStoneDisponivel(Builder $query): Builder
    {
        return $query->where('operadora', OperadoraMaquininha::Stone->value)
            ->whereNotNull('numero_serie');
    }
}
