<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pergunta feita ao montar um item ("Escolha a borda", "Ponto da carne"), de
 * um produto ou de uma categoria. Vale em todos os canais (garçom, balcão,
 * cardápio e mesa) — ver LancamentoItemPedidoService. Obrigatória quando o
 * mínimo de escolhas é 1 ou mais.
 */
class Pergunta extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'pergunta_produto_id',
        'pergunta_categoria_id',
        'pergunta_texto',
        'pergunta_minimo',
        'pergunta_maximo',
        'pergunta_ordem',
        'pergunta_ativa',
    ];

    protected function casts(): array
    {
        return [
            'pergunta_minimo' => 'integer',
            'pergunta_maximo' => 'integer',
            'pergunta_ordem' => 'integer',
            'pergunta_ativa' => 'boolean',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'pergunta_produto_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'pergunta_categoria_id');
    }

    public function opcoes(): HasMany
    {
        return $this->hasMany(PerguntaOpcao::class, 'pergunta_opcao_pergunta_id')
            ->orderBy('pergunta_opcao_ordem')
            ->orderBy('id');
    }

    public function opcoesAtivas(): HasMany
    {
        return $this->opcoes()->where('pergunta_opcao_ativa', true);
    }

    public function scopeAtivas(Builder $query): Builder
    {
        return $query->where('pergunta_ativa', true);
    }

    public function obrigatoria(): bool
    {
        return $this->pergunta_minimo > 0;
    }

    /**
     * Dados que as telas (seletor do garçom, cardápio) precisam para montar a
     * pergunta.
     *
     * @return array{id: int, texto: string, minimo: int, maximo: int, opcoes: list<array{id: int, nome: string, valor: float}>}
     */
    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'texto' => $this->pergunta_texto,
            'minimo' => $this->pergunta_minimo,
            'maximo' => $this->pergunta_maximo,
            'opcoes' => $this->opcoesAtivas
                ->map(fn (PerguntaOpcao $opcao) => [
                    'id' => $opcao->id,
                    'nome' => $opcao->pergunta_opcao_nome,
                    'valor' => (float) $opcao->pergunta_opcao_valor,
                ])
                ->values()
                ->all(),
        ];
    }
}
