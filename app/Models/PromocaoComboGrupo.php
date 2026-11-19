<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma posição do combo: "1 Pizza Grande", "1 Pizza Brotinho". Tem quantidade
 * e participantes próprios; o combo fecha quando todos os grupos completam.
 */
class PromocaoComboGrupo extends Model
{
    protected $table = 'promocao_combo_grupos';

    protected $fillable = [
        'pcg_promocao_combo_id',
        'pcg_nome',
        'pcg_quantidade',
        'pcg_ordem',
    ];

    protected function casts(): array
    {
        return [
            'pcg_quantidade' => 'integer',
            'pcg_ordem' => 'integer',
        ];
    }

    public function promocao(): BelongsTo
    {
        return $this->belongsTo(PromocaoCombo::class, 'pcg_promocao_combo_id');
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(PromocaoComboParticipante::class, 'pcp_grupo_id');
    }
}
