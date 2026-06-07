<?php

namespace App\Http\Controllers;

use App\Models\AdicionaisProduto;
use App\Models\Adicional;
use App\Models\Categoria;
use App\Models\Produto;
use App\Http\Requests\StoreProdutoRequest;
use App\Http\Requests\UpdateProdutoRequest;
use Request;

class ProdutoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Ordena as categorias: primeiro as que começam com 'P', depois as demais em ordem alfabética
        $categorias = Categoria::orderByRaw("
            CASE 
                WHEN categoria_nome LIKE 'Pi%' THEN 0 
                ELSE 1 
            END, categoria_nome
        ")->with('produtos')->get();

        $adicionais = Adicional::all();

        return view('app.produto.index', ['categorias' => $categorias, 'adicionais' => $adicionais]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('app.produto.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProdutoRequest $request)
    {

        $produto = Produto::create([
            'produto_descricao' => $request->input('produto_descricao'),
            'produto_codimentacao' => $request->input('produto_codimentacao'),
            'produto_cardapio' => $request->has('produto_cardapio'),
            'produto_codigo_NCM' => $request->input('produto_codigo_NCM'),
            'produto_codigo_CEST' => $request->input('produto_codigo_CEST'),
            'produto_codigo_EAN' => $request->input('produto_codigo_EAN'),
            'produto_codigo_beneficio_fiscal_uf' => $request->input('produto_codigo_beneficio_fiscal_uf'),
            'produto_CFOP' => $request->input('produto_CFOP'),
            'produto_CSOSN' => $request->input('produto_CSOSN'),
            'produto_categoria_id' => $request->input('produto_categoria_id'),
            'produto_unidade_comercial' => $request->input('produto_unidade_comercial'),
            'produto_preco_custo' => $request->input('produto_preco_custo') ? str_replace(',', '.', $request->input('produto_preco_custo')) : '0.00',
            'produto_valor_percentual_venda' => $request->input('produto_valor_percentual_venda') ? str_replace(',', '.', $request->input('produto_valor_percentual_venda')) : '0.00',
            'produto_preco_venda' => $request->input('produto_preco_venda') ? str_replace(',', '.', $request->input('produto_preco_venda')) : '0.00',
            'produto_valor_percentual_comissao' => $request->input('produto_valor_percentual_comissao') ? str_replace(',', '.', $request->input('produto_valor_percentual_comissao')) : '0.00',
            'produto_preco_comissao' => $request->input('produto_preco_comissao') ? str_replace(',', '.', $request->input('produto_preco_comissao')) : '0.00',
            'produto_preco_promocional' => $request->input('produto_preco_promocional') ? str_replace(',', '.', $request->input('produto_preco_promocional')) : '0.00',
            'produto_cod_origem_mercadoria' => $request->input('produto_cod_origem_mercadoria'),
            'produto_cod_tributacao_icms' => $request->input('produto_cod_tributacao_icms'),
            'produto_valor_percentual_icms' => $request->input('produto_valor_percentual_icms') ? str_replace(',', '.', $request->input('produto_valor_percentual_icms')) : '0.00',
            'produto_valor_percentual_cofins' => $request->input('produto_valor_percentual_cofins') ? str_replace(',', '.', $request->input('produto_valor_percentual_cofins')) : '0.00',
            'produto_valor_percentual_pis' => $request->input('produto_valor_percentual_pis') ? str_replace(',', '.', $request->input('produto_valor_percentual_pis')) : '0.00',
            'produto_valor_percentual_reducao_icms' => $request->input('produto_valor_percentual_reducao_icms') ? str_replace(',', '.', $request->input('produto_valor_percentual_reducao_icms')) : '0.00',
            'produto_data_inicio_promocao' => $request->input('produto_data_inicio_promocao'),
            'produto_data_final_promocao' => $request->input('produto_data_final_promocao'),
            'produto_quantidade_minima' => $request->input('produto_quantidade_minima'),
            'produto_quantidade_maxima' => $request->input('produto_quantidade_maxima'),
        ]);

        // Upload da foto, se presente
        if ($request->hasFile('produto_foto')) {
            $foto = $request->file('produto_foto');
            $produto->saveFoto($foto);
        }


        $produto->save();

        // Redireciona para a página do produto recém-criado
        return redirect()->route('produto')->with('success', 'Produto criado com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Produto $produto)
    {
        $top10Ids = Produto::where('produto_destaque_mais_vendidos', true)
            ->where('produto_qtd_vendas', '>', 0)
            ->orderByDesc('produto_qtd_vendas')
            ->limit(10)
            ->pluck('id')
            ->all();

        return view('app.produto.show', [
            'produto' => $produto,
            'ehMaisVendido' => in_array($produto->id, $top10Ids),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Produto $produto)
    {
        $categorias = Categoria::all();
        $adicionais = Adicional::all();
        $produtoAdicionais = AdicionaisProduto::where('ap_produto_id', $produto->id)
            ->pluck('ap_adicional_id')
            ->toArray();

        return view('app.produto.edit', [
            'produto' => $produto,
            'categorias' => $categorias,
            'adicionais' => $adicionais,
            'produtoAdicionais' => $produtoAdicionais,
        ]);
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProdutoRequest $request, Produto $produto)
    {
        $produto->update([
            'produto_descricao' => $request->input('produto_descricao'),
            'produto_codimentacao' => $request->input('produto_codimentacao'),
            'produto_cardapio' => $request->has('produto_cardapio'),
            'produto_codigo_NCM' => $request->input('produto_codigo_NCM'),
            'produto_codigo_CEST' => $request->input('produto_codigo_CEST'),
            'produto_codigo_EAN' => $request->input('produto_codigo_EAN'),
            'produto_codigo_beneficio_fiscal_uf' => $request->input('produto_codigo_beneficio_fiscal_uf'),
            'produto_CFOP' => $request->input('produto_CFOP'),
            'produto_CSOSN' => $request->input('produto_CSOSN'),
            'produto_categoria_id' => $request->input('produto_categoria_id'),
            'produto_unidade_comercial' => $request->input('produto_unidade_comercial'),
            'produto_preco_custo' => $request->input('produto_preco_custo') ? str_replace(',', '.', $request->input('produto_preco_custo')) : '0.00',
            'produto_valor_percentual_venda' => $request->input('produto_valor_percentual_venda') ? str_replace(',', '.', $request->input('produto_valor_percentual_venda')) : '0.00',
            'produto_preco_venda' => $request->input('produto_preco_venda') ? str_replace(',', '.', $request->input('produto_preco_venda')) : '0.00',
            'produto_valor_percentual_comissao' => $request->input('produto_valor_percentual_comissao') ? str_replace(',', '.', $request->input('produto_valor_percentual_comissao')) : '0.00',
            'produto_preco_comissao' => $request->input('produto_preco_comissao') ? str_replace(',', '.', $request->input('produto_preco_comissao')) : '0.00',
            'produto_preco_promocional' => $request->input('produto_preco_promocional') ? str_replace(',', '.', $request->input('produto_preco_promocional')) : '0.00',
            'produto_cod_origem_mercadoria' => $request->input('produto_cod_origem_mercadoria'),
            'produto_cod_tributacao_icms' => $request->input('produto_cod_tributacao_icms'),
            'produto_valor_percentual_icms' => $request->input('produto_valor_percentual_icms') ? str_replace(',', '.', $request->input('produto_valor_percentual_icms')) : '0.00',
            'produto_valor_percentual_cofins' => $request->input('produto_valor_percentual_cofins') ? str_replace(',', '.', $request->input('produto_valor_percentual_cofins')) : '0.00',
            'produto_valor_percentual_pis' => $request->input('produto_valor_percentual_pis') ? str_replace(',', '.', $request->input('produto_valor_percentual_pis')) : '0.00',
            'produto_valor_percentual_reducao_icms' => $request->input('produto_valor_percentual_reducao_icms') ? str_replace(',', '.', $request->input('produto_valor_percentual_reducao_icms')) : '0.00',
            'produto_data_inicio_promocao' => $request->input('produto_data_inicio_promocao'),
            'produto_data_final_promocao' => $request->input('produto_data_final_promocao'),
            'produto_quantidade_minima' => $request->input('produto_quantidade_minima'),
            'produto_quantidade_maxima' => $request->input('produto_quantidade_maxima'),
        ]);

        // Upload da nova foto, se presente
        if ($request->hasFile('produto_foto')) {
            $foto = $request->file('produto_foto');
            $produto->saveFoto($foto);
        }

        // Redireciona para a página do produto atualizado
        return redirect()->route('produto')->with('success', 'Produto atualizado com sucesso!');
        //dd($produto);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Produto $produto, $id)
    {
        $produto = Produto::find($id);

        if (!$produto) {
            return redirect('/Produto')->with('error', 'Produto não encontrado!');
        }

        $produto->delete();

        return redirect('/Produto')->with('success', 'Produto Inativado com sucesso!');
    }

    /**
     * Show form Inactives.
     */
    public function inactive()
    {
        $produtos_inativos = Produto::onlyTrashed()->get();

        return view('app.produto.inactive', ['produtos' => $produtos_inativos]);
    }

    /**
     * Active object.
     */
    public function active(Produto $produto, $id)
    {
        $produto = Produto::withTrashed()->find($id);

        if (!$produto) {
            return redirect('/Produto')->with('error', 'Produto não encontrado!');
        }

        $produto->restore();

        return redirect('/Produto')->with('success', 'Produto Ativado com sucesso!');
    }

}
