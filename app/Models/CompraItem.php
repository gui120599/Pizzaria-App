<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompraItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'compra_itens';

    protected $fillable = [
        'ci_compra_id',
        'ci_produto_id',
        'ci_descricao_fornecedor',
        'ci_codigo_fornecedor',
        'ci_quantidade_compra',
        'ci_unidade_compra',
        'ci_fator_conversao',
        'ci_custo_unitario_compra',
        'ci_valor_rateio',
        'ci_lote_codigo',
        'ci_validade',
    ];

    protected function casts(): array
    {
        return [
            'ci_validade' => 'date',
            'ci_quantidade_compra' => 'decimal:4',
            'ci_fator_conversao' => 'decimal:4',
            'ci_custo_unitario_compra' => 'decimal:4',
            'ci_valor_rateio' => 'decimal:4',
        ];
    }

    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'ci_compra_id');
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'ci_produto_id');
    }

    /** Quantidade convertida para a unidade de estoque. */
    public function quantidadeEstoque(): float
    {
        return (float) $this->ci_quantidade_compra * ((float) $this->ci_fator_conversao ?: 1);
    }

    /** Valor dos produtos deste item (sem rateio). */
    public function valorProdutos(): float
    {
        return (float) $this->ci_quantidade_compra * (float) $this->ci_custo_unitario_compra;
    }

    /** Custo unitário na unidade de estoque, já com o rateio. */
    public function custoUnitarioEstoque(): float
    {
        $qtd = $this->quantidadeEstoque();
        if ($qtd <= 0) {
            return 0.0;
        }

        return ($this->valorProdutos() + (float) $this->ci_valor_rateio) / $qtd;
    }
}
