<?php

namespace App\Models;

use App\Models\Concerns\TemRespostas;
use App\Models\Concerns\TemSabores;
use App\Services\PrecificadorService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItensPedido extends Model
{
    use HasFactory;
    use TemRespostas;
    use TemSabores;

    protected $fillable = [
        'item_pedido_produto_id',
        'item_pedido_promocao_id',
        'item_pedido_promocao_adicional_regra_id',
        'item_pedido_promocao_adicional_oferta_id',
        'item_pedido_origem_id',
        'item_pedido_pedido_id',
        'item_pedido_cliente_id',
        'item_pedido_venda_id',
        'item_pedido_item_venda_id',
        'item_pedido_quantidade',
        'item_pedido_valor_unitario',
        'item_pedido_desconto',
        'item_pedido_desconto_unitario',
        'item_pedido_valor_adicionais',
        'item_pedido_valor',
        'item_pedido_observacao',
        'item_pedido_sabores',
        'item_pedido_respostas',
        'item_pedido_status',
        'item_pedido_usuario_removeu',
    ];

    protected function casts(): array
    {
        return [
            'item_pedido_sabores' => 'array',
            'item_pedido_respostas' => 'array',
        ];
    }

    protected function colunaSabores(): string
    {
        return 'item_pedido_sabores';
    }

    protected function colunaRespostas(): string
    {
        return 'item_pedido_respostas';
    }

    protected function colunaProduto(): string
    {
        return 'item_pedido_produto_id';
    }

    protected function colunaQuantidade(): string
    {
        return 'item_pedido_quantidade';
    }

    /**
     * Item montado a partir do pivô Pedido::produtos*() (telas legadas), onde
     * só há o Produto com o pivô cru — o JSON de sabores chega como string.
     * Serve para reaproveitar <x-item-nome> nessas telas.
     */
    public static function doPivot(Produto $produto): static
    {
        $sabores = $produto->pivot?->item_pedido_sabores;

        $item = new static([
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_sabores' => is_string($sabores) ? json_decode($sabores, true) : $sabores,
        ]);

        return $item->setRelation('produto', $produto);
    }

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'item_pedido_produto_id');
    }

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'item_pedido_pedido_id');
    }

    public function promocao()
    {
        return $this->belongsTo(PromocaoRelampago::class, 'item_pedido_promocao_id');
    }

    /** Regra de promoção adicional que originou este item (linha da oferta). */
    public function promocaoAdicionalRegra()
    {
        return $this->belongsTo(PromocaoAdicionalRegra::class, 'item_pedido_promocao_adicional_regra_id');
    }

    /** Opção de produto ofertado escolhida, quando este item é a linha da oferta. */
    public function promocaoAdicionalOferta()
    {
        return $this->belongsTo(PromocaoAdicionalOferta::class, 'item_pedido_promocao_adicional_oferta_id');
    }

    /** Item-gatilho que originou este item, quando este é a oferta (ex.: brotinho → pizza). */
    public function itemOrigem()
    {
        return $this->belongsTo(ItensPedido::class, 'item_pedido_origem_id');
    }

    /** Itens de oferta gerados a partir deste item ser o gatilho. */
    public function itensOferta()
    {
        return $this->hasMany(ItensPedido::class, 'item_pedido_origem_id');
    }

    public function usuarioRemoveu()
    {
        return $this->belongsTo(User::class, 'item_pedido_usuario_removeu');
    }

    public function adicionaisItemPedido()
    {
        return $this->hasMany(AdicionaisItemPedido::class, 'aip_item_pedido_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'item_pedido_cliente_id');
    }

    public function venda()
    {
        return $this->belongsTo(Venda::class, 'item_pedido_venda_id');
    }

    /** Linha da venda em que este item entrou (nula para lançamentos antigos). */
    public function itemVenda()
    {
        return $this->belongsTo(ItensVenda::class, 'item_pedido_item_venda_id');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Regra de negócio do valor do item (fonte da verdade)
    //
    //   item_pedido_valor_unitario = max(preco_venda, preco_promocional)
    //   desconto unitário          = (promo > 0 && promo < venda) ? (venda - promo) : 0
    //   item_pedido_desconto       = desconto_unitário * quantidade
    //   item_pedido_valor          = (quantidade * valor_unitário) - desconto + adicionais   [LÍQUIDO]
    //
    // O preço é sempre resolvido pelo servidor via PrecificadorService — nunca
    // aceito do cliente. Item nascido de promoção relâmpago tem o preço
    // congelado e não é reprecificado (ver recalcularValores).
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Deriva o valor unitário base e o desconto unitário de um produto.
     *
     * Não considera promoção relâmpago: este método serve à reprecificação de
     * itens já gravados, e uma promoção criada depois não pode alcançar um item
     * antigo. Para precificar um item novo, use PrecificadorService::resolver().
     *
     * @return array{valor_unitario: float, desconto_unitario: float}
     */
    public static function precoUnitario(Produto $produto): array
    {
        $preco = app(PrecificadorService::class)->resolver($produto, considerarRelampago: false, considerarPromoAdicional: false);

        return [
            'valor_unitario' => $preco->valorUnitario,
            'desconto_unitario' => $preco->descontoUnitario,
        ];
    }

    /**
     * Calcula o valor líquido de uma linha de item segundo a regra de negócio.
     *
     * @param  float  $valorUnitario  Valor unitário base (max venda/promo)
     * @param  float  $descontoUnitario  Desconto por unidade
     * @param  float  $valorAdicionais  Total de adicionais da linha
     * @return array{quantidade: float, valor_unitario: float, desconto: float, adicionais: float, valor: float}
     */
    public static function calcularLinha(
        float $quantidade,
        float $valorUnitario,
        float $descontoUnitario,
        float $valorAdicionais = 0.0
    ): array {
        $desconto = round($descontoUnitario * $quantidade, 2);
        $valor = round(($valorUnitario * $quantidade) - $desconto + $valorAdicionais, 2);

        return [
            'quantidade' => $quantidade,
            'valor_unitario' => round($valorUnitario, 2),
            'desconto' => $desconto,
            'adicionais' => round($valorAdicionais, 2),
            'valor' => $valor,
        ];
    }

    /**
     * Quantas vezes cada adicional é cobrado numa linha: uma por unidade do
     * item. Fração (meia porção) ainda leva o adicional inteiro, como no
     * lançamento legado (ItensPedidoController::AtualizarQtdValor).
     */
    public static function quantidadeDosAdicionais(float $quantidadeItem): float
    {
        return max(1.0, $quantidadeItem);
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

        $quantidade = (float) $this->item_pedido_quantidade;
        // Sem valor informado: os adicionais gravados mais as respostas das
        // perguntas, ambos por unidade do item.
        $adicionais = $valorAdicionais ?? (float) $this->adicionaisItemPedido()->sum('aip_valor_total')
            + round($this->valorUnitarioRespostas() * static::quantidadeDosAdicionais($quantidade), 2);

        if ($this->item_pedido_promocao_id || $this->item_pedido_promocao_adicional_regra_id || $this->ehMultiSabor()) {
            // Pizza de vários sabores também tem o preço congelado: o unitário é
            // a média dos sabores, não o preço do produto da linha (1º sabor).
            // Preço congelado no momento da venda. Reprecificar aqui faria o item
            // perder a promoção quando ela expirasse, ou herdar uma promoção nova.
            // Cobre tanto o gatilho com preço override quanto a linha da oferta
            // (cujo valor nunca vem de PrecificadorService, só de par_valor_adicional).
            $valorUnitario = (float) $this->item_pedido_valor_unitario;
            $descontoUnitario = (float) ($this->item_pedido_desconto_unitario ?? 0);
        } else {
            $precos = static::precoUnitario($produto);
            $valorUnitario = $precos['valor_unitario'];
            $descontoUnitario = $precos['desconto_unitario'];
        }

        $linha = static::calcularLinha(
            $quantidade,
            $valorUnitario,
            $descontoUnitario,
            $adicionais
        );

        $this->item_pedido_valor_unitario = $linha['valor_unitario'];
        $this->item_pedido_desconto = $linha['desconto'];
        $this->item_pedido_valor_adicionais = $linha['adicionais'];
        $this->item_pedido_valor = $linha['valor'];

        return $this;
    }
}
