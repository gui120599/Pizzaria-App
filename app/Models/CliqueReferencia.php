<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Log append-only de cliques em /r/{slug}. Sem updated_at nem SoftDeletes. */
class CliqueReferencia extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cliques_referencia';

    protected $fillable = [
        'clique_influenciador_id',
        'clique_ip_hash',
        'clique_user_agent',
    ];

    public function influenciador()
    {
        return $this->belongsTo(Influenciador::class, 'clique_influenciador_id');
    }
}
