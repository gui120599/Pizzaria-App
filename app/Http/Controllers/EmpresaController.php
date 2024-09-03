<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Http\Requests\StoreEmpresaRequest;
use App\Http\Requests\UpdateEmpresaRequest;

class EmpresaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $empresas = Empresa::all();
        return view('app.empresa.index', ['empresas' => $empresas]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('app.empresa.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEmpresaRequest $request)
    {
        $empresa = Empresa::create([
            'empresa_razao_social' => $request->input('empresa_razao_social'),
            'empresa_nome_fantasia' => $request->input('empresa_nome_fantasia') ? $request->input('empresa_nome_fantasia') : null,
            'empresa_cnpj' => $request->input('empresa_cnpj') ? str_replace([".", "-", " ","/"], "", $request->input('empresa_cnpj')) : null,
            'empresa_regime_tributario' => $request->input('empresa_regime_tributario'),
            'empresa_endereco_uf_estado' => $request->input('empresa_endereco_uf_estado'),
            'empresa_endereco_cidade_id_ibge' => $request->input('empresa_endereco_cidade_id_ibge'),
            'empresa_endereco_rua' => $request->input('empresa_endereco_rua'),
            'empresa_endereco_numero_endereco' => $request->input('empresa_endereco_numero_endereco'),
            'empresa_endereco_cep' => $request->input('empresa_endereco_cep'),
            'empresa_endereco_complemento_endereco' => $request->input('empresa_endereco_complemento_endereco'),
            'empresa_endereco_bairro' => $request->input('empresa_endereco_bairro'),
            'empresa_endereco' => $request->input('empresa_endereco'),
            'empresa_api_nfeio_conta_id' => $request->input('empresa_api_nfeio_conta_id'),
            'empresa_api_nfeio_company_id' => $request->input('empresa_api_nfeio_company_id'),
            'empresa_api_nfeio_apikey' => $request->input('empresa_api_nfeio_apikey'),
            'empresa_status' => $request->input('empresa_status', 'Active'),
        ]);

        $empresa->save();

        // Redireciona para a página da empresa recém-criada
        return redirect()->route('empresa')->with('success', 'Empresa criada com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Empresa $empresa)
    {
        return view('app.empresa.show', ['empresa' => $empresa]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Empresa $empresa)
    {
        return view('app.empresa.edit', ['empresa' => $empresa]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEmpresaRequest $request, Empresa $empresa)
    {
        $empresa->update([
            'empresa_razao_social' => $request->input('empresa_razao_social'),
            'empresa_nome_fantasia' => $request->input('empresa_nome_fantasia'),
            'empresa_cnpj' => $request->input('empresa_cnpj') ? str_replace([".", "-", " ","/"], "", $request->input('empresa_cnpj')) : null,
            'empresa_regime_tributario' => $request->input('empresa_regime_tributario'),
            'empresa_endereco_uf_estado' => $request->input('empresa_endereco_uf_estado'),
            'empresa_endereco_cidade_id_ibge' => $request->input('empresa_endereco_cidade_id_ibge'),
            'empresa_endereco_rua' => $request->input('empresa_endereco_rua'),
            'empresa_endereco_numero_endereco' => $request->input('empresa_endereco_numero_endereco'),
            'empresa_endereco_cep' => $request->input('empresa_endereco_cep'),
            'empresa_endereco_complemento_endereco' => $request->input('empresa_endereco_complemento_endereco'),
            'empresa_endereco_bairro' => $request->input('empresa_endereco_bairro'),
            'empresa_endereco' => $request->input('empresa_endereco'),
            'empresa_api_nfeio_conta_id' => $request->input('empresa_api_nfeio_conta_id'),
            'empresa_api_nfeio_company_id' => $request->input('empresa_api_nfeio_company_id'),
            'empresa_api_nfeio_apikey' => $request->input('empresa_api_nfeio_apikey'),
            'empresa_status' => $request->input('empresa_status', 'Active'),
        ]);

        // Redireciona para a página da empresa atualizada
        return redirect()->route('empresa')->with('success', 'Empresa atualizada com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Empresa $empresa, $id)
    {
        $empresa = Empresa::find($id);

        if (!$empresa) {
            return redirect()->route('empresa')->with('error', 'Empresa não encontrada!');
        }

        $empresa->delete();
        return redirect()->route('empresa')->with('success', 'Empresa inativada com sucesso!');
    }

    /**
     * Show form Inactives.
     */
    public function inactive()
    {
        $empresas_inativas = Empresa::onlyTrashed()->get();

        return view('app.empresa.inactive', ['empresas' => $empresas_inativas]);
    }

    /**
     * Active object.
     */
    public function active(Empresa $empresa, $id)
    {
        $empresa = Empresa::withTrashed()->find($id);

        if (!$empresa) {
            return redirect()->route('empresa')->with('error', 'Empresa não encontrada!');
        }

        $empresa->restore();
        return redirect()->route('empresa')->with('success', 'Empresa ativada com sucesso!');
    }
}
