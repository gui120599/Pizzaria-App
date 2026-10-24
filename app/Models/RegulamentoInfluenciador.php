<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Versão publicada do regulamento do programa de influenciadores (texto interno). */
class RegulamentoInfluenciador extends Model
{
    protected $table = 'regulamentos_influenciador';

    protected $fillable = [
        'ri_versao',
        'ri_texto',
        'ri_publicado_em',
        'ri_user_id',
    ];

    protected function casts(): array
    {
        return [
            'ri_versao' => 'integer',
            'ri_publicado_em' => 'datetime',
        ];
    }

    /** Versão em vigor (a mais recente), ou null enquanto nada foi publicado. */
    public static function atual(): ?self
    {
        return static::query()->orderByDesc('ri_versao')->first();
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'ri_user_id');
    }
}
