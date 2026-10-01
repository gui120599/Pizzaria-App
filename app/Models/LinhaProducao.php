<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Linha de produção da cozinha (ex.: "Pizzas", "Bar"), associada a um
 * conjunto de categorias — usada pra filtrar o Painel de Pedidos por estação:
 * cada estação só precisa ver pedidos com pelo menos um item de sua linha.
 */
class LinhaProducao extends Model
{
    use HasFactory;

    protected $table = 'linhas_producao';

    protected $fillable = [
        'linha_nome',
    ];

    public function categorias(): BelongsToMany
    {
        return $this->belongsToMany(Categoria::class, 'categoria_linha_producao', 'linha_producao_id', 'categoria_id');
    }

    /**
     * IDs de categoria que fazem esta linha "bater" com um item de pedido —
     * as categorias associadas diretamente, mais todas as descendentes de
     * cada uma (uma linha ligada a "Pizzas" também cobre "Pizzas Doces").
     *
     * @return array<int>
     */
    public function idsCategoriasComDescendentes(): array
    {
        $ids = $this->categorias->pluck('id')->all();

        foreach ($this->categorias as $categoria) {
            $ids = array_merge($ids, $categoria->idsDescendentes());
        }

        return array_values(array_unique($ids));
    }
}
