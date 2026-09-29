<?php

namespace App\Models;

use App\Enums\OperadoraMaquininha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    ];

    protected function casts(): array
    {
        return [
            'operadora' => OperadoraMaquininha::class,
        ];
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
