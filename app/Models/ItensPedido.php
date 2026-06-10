<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItensPedido extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_pedido_produto_id',
        'item_pedido_pedido_id',
        'item_pedido_cliente_id',
        'item_pedido_venda_id',
        'item_pedido_quantidade',
        'item_pedido_valor_unitario',
        'item_pedido_desconto',
        'item_pedido_valor_adicionais',
        'item_pedido_valor',
        'item_pedido_observacao',
        'item_pedido_status',
        'item_pedido_usuario_removeu',
    ];

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'item_pedido_produto_id');
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'item_pedido_pedido_id');
    }

    public function usuarioRemoveu()
    {
        return $this->belongsTo(User::class, 'item_pedido_usuario_removeu');
    }

    public function adicionaisItemPedido(){
        return $this->hasMany( AdicionaisItemPedido::class, 'aip_item_pedido_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'item_pedido_cliente_id');
    }

    public function venda()
    {
        return $this->belongsTo(\App\Models\Venda::class, 'item_pedido_venda_id');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Regra de negócio do valor do item (fonte da verdade)
    //
    //   item_pedido_valor_unitario = max(preco_venda, preco_promocional)
    //   desconto unitário          = (promo > 0 && promo < venda) ? (venda - promo) : 0
    //   item_pedido_desconto       = desconto_unitário * quantidade
    //   item_pedido_valor          = (quantidade * valor_unitário) - desconto + adicionais   [LÍQUIDO]
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Deriva o valor unitário base e o desconto unitário de um produto,
     * segundo a regra de promoção.
     *
     * @return array{valor_unitario: float, desconto_unitario: float}
     */
    public static function precoUnitario(Produto $produto): array
    {
        $precoVenda = (float) $produto->produto_preco_venda;
        $precoPromo = (float) ($produto->produto_preco_promocional ?? 0);

        $valorUnitario    = max($precoVenda, $precoPromo);
        $descontoUnitario = ($precoPromo > 0 && $precoPromo < $precoVenda)
            ? ($precoVenda - $precoPromo)
            : 0.0;

        return [
            'valor_unitario'    => round($valorUnitario, 2),
            'desconto_unitario' => round($descontoUnitario, 2),
        ];
    }

    /**
     * Calcula o valor líquido de uma linha de item segundo a regra de negócio.
     *
     * @param  float       $quantidade
     * @param  float       $valorUnitario      Valor unitário base (max venda/promo)
     * @param  float       $descontoUnitario   Desconto por unidade
     * @param  float       $valorAdicionais    Total de adicionais da linha
     * @return array{quantidade: float, valor_unitario: float, desconto: float, adicionais: float, valor: float}
     */
    public static function calcularLinha(
        float $quantidade,
        float $valorUnitario,
        float $descontoUnitario,
        float $valorAdicionais = 0.0
    ): array {
        $desconto = round($descontoUnitario * $quantidade, 2);
        $valor    = round(($valorUnitario * $quantidade) - $desconto + $valorAdicionais, 2);

        return [
            'quantidade'     => $quantidade,
            'valor_unitario' => round($valorUnitario, 2),
            'desconto'       => $desconto,
            'adicionais'     => round($valorAdicionais, 2),
            'valor'          => $valor,
        ];
    }

    /**
     * Recalcula e atribui (sem salvar) os campos de valor deste item a partir
     * do produto vinculado, da quantidade atual e dos adicionais persistidos.
     */
    public function recalcularValores(?float $valorAdicionais = null): static
    {
        $produto = $this->produto;
        if (! $produto) {
            return $this;
        }

        $precos     = static::precoUnitario($produto);
        $quantidade = (float) $this->item_pedido_quantidade;
        $adicionais = $valorAdicionais ?? (float) $this->adicionaisItemPedido()->sum('aip_valor_total');

        $linha = static::calcularLinha(
            $quantidade,
            $precos['valor_unitario'],
            $precos['desconto_unitario'],
            $adicionais
        );

        $this->item_pedido_valor_unitario   = $linha['valor_unitario'];
        $this->item_pedido_desconto         = $linha['desconto'];
        $this->item_pedido_valor_adicionais = $linha['adicionais'];
        $this->item_pedido_valor            = $linha['valor'];

        return $this;
    }
}
