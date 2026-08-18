<?php

namespace App\Http\Controllers;

use App\Exceptions\NfeIoException;
use App\Models\Venda;
use App\Services\NfeIoService;

/**
 * Integração de emissão de NFC-e via nfse.io, extraída do antigo
 * VendaController (que concentrava tanto o fluxo operacional do PDV quanto
 * essa integração fiscal). A montagem do payload e as chamadas HTTP em si
 * moraram para App\Services\NfeIoService (client único, testável via
 * Http::fake()) — este controller só atende as rotas legadas
 * (resources/views/app/sessao_caixa/vendas.blade.php e nota_fiscal/*).
 */
class NfeVendaController extends Controller
{
    public function __construct(private readonly NfeIoService $nfeIoService) {}

    public function enviarNfe($vendaId)
    {
        $venda = Venda::findOrFail($vendaId);

        try {
            $this->nfeIoService->emitir($venda);
        } catch (NfeIoException $e) {
            return redirect()->route('sessao_caixa.vendas', ['sessao_caixa' => $venda->venda_sessao_caixa_id])
                ->with('error', $e->getMessage());
        }

        // Emissão é assíncrona: a consulta imediata de status é só uma
        // tentativa de "adiantar" o resultado — se falhar, não desfaz o
        // enfileiramento que já deu certo (o webhook/consulta posterior
        // ainda vai atualizar o status normalmente).
        try {
            $this->nfeIoService->sincronizarStatusLocal($venda);
        } catch (NfeIoException) {
            // ignorado de propósito
        }

        return redirect()->route('sessao_caixa.vendas', ['sessao_caixa' => $venda->venda_sessao_caixa_id])
            ->with('success', 'NFC-E enviada com sucesso! Verifique em Notas Fiscais se a emissão foi confirmada.');
    }

    public function jsonNFE($vendaId)
    {
        $venda = Venda::findOrFail($vendaId);

        return response()->json($this->nfeIoService->payloadPreview($venda));
    }

    public function removerIdNfe($vendaId, $idNfe)
    {
        $venda = Venda::where('id', $vendaId)->where('venda_id_nfe', $idNfe)->first();

        if ($venda) {
            $venda->update(['venda_id_nfe' => null]);

            return redirect()->route('nota_fiscal')->with('success', 'Id da NFE removido com sucesso da venda!');
        }

        return redirect()->route('nota_fiscal')->with('error', 'Venda não encontrada!');
    }

    public function buscarNFE(Venda $venda)
    {
        try {
            $data = $this->nfeIoService->consultarStatus($venda->venda_id_nfe);

            return response()->json(['statusCode' => 200, 'data' => $data]);
        } catch (NfeIoException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function imprimirNFE(string $id_nfe)
    {
        try {
            $uri = $this->nfeIoService->consultarPdfUrl($id_nfe);

            return view('nfePDF', ['data' => ['uri' => $uri]]);
        } catch (NfeIoException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
