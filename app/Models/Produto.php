<?php

namespace App\Models;

use App\Services\PrecificadorService;
use App\Services\PrecoResolvido;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Produto extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'produtos';

    private ?PrecoResolvido $precoResolvidoCache = null;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'produto_descricao',
        'produto_exibe_categoria',
        'produto_preposicao',
        'produto_custo_medio',
        'produto_saldo_estoque',
        'produto_unidade_estoque',
        'produto_controla_lote',
        'produto_controla_marca',
        'produto_perecivel',
        'produto_ficha_rendimento',
        'produto_ordem',
        'produto_codimentacao',
        'produto_tipo',
        'produto_controla_estoque',
        'produto_modo_controle_estoque',
        'produto_lista_estoque_zerado',
        'produto_cardapio',
        'produto_codigo_NCM',
        'produto_codigo_CEST',
        'produto_codigo_EAN',
        'produto_codigo_beneficio_fiscal_uf',
        'produto_CFOP',
        'produto_CSOSN',
        'produto_categoria_id',
        'produto_plano_despesa_id',
        'produto_foto',
        'produto_unidade_comercial',
        'produto_preco_custo',
        'produto_valor_percentual_venda',
        'produto_preco_venda',
        'produto_venda_manual',
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
        'produto_controla_marca' => 'boolean',
        'produto_perecivel' => 'boolean',
        'produto_venda_manual' => 'boolean',
        'produto_lista_estoque_zerado' => 'boolean',
        'produto_modo_controle_estoque' => \App\Enums\EstoqueModoControleEnum::class,
        'produto_custo_medio' => 'decimal:4',
        'produto_saldo_estoque' => 'decimal:3',
        'produto_ficha_rendimento' => 'decimal:3',
    ];

    /**
     * Preço que o servidor cobrará por uma unidade deste produto agora.
     * O cardápio exibe exatamente isto — display e cobrança não podem divergir.
     */
    public function precoResolvido(): PrecoResolvido
    {
        return $this->precoResolvidoCache ??= app(PrecificadorService::class)->resolver($this);
    }

    /**
     * Política de sabores no cardápio. A disponibilidade (meia a meia / terços)
     * é sempre da categoria — inclusive para produto em promoção relâmpago. Uma
     * promoção que permite sabores pode apenas restringir o máximo abaixo do da
     * categoria; uma promoção "só inteira" não bloqueia mais a seleção como
     * sabor: a fração apenas volta ao preço normal (ver precoFracaoCardapio()).
     *
     * @return array{permite: bool, max: int}
     */
    public function politicaSaboresCardapio(): array
    {
        $catPermite = (bool) ($this->categoria->categoria_permite_sabores ?? false);
        $catMax = (int) ($this->categoria->categoria_max_sabores ?? 2);

        $promos = app(PrecificadorService::class)->promocoesVigentesDoProduto($this->id);

        // Promoção que permite sabores em TODAS as vigentes pode apertar o teto.
        if ($promos->isNotEmpty() && $promos->every(fn ($p) => $p->promocao_permite_sabores)) {
            return [
                'permite' => $catPermite,
                'max' => (int) $promos->map(fn ($p) => $p->maxSaboresEfetivo([$this]))->min(),
            ];
        }

        // Sem promoção, ou promoção "só inteira": a categoria manda.
        return [
            'permite' => $catPermite,
            'max' => $catMax,
        ];
    }

    public function permiteSaboresCardapio(): bool
    {
        return $this->politicaSaboresCardapio()['permite'];
    }

    public function maxSaboresCardapio(): int
    {
        return $this->politicaSaboresCardapio()['max'];
    }

    /**
     * A promoção relâmpago vigente deste produto vale só para a pizza inteira?
     * Verdadeiro quando existe promoção vigente e nem todas permitem sabores —
     * nesse caso a fração (meia/terço) sai pelo preço normal.
     */
    public function relampagoSoInteiraCardapio(): bool
    {
        $promos = app(PrecificadorService::class)->promocoesVigentesDoProduto($this->id);

        return $promos->isNotEmpty() && ! $promos->every(fn ($p) => $p->promocao_permite_sabores);
    }

    /**
     * Preço de uma unidade deste produto quando escolhido como fração de uma
     * pizza multi-sabor. Numa promoção "só inteira" a fração volta ao preço
     * normal (relâmpago não se aplica); nos demais casos segue o preço resolvido
     * da unidade inteira. Espelha o que o servidor cobra em ratearCombo().
     */
    public function precoFracaoCardapio(): float
    {
        if ($this->relampagoSoInteiraCardapio()) {
            return app(PrecificadorService::class)->resolver($this, considerarRelampago: false)->precoFinal();
        }

        return $this->precoResolvido()->precoFinal();
    }

    /**
     * Produtos cujo produto_preco_promocional está dentro da janela de datas.
     * Datas em branco = promoção permanente.
     */
    public function scopeComPromocaoDeProdutoVigente(Builder $query): Builder
    {
        $hoje = now()->toDateString();

        return $query->where('produto_preco_promocional', '>', 0)
            ->where(fn (Builder $q) => $q->whereNull('produto_data_inicio_promocao')
                ->orWhere('produto_data_inicio_promocao', '<=', $hoje))
            ->where(fn (Builder $q) => $q->whereNull('produto_data_final_promocao')
                ->orWhere('produto_data_final_promocao', '>=', $hoje));
    }

    /**
     * Produtos visíveis no cardápio público: precisa estar marcado pra
     * aparecer e, se controla estoque e o saldo zerou, só continua visível
     * se produto_lista_estoque_zerado permitir (ex.: item que ainda aceita
     * encomenda mesmo sem saldo no momento).
     */
    public function scopeVisivelCardapio(Builder $query): Builder
    {
        return $query->where('produto_cardapio', true)
            ->where(fn (Builder $q) => $q->where('produto_controla_estoque', false)
                ->orWhere('produto_saldo_estoque', '>', 0)
                ->orWhere('produto_lista_estoque_zerado', true));
    }

    /** Mesma regra de scopeVisivelCardapio(), pra checar uma instância já carregada. */
    public function visivelNoCardapio(): bool
    {
        if (! $this->produto_cardapio) {
            return false;
        }

        if (! $this->produto_controla_estoque) {
            return true;
        }

        return (float) $this->produto_saldo_estoque > 0 || (bool) $this->produto_lista_estoque_zerado;
    }

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

    /** Plano de despesas ao qual o custo deste produto pertence (classificação para a DRE). */
    public function planoDespesa()
    {
        return $this->belongsTo(PlanoDespesa::class, 'produto_plano_despesa_id');
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

    /**
     * Produto gera/consome registro em estoque_lotes: seja para rastrear
     * lote/validade (FEFO) ou apenas a marca (ex.: papel toalha, sem
     * validade). Nesse segundo caso o lote nasce sem código/validade e a
     * baixa vira FIFO (ordena por validade nula, depois id).
     */
    public function rastreiaLote(): bool
    {
        return (bool) $this->produto_controla_lote || (bool) $this->produto_controla_marca;
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
     * Verifica se este produto depende (direta ou indiretamente) do produto
     * informado, percorrendo a ficha técnica. Usado para barrar ciclo no
     * cadastro (ex.: impedir que a massa some como insumo do molho se o
     * molho já é, ele mesmo, insumo da massa).
     *
     * @param  array<int>  $visitados  ids já percorridos nesta checagem (proteção contra ciclo pré-existente)
     */
    public function dependeDe(int $produtoId, array $visitados = []): bool
    {
        if (in_array($this->id, $visitados, true)) {
            return false;
        }
        $visitados[] = $this->id;

        $itens = $this->relationLoaded('fichaItens')
            ? $this->fichaItens
            : $this->fichaItens()->with('insumo')->get();

        foreach ($itens as $item) {
            if ($item->fti_insumo_id === $produtoId) {
                return true;
            }

            if ($item->insumo && $item->insumo->dependeDe($produtoId, $visitados)) {
                return true;
            }
        }

        return false;
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
        $nomeArquivo = time().'.'.$foto->getClientOriginalExtension();
        $foto->storeAs('fotos_produtos', $nomeArquivo, 'public');
        $this->produto_foto = 'fotos_produtos/'.$nomeArquivo;
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
