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
        $clienteData = $request->all();

        // Formatar a data de nascimento se estiver presente e for pessoa física
        if ($request->input('cliente_tipo') !== 'Jurídica' && isset($clienteData['cliente_data_nascimento'])) {
            $clienteData['cliente_data_nascimento'] = Carbon::createFromFormat('d/m/Y', $clienteData['cliente_data_nascimento'])->toDateString();
        }

        // Criar novo cliente
        $cliente = new Cliente($clienteData);
        //dd($cliente);

        // Salvar a foto se presente
        if ($request->hasFile('cliente_foto')) {
            $foto = $request->file('cliente_foto');
            $cliente->saveFoto($foto);
        }

        // Salvar no banco
        $cliente->save();

        return redirect()->route('cliente')->with('success', 'Cliente criado com sucesso!');
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Cliente $cliente)
    {
        return view('app.cliente.edit', ["cliente" => $cliente]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClienteRequest $request, Cliente $cliente)
    {

        // Obter todos os dados do request
        $clienteData = $request->all();
        //dd($request);

        // Formatar a data de nascimento se presente e se não for Pessoa Jurídica
        if ($request->input('cliente_tipo') !== 'Jurídica' && isset($clienteData['cliente_data_nascimento'])) {
            $clienteData['cliente_data_nascimento'] = Carbon::createFromFormat('d/m/Y', $clienteData['cliente_data_nascimento'])->toDateString();
        }
        else{
            $clienteData['cliente_data_nascimento'] = null;
        }

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
    public function destroy(Cliente $cliente, $id)
    {
        $cliente = Cliente::find($id);

        if (!$cliente) {
            return redirect('/Cliente')->with('error', 'Cliente não encontrado!');
        }

        $cliente->delete();

        return redirect('/Cliente')->with('success', 'Cliente inativado!');
    }
}
