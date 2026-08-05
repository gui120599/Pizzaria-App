<?php

namespace App\Models;

use App\Enums\OperadoraMaquininha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Maquininha extends Model
{
    use SoftDeletes;

    protected $table = 'maquininhas';

    protected $fillable = [
        'nome',
        'operadora',
        'identificador_externo',
    ];

    protected function casts(): array
    {
        return [
            'operadora' => OperadoraMaquininha::class,
        ];
    }
}
