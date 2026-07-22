<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categoria extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'categoria_nome',
        'categoria_pai_id',
        'categoria_preposicao_padrao',
        'categoria_ordem',
        'categoria_cardapio',
        'categoria_cardapio_garcom',
        'categoria_permite_sabores',
        'categoria_max_sabores',
    ];

    protected $casts = [
        'categoria_cardapio' => 'boolean',
        'categoria_cardapio_garcom' => 'boolean',
        'categoria_permite_sabores' => 'boolean',
        'categoria_max_sabores' => 'integer',
        'categoria_ordem' => 'integer',
    ];

    protected static function booted(): void
    {
        // Garante auto-incremento da ordem em toda via de criação (Filament,
        // tela legada, tinker...). Sem isso, categorias criadas sem informar
        // a ordem caem todas no default 0 da coluna e disputam a mesma
        // posição quando alguém reordena via drag-and-drop.
        static::creating(function (Categoria $categoria) {
            if ($categoria->categoria_ordem === null) {
                $categoria->categoria_ordem = ((int) static::max('categoria_ordem')) + 1;
            }
        });
    }

    public function produtos()
    {
        return $this->hasMany(Produto::class, 'produto_categoria_id');
    }

    public function pai(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_pai_id');
    }

    public function filhas(): HasMany
    {
        return $this->hasMany(Categoria::class, 'categoria_pai_id');
    }

    /**
     * IDs de todas as categorias descendentes desta (filhas, netas...). Usado
     * pra impedir escolher a própria categoria (ou uma descendente dela) como
     * pai, o que criaria um ciclo na hierarquia.
     *
     * @return array<int>
     */
    public function idsDescendentes(): array
    {
        $ids = [];
        $pendentes = [$this->id];

        while ($pendentes !== []) {
            $filhosIds = static::query()->whereIn('categoria_pai_id', $pendentes)->pluck('id')->all();
            $novos = array_diff($filhosIds, $ids);

            if ($novos === []) {
                break;
            }

            $ids = array_merge($ids, $novos);
            $pendentes = $novos;
        }

        return $ids;
    }

    public function historicosPrecos(): HasMany
    {
        return $this->hasMany(ProdutoPrecoHistorico::class, 'categoria_id');
    }

    public function ultimoHistoricoPreco(): HasOne
    {
        return $this->hasOne(ProdutoPrecoHistorico::class, 'categoria_id')->latestOfMany();
    }
}
