<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma promoção combo aplicada num pedido, com o retrato da promoção no
 * momento (nome, preço, quantidade): editar a promoção depois não muda o
 * histórico do pedido.
 */
class PedidoComboAplicacao extends Model
{
    protected $table = 'pedido_combo_aplicacoes';

    protected $fillable = [
        'pca_pedido_id',
        'pca_promocao_combo_id',
        'pca_chave',
        'pca_nome',
        'pca_quantidade',
        'pca_preco',
        'pca_valor_cheio',
        'pca_desconto',
    ];

    protected function casts(): array
    {
        return [
            'pca_quantidade' => 'integer',
            'pca_preco' => 'decimal:2',
            'pca_valor_cheio' => 'decimal:2',
            'pca_desconto' => 'decimal:2',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pca_pedido_id');
    }

    public function promocao(): BelongsTo
    {
        return $this->belongsTo(PromocaoCombo::class, 'pca_promocao_combo_id')->withTrashed();
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoComboItem::class, 'pci_aplicacao_id');
    }
}
