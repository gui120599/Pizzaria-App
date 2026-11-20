<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Foto do comprovante de uma compra (disco local, pasta compras_anexos/). */
class CompraAnexo extends Model
{
    use SoftDeletes;

    protected $table = 'compra_anexos';

    protected $fillable = [
        'compra_id',
        'caminho',
        'mime',
        'tamanho',
        'enviado_por',
    ];

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
