<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Models\NotaFiscal;
use App\Http\Requests\StoreNotaFiscalRequest;
use App\Http\Requests\UpdateNotaFiscalRequest;
use GuzzleHttp\Client;

class NotaFiscalController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

        // Inicializa o cliente HTTP do Guzzle
        $client = new Client();

        $empresa = Empresa::first();
        $companyId = $empresa->empresa_api_nfeio_company_id;
        $apiKey = $empresa->empresa_api_nfeio_apikey;
        $environment = $empresa->empresa_api_nfeio_ambiente;

        // URL da API com os parâmetros dinamicamente inseridos
        $url = "https://api.nfse.io/v2/companies/{$companyId}/consumerinvoices?environment={$environment}&q=status%3A%22Issued%22&limit=50";
        $url2 = "https://api.nfse.io/v2/companies/{$companyId}/consumerinvoices?environment={$environment}&q=status%3A%22Error%22&limit=50";

        try {
            // Faz a requisição GET para a API
            $response = $client->request('GET', $url, [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => $apiKey,
                ],
            ]);

            $response2 = $client->request('GET', $url2, [
                'headers' => [
                    'accept' => 'application/json',
                    'Authorization' => $apiKey,
                ],
            ]);

            // Decodifica o corpo da resposta JSON
            $body = $response->getBody();
            $statusCode = $response->getStatusCode();
            $content = $body->getContents();
            // Decodifica o corpo da resposta JSON
            $body2 = $response2->getBody();
            $statusCode2 = $response2->getStatusCode();
            $content2 = $body2->getContents();

            // Decodifica a string JSON dentro do campo "content"
            $data = json_decode($content, true);
            $data2 = json_decode($content2, true);

            // Verifica se a requisição foi bem-sucedida
            if ($response->getStatusCode() === 200) {
                // Retorna o conteúdo do PDF (ou salva, dependendo da sua necessidade)
                return view('app.nota_fiscal.index', ['data' => $data['consumerInvoices'], 'dataError' => $data2['consumerInvoices']]);
                //dd($data);
            }



            // Retorno em caso de falha
            return response()->json(['error' => 'Falha ao listar Notas Fiscais!'], $response->getStatusCode());
        } catch (\Exception $e) {
            // Tratamento de exceção caso algo dê errado
            //return response()->json(['error' => 'Erro ao se comunicar com a API: ' . $e->getMessage()], 500);
            return view('app.nota_fiscal.index')->with('error', 'Erro ao se comunicar com Api');
        }


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
    public function store(StoreNotaFiscalRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function Eventos($idInvoice)
    {
        try {
            // Busca a empresa e verifica se existe
            $empresa = Empresa::first();
            if (!$empresa) {
                return redirect()->route('app.nota_fiscal.index')->with('error', 'Empresa não cadastrada!');
            }

            // Inicializa o cliente HTTP do Guzzle
            $client = new Client();

            // Recupera credenciais da empresa
            $companyId = $empresa->empresa_api_nfeio_company_id;
            $apiKey = $empresa->empresa_api_nfeio_apikey;

            // Monta a URL da API
            $url = "https://api.nfse.io/v2/companies/{$companyId}/consumerinvoices/{$idInvoice}";

            // Faz a requisição GET para a API
            $response = $client->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => $apiKey,
                ],
            ]);

            // Decodifica a resposta
            $content = json_decode($response->getBody()->getContents(), true);

            // Verifica se a resposta tem o campo "events"
            if ($response->getStatusCode() === 200 && isset($content['lastEvents']['events'])) {
                return view('app.nota_fiscal.events', ['data' => $content['lastEvents']['events'],'venda_id' => $content['number']]);
            }

            return redirect()->route('nota_fiscal')->with('error', 'Nenhum evento encontrado!');
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return redirect()->route('nota_fiscal')->with('error', 'Erro ao se comunicar com a API: ' . $e->getMessage());
        } catch (\Exception $e) {
            return redirect()->route('nota_fiscal')->with('error', 'Erro inesperado: ' . $e->getMessage());
        }
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(NotaFiscal $notaFiscal)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateNotaFiscalRequest $request, NotaFiscal $notaFiscal)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(NotaFiscal $notaFiscal)
    {
        //
    }
}
