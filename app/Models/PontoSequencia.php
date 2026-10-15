<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Linha de controle do NSR e do último hash da cadeia. A linha 'marcacoes'
 * é criada pela migration e travada com lockForUpdate a cada marcação.
 */
class PontoSequencia extends Model
{
    public const MARCACOES = 'marcacoes';

    protected $table = 'ponto_sequencias';

    protected $fillable = [
        'nome',
        'ultimo_nsr',
        'ultimo_hash',
    ];

    protected function casts(): array
    {
        return [
            'ultimo_nsr' => 'integer',
        ];
    }
}
