<?php

namespace App\Models;

use App\Enums\RegraPrecoSaboresEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

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
        'categoria_requer_aprovacao_mesa',
        'categoria_permite_sabores',
        'categoria_herda_sabores',
        'categoria_regra_preco_sabores',
    ];

    protected $casts = [
        'categoria_cardapio' => 'boolean',
        'categoria_cardapio_garcom' => 'boolean',
        'categoria_requer_aprovacao_mesa' => 'boolean',
        'categoria_permite_sabores' => 'boolean',
        'categoria_herda_sabores' => 'boolean',
        'categoria_ordem' => 'integer',
        'categoria_regra_preco_sabores' => RegraPrecoSaboresEnum::class,
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

    public function perguntas(): HasMany
    {
        return $this->hasMany(Pergunta::class, 'pergunta_categoria_id')
            ->orderBy('pergunta_ordem')
            ->orderBy('id');
    }

    /**
     * Perguntas ativas que valem para um item desta categoria — inclusive a
     * pizza de sabores: as da categoria mãe primeiro, depois as próprias.
     *
     * @return Collection<int, Pergunta>
     */
    public function perguntasAplicaveis(): Collection
    {
        return Pergunta::query()
            ->ativas()
            ->whereIn('pergunta_categoria_id', array_filter([$this->categoria_pai_id, $this->id]))
            ->with('opcoesAtivas')
            ->get()
            ->sortBy(fn (Pergunta $pergunta) => [
                $pergunta->pergunta_categoria_id === $this->id ? 1 : 0,
                $pergunta->pergunta_ordem,
                $pergunta->id,
            ])
            ->values();
    }

    public function quantidadesSabores(): HasMany
    {
        return $this->hasMany(QuantidadeSabor::class, 'quantidade_sabor_categoria_id')
            ->orderBy('quantidade_sabor_ordem')
            ->orderBy('quantidade_sabor_quantidade');
    }

    /**
     * A subcategoria herda as opções de quantidade de sabores da categoria pai?
     * Só vale quando de fato existe pai — sem ele o toggle é ignorado.
     */
    public function herdaQuantidadesSabores(): bool
    {
        return $this->categoria_pai_id !== null && $this->categoria_herda_sabores;
    }

    /**
     * Opções de quantidade de sabores efetivas: as da categoria pai quando
     * esta herda, senão as próprias.
     *
     * @return Collection<int, QuantidadeSabor>
     */
    public function quantidadesSaboresResolvidas(): Collection
    {
        if ($this->herdaQuantidadesSabores() && $this->pai) {
            return $this->pai->quantidadesSabores;
        }

        return $this->quantidadesSabores;
    }

    /**
     * Regra de preço da pizza de vários sabores, seguindo a mesma herança das
     * opções de quantidade (categoria_herda_sabores).
     */
    public function regraPrecoSaboresResolvida(): RegraPrecoSaboresEnum
    {
        if ($this->herdaQuantidadesSabores() && $this->pai) {
            return $this->pai->regraPrecoSaboresResolvida();
        }

        return $this->categoria_regra_preco_sabores ?? RegraPrecoSaboresEnum::MEDIA;
    }

    public function opcaoQuantidadeSabores(int $quantidade): ?QuantidadeSabor
    {
        return $this->quantidadesSaboresResolvidas()
            ->firstWhere('quantidade_sabor_quantidade', $quantidade);
    }

    /**
     * Maior quantidade de sabores combináveis nesta categoria — 1 quando ela
     * não permite sabores ou não tem opção cadastrada além do sabor único.
     */
    public function maxSabores(): int
    {
        if (! $this->categoria_permite_sabores) {
            return 1;
        }

        return max(1, (int) $this->quantidadesSaboresResolvidas()->max('quantidade_sabor_quantidade'));
    }

    /**
     * Cria (ou completa) as opções de 1 até $max sabores com percentuais
     * iguais — atalho para seeders/testes e para o padrão de categoria nova.
     */
    public function sincronizarQuantidadesSabores(int $max): void
    {
        for ($n = 1; $n <= $max; $n++) {
            $this->quantidadesSabores()->firstOrCreate(
                ['quantidade_sabor_quantidade' => $n],
                [
                    'quantidade_sabor_descricao' => match ($n) {
                        1 => 'Sabor único',
                        2 => 'Meia a meia',
                        default => "{$n} sabores",
                    },
                    'quantidade_sabor_percentuais' => QuantidadeSabor::percentuaisIguais($n),
                    'quantidade_sabor_ordem' => $n,
                ],
            );
        }

        $this->unsetRelation('quantidadesSabores');
    }

    public function historicosPrecos(): HasMany
    {
        return $this->hasMany(ProdutoPrecoHistorico::class, 'categoria_id');
    }

    public function ultimoHistoricoPreco(): HasOne
    {
        return $this->hasOne(ProdutoPrecoHistorico::class, 'categoria_id')->latestOfMany();
    }

    public function linhasProducao(): BelongsToMany
    {
        return $this->belongsToMany(LinhaProducao::class, 'categoria_linha_producao', 'categoria_id', 'linha_producao_id');
    }
}
