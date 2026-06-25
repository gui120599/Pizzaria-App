<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Produto extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'produtos';
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'produto_descricao',
        'produto_exibe_categoria',
        'produto_preposicao',
        'produto_custo_medio',
        'produto_saldo_estoque',
        'produto_unidade_estoque',
        'produto_controla_lote',
        'produto_perecivel',
        'produto_ficha_rendimento',
        'produto_ordem',
        'produto_codimentacao',
        'produto_tipo',
        'produto_controla_estoque',
        'produto_cardapio',
        'produto_codigo_NCM',
        'produto_codigo_CEST',
        'produto_codigo_EAN',
        'produto_codigo_beneficio_fiscal_uf',
        'produto_CFOP',
        'produto_CSOSN',
        'produto_categoria_id',
        'produto_foto',
        'produto_unidade_comercial',
        'produto_preco_custo',
        'produto_valor_percentual_venda',
        'produto_preco_venda',
        'produto_valor_percentual_comissao',
        'produto_preco_comissao',
        'produto_preco_promocional',
        'produto_cod_origem_mercadoria',
        'produto_cod_tributacao_icms',
        'produto_valor_percentual_icms',
        'produto_valor_percentual_cofins',
        'produto_valor_percentual_pis',
        'produto_valor_percentual_reducao_icms',
        'produto_data_inicio_promocao',
        'produto_data_final_promocao',
        'produto_quantidade_minima',
        'produto_quantidade_maxima',
        'produto_qtd_vendas',
        'produto_destaque_mais_vendidos',
    ];

    protected $casts = [
        'produto_exibe_categoria' => 'boolean',
        'produto_controla_lote' => 'boolean',
        'produto_perecivel' => 'boolean',
        'produto_custo_medio' => 'decimal:4',
        'produto_saldo_estoque' => 'decimal:3',
        'produto_ficha_rendimento' => 'decimal:3',
    ];

    /**
     * Nome para exibição no cardápio. Quando o produto está marcado para
     * exibir a categoria, monta "Categoria [preposição] Descrição"
     * (ex.: "PASTEL DE FRANGO"). Caso contrário, retorna só a descrição.
     */
    public function nomeExibicao(): string
    {
        if (! $this->produto_exibe_categoria || ! $this->categoria) {
            return $this->produto_descricao;
        }

        // Usa a preposição do produto; se vazia, recorre à padrão da categoria.
        $preposicao = trim((string) ($this->produto_preposicao ?: $this->categoria->categoria_preposicao_padrao));

        return collect([
            $this->categoria->categoria_nome,
            $preposicao !== '' ? $preposicao : null,
            $this->produto_descricao,
        ])->filter()->implode(' ');
    }

    // Relacionamento com a tabela 'categorias'
    public function categoria()
    {
        return $this->belongsTo(Categoria::class, 'produto_categoria_id');
    }
    // Relacionamento com a tabela 'EntradaProdutos'
    public function mov_produto()
    {
        return $this->hasMany(MovimentacaoProduto::class, 'mov_produto_id');
    }

    // Lotes de estoque (controle físico/validade — baixa FEFO)
    public function lotes()
    {
        return $this->hasMany(EstoqueLote::class, 'lote_produto_id');
    }

    // De-para de fornecedores (código do fornecedor ↔ insumo, p/ NF e XML)
    public function fornecedores()
    {
        return $this->hasMany(FornecedorProduto::class, 'fp_produto_id');
    }

    // Itens da ficha técnica deste produto (componentes que ele consome)
    public function fichaItens()
    {
        return $this->hasMany(FichaTecnicaItem::class, 'fti_produto_id');
    }

    // Linhas de ficha onde este produto é usado como insumo de outros
    public function usadoComoInsumo()
    {
        return $this->hasMany(FichaTecnicaItem::class, 'fti_insumo_id');
    }

    public function temFichaTecnica(): bool
    {
        return $this->fichaItens()->exists();
    }

    /**
     * Custo unitário do produto.
     *
     * Para produtos com ficha técnica, calcula o custo a partir dos insumos
     * (recursivo — suporta semi-acabados/codimentação), dividido pelo rendimento.
     * Sem ficha, usa o custo médio (alimentado pelas compras via WAC).
     *
     * @param  array<int>  $visitados  ids já visitados (proteção contra ciclo)
     */
    public function custoUnitario(array $visitados = []): float
    {
        if (in_array($this->id, $visitados, true)) {
            return (float) $this->produto_custo_medio;
        }
        $visitados[] = $this->id;

        $itens = $this->relationLoaded('fichaItens')
            ? $this->fichaItens
            : $this->fichaItens()->with('insumo')->get();

        if ($itens->isEmpty()) {
            return (float) $this->produto_custo_medio;
        }

        $custoTotal = $itens->sum(fn (FichaTecnicaItem $item) => $item->custo($visitados));
        $rendimento = (float) $this->produto_ficha_rendimento ?: 1;

        return $custoTotal / $rendimento;
    }

    /** Custo total da ficha (para o rendimento configurado). */
    public function custoFicha(): float
    {
        return $this->custoUnitario() * ((float) $this->produto_ficha_rendimento ?: 1);
    }

    public function saveFoto($foto)
    {
        $nomeArquivo = time() . '.' . $foto->getClientOriginalExtension();
        $foto->storeAs('fotos_produtos', $nomeArquivo, 'public');
        $this->produto_foto = 'fotos_produtos/' . $nomeArquivo;
        $this->save();
    }

    public function getImagemUrl()
    {
        if ($this->produto_foto && Storage::disk('public')->exists($this->produto_foto)) {
            return Storage::disk('public')->url($this->produto_foto);
        }

        return asset('Sem Imagem.png');
    }

    public function saldo()
    {
        return $this->mov_produto()
            ->selectRaw('SUM(CASE WHEN mov_tipo = "ENTRADA" THEN mov_quantidade ELSE -mov_quantidade END) as saldo')
            ->groupBy('mov_produto_id');
    }

    public function pedidos()
    {
        return $this->belongsToMany(Pedido::class, 'itens_pedidos', 'item_pedido_produto_id', 'item_pedido_pedido_id')
            ->withPivot('quantidade', 'valor', 'observacao', 'status', 'usuario_removeu');
    }

    public function item_pedido_produto_id()
    {
        return $this->hasMany(ItensPedido::class, 'item_pedido_produto_id');
    }

    public function ap_produto_id()
    {
        return $this->hasMany(AdicionaisProduto::class, 'ap_produto_id');
    }

    public function balancos()
    {
        return $this->hasMany(MovimentacaoBalanco::class, 'mbal_produto_id');
    }
}
