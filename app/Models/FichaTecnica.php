<?php

namespace App\Models;

use App\Models\Concerns\TemHistoricoCozinha;
use App\Observers\CozinhaAuditoriaObserver;
use App\Observers\FichaTecnicaVersaoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cabeçalho de preparo da ficha técnica de um produto: passos, fotos,
 * alérgenos. Os ingredientes são as linhas de `ficha_tecnica_itens` do mesmo
 * produto — a mesma fonte do CMV e da baixa de estoque.
 */
#[ObservedBy([CozinhaAuditoriaObserver::class, FichaTecnicaVersaoObserver::class])]
class FichaTecnica extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoCozinha;

    protected $table = 'fichas_tecnicas';

    protected $fillable = [
        'produto_id',
        'descricao',
        'tempo_preparo_min',
        'foto_apresentacao',
        'video_url',
        'alergenos',
        'pontos_criticos',
        'versao',
        'ativo',
        'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'tempo_preparo_min' => 'integer',
            'alergenos' => 'array',
            'versao' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    /** Ingredientes: linhas da ficha de custo/estoque do mesmo produto, na ordem da cozinha. */
    public function ingredientes(): HasMany
    {
        return $this->hasMany(FichaTecnicaItem::class, 'fti_produto_id', 'produto_id')
            ->orderBy('fti_ordem')
            ->orderBy('id');
    }

    public function passos(): HasMany
    {
        return $this->hasMany(FichaTecnicaPasso::class, 'ficha_tecnica_id')
            ->orderBy('ordem')
            ->orderBy('id');
    }

    /** Itens de produção que usaram esta ficha (contagem "usada X vezes"). */
    public function producaoItens(): HasMany
    {
        return $this->hasMany(ProducaoItem::class, 'ficha_tecnica_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function scopeAtivas(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }
}
