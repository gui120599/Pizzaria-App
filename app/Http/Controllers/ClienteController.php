<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Http\Requests\StoreClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use Carbon\Carbon;

class ClienteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Cliente $cliente)
    {
        $clientes = $cliente::all();
        return view('app.cliente.index', ['clientes' => $clientes]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreClienteRequest $request)
    {


        // Criar um novo cliente com base nos dados recebidos
        // Formatar a data para o formato correto
        $clienteData = $request->all();
        // Formatar a data de nascimento se estiver presente e não for Pessoa Jurídica
        if ($request->input('cliente_tipo') !== 'Jurídica' && isset($clienteData['cliente_data_nascimento'])) {
            $clienteData['cliente_data_nascimento'] = Carbon::createFromFormat('d/m/Y', $clienteData['cliente_data_nascimento']);
        }
        $clienteData['cliente_cpf'] = $clienteData['cliente_cpf'] ? str_replace([".", "-", " "], "", $clienteData['cliente_cpf']) : null;
        $clienteData['cliente_cnpj'] = $clienteData['cliente_cnpj'] ? str_replace([".", "-", " "], "", $clienteData['cliente_cnpj']) : null;
        $clienteData['cliente_cep'] = $clienteData['cliente_cep'] ? str_replace("-", "", $clienteData['cliente_cep']) : null;
        $clienteData['cliente_celular'] = $clienteData['cliente_celular'] ? str_replace(["(", ")", "-", " "], "", $clienteData['cliente_celular']) : null;
        // Criar um novo cliente
        $cliente = new Cliente($clienteData);

        // Salvar a foto se presente
        if ($request->hasFile('cliente_foto')) {
            $foto = $request->file('cliente_foto');
            $cliente->saveFoto($foto);
        }

        // Salvar o cliente no banco de dados
        $cliente->save();


        // Redirecionar ou retornar a resposta desejada
        return redirect()->route('cliente')->with('success', 'Cliente criado com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(Cliente $cliente)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Cliente $cliente)
    {
        return view('app.cliente.edit',["cliente" => $cliente]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClienteRequest $request, Cliente $cliente)
    {

        // Obter todos os dados do request
        $clienteData = $request->all();

        // Formatar a data de nascimento se presente e se não for Pessoa Jurídica
        if ($request->input('cliente_tipo') !== 'Jurídica' && isset($clienteData['cliente_data_nascimento'])) {
            $clienteData['cliente_data_nascimento'] = Carbon::createFromFormat('d/m/Y', $clienteData['cliente_data_nascimento']);
        }

        // Limpar e formatar os campos necessários
        $clienteData['cliente_cpf'] = $clienteData['cliente_cpf'] ? str_replace([".", "-", " "], "", $clienteData['cliente_cpf']) : null;
        $clienteData['cliente_cnpj'] = $clienteData['cliente_cnpj'] ? str_replace([".", "-", " "], "", $clienteData['cliente_cnpj']) : null;
        $clienteData['cliente_cep'] = $clienteData['cliente_cep'] ? str_replace("-", "", $clienteData['cliente_cep']) : null;
        $clienteData['cliente_celular'] = $clienteData['cliente_celular'] ? str_replace(["(", ")", "-", " "], "", $clienteData['cliente_celular']) : null;

        // Atualizar os dados do cliente
        $cliente->fill($clienteData);

        // Atualizar a foto se presente
        if ($request->hasFile('cliente_foto')) {
            $foto = $request->file('cliente_foto');
            $cliente->saveFoto($foto);
        }

        // Salvar as mudanças no banco de dados
        $cliente->save();

        // Redirecionar ou retornar a resposta desejada
        return redirect()->route('cliente')->with('success', 'Cliente atualizado com sucesso!');

    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Cliente $cliente)
    {
        //
    }
}
