<?php

namespace App\Models;

use App\Enums\TipoNotaMoeda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotaMoeda extends Model
{
    use SoftDeletes;

    protected $table = 'notas_moedas';

    protected $fillable = [
        'descricao',
        'valor',
        'tipo',
        'ordem_exibicao',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'tipo' => TipoNotaMoeda::class,
            'ordem_exibicao' => 'integer',
        ];
    }

    public function scopeOrdenadas(Builder $query): Builder
    {
        return $query->orderByDesc('ordem_exibicao');
    }
}
