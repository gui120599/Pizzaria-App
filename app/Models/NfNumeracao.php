<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sequência de numeração de NF por emitente + ambiente + modelo + série.
 * Só App\Services\Nfe\NfNumeracaoService deve mexer em ultimo_numero
 * (sempre sob lockForUpdate).
 */
class NfNumeracao extends Model
{
    use SoftDeletes;

    public const MODELO_NFCE = 65;

    protected $table = 'nf_numeracoes';

    protected $fillable = [
        'emitente_cnpj',
        'ambiente',
        'modelo',
        'serie',
        'ultimo_numero',
    ];

    protected function casts(): array
    {
        return [
            'modelo' => 'integer',
            'serie' => 'integer',
            'ultimo_numero' => 'integer',
        ];
    }

    public function emissoes(): HasMany
    {
        return $this->hasMany(NfEmissao::class, 'numeracao_id');
    }
}
