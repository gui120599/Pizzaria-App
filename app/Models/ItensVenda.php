<?php

namespace App\Models;

use App\Models\Concerns\TemRespostas;
use App\Models\Concerns\TemSabores;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItensVenda extends Model
{
    use HasFactory;
    use TemRespostas;
    use TemSabores;

    protected $table = 'itens_vendas';

    protected $fillable = [
        'item_numero',
        'item_venda_venda_id',
        'item_venda_produto_id',
        'item_venda_quantidade',
        'item_venda_quantidade_tributavel',
        'item_venda_valor_unitario',
        'item_venda_custo_unitario',
        'item_venda_valor_adicionais',
        'item_venda_valor_unitario_tributavel',
        'item_venda_desconto',
        'item_venda_desconto_percentual_aplicado',
        'item_venda_valor',
        'item_venda_valor_base_calculo',
        'item_venda_valor_icms',
        'item_venda_valor_pis',
        'item_venda_valor_cofins',
        'item_venda_valor_total_tributos',
        'item_venda_observacao',
        'item_venda_sabores',
        'item_venda_respostas',
        'item_venda_status',
        'item_venda_usuario_removeu',
    ];

    protected $casts = [
        'item_venda_quantidade' => 'double',
        'item_venda_quantidade_tributavel' => 'double',
        'item_venda_valor_unitario' => 'decimal:2',
        'item_venda_custo_unitario' => 'decimal:8',
        'item_venda_valor_adicionais' => 'decimal:2',
        'item_venda_valor_unitario_tributavel' => 'decimal:2',
        'item_venda_desconto' => 'decimal:2',
        'item_venda_desconto_percentual_aplicado' => 'decimal:2',
        'item_venda_valor' => 'decimal:2',
        'item_venda_base_calculo_icms' => 'decimal:2',
        'item_venda_valor_icms' => 'decimal:2',
        'item_venda_valor_pis' => 'decimal:2',
        'item_venda_valor_cofins' => 'decimal:2',
        'item_venda_valor_total_tributos' => 'decimal:2',
        'item_venda_sabores' => 'array',
        'item_venda_respostas' => 'array',
    ];

    protected function colunaSabores(): string
    {
        return 'item_venda_sabores';
    }

    protected function colunaRespostas(): string
    {
        return 'item_venda_respostas';
    }

    protected function colunaProduto(): string
    {
        return 'item_venda_produto_id';
    }

    protected function colunaQuantidade(): string
    {
        return 'item_venda_quantidade';
    }

    /**
     * Linha da venda que recebeu um item de pedido — usada para abater o item
     * quando o pedido sai da venda. Pizza de sabores casa pela mesma lista de
     * sabores (nunca foi mesclada); item comum casa pelo produto entre as
     * linhas sem sabores, como sempre foi.
     */
    public static function correspondenteAoItemPedido(int $vendaId, ItensPedido $itemPedido): ?self
    {
        $candidatas = static::where('item_venda_venda_id', $vendaId)
            ->where('item_venda_produto_id', $itemPedido->item_pedido_produto_id);

        if (! $itemPedido->ehMultiSabor()) {
            return $candidatas->whereNull('item_venda_sabores')->first();
        }

        $assinatura = static::assinaturaSabores($itemPedido->sabores());

        return $candidatas->whereNotNull('item_venda_sabores')
            ->get()
            ->first(fn (self $linha) => static::assinaturaSabores($linha->sabores()) === $assinatura);
    }

    /**
     * @param  array<int, array{produto_id: int, percentual: float}>  $sabores
     */
    private static function assinaturaSabores(array $sabores): string
    {
        return collect($sabores)
            ->map(fn (array $sabor) => $sabor['produto_id'].':'.round((float) $sabor['percentual'], 2))
            ->implode('|');
    }

    public function venda()
    {
        return $this->belongsTo(Venda::class, 'item_venda_venda_id');
    }

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'item_venda_produto_id');
    }

    public function usuarioRemoveu()
    {
        return $this->belongsTo(User::class, 'item_venda_usuario_removeu');
    }

    public function adicionaisItemVenda()
    {
        return $this->hasMany(AdicionaisItemVenda::class, 'aiv_item_venda_id');
    }
}
