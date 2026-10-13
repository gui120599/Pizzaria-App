<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistModeloSecao extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'checklist_modelo_secoes';

    protected $fillable = [
        'checklist_modelo_id',
        'titulo',
        'ordem',
    ];

    /** Seção removida no formulário leva os itens junto (soft delete não cascateia). */
    protected static function booted(): void
    {
        static::deleting(function (self $secao): void {
            $secao->itens()->get()->each->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
        ];
    }

    public function modelo(): BelongsTo
    {
        return $this->belongsTo(ChecklistModelo::class, 'checklist_modelo_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ChecklistModeloItem::class, 'checklist_modelo_secao_id')->orderBy('ordem');
    }
}
