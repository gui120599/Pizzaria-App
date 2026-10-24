<?php

namespace App\Models;

use App\Enums\TipoMaterialEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaterialDivulgacao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'materiais_divulgacao';

    protected $fillable = [
        'material_titulo',
        'material_descricao',
        'material_tipo',
        'material_arquivo_path',
        'material_conteudo_texto',
        'material_ativo',
        'material_ordem',
    ];

    protected function casts(): array
    {
        return [
            'material_tipo' => TipoMaterialEnum::class,
            'material_ativo' => 'boolean',
        ];
    }

    public function scopeAtivos($query)
    {
        return $query->where('material_ativo', true)->orderBy('material_ordem');
    }
}
