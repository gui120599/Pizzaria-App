<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Categoria (com subcategorias) ou produto que participa — ou fica fora — de uma promoção combo. */
class PromocaoComboParticipante extends Model
{
    protected $table = 'promocao_combo_participantes';

    protected $fillable = [
        'pcp_promocao_combo_id',
        'pcp_grupo_id',
        'pcp_categoria_id',
        'pcp_produto_id',
        'pcp_excluir',
    ];

    protected function casts(): array
    {
        return [
            'pcp_excluir' => 'boolean',
        ];
    }

    /** Criado pelo grupo (Repeater aninhado do formulário): herda a promoção dele. */
    protected static function booted(): void
    {
        static::creating(function (self $participante): void {
            if ($participante->pcp_promocao_combo_id === null && $participante->pcp_grupo_id !== null) {
                $participante->pcp_promocao_combo_id = PromocaoComboGrupo::whereKey($participante->pcp_grupo_id)->value('pcg_promocao_combo_id');
            }
        });
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(PromocaoComboGrupo::class, 'pcp_grupo_id');
    }

    public function promocao(): BelongsTo
    {
        return $this->belongsTo(PromocaoCombo::class, 'pcp_promocao_combo_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'pcp_categoria_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'pcp_produto_id');
    }
}
