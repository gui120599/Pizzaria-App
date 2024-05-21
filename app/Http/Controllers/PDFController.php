<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use Illuminate\Http\Request;
use PDF;

class PDFController extends Controller
{
    public function generatePDF()
    {
        $data = ['title' => 'Welcome to Laravel PDF generation'];

        // Carrega a view 'myPDF' com os dados e converte para PDF
        //$pdf = PDF::loadView('myPDF', $data);
        $pedido_id = 12;
        $itensInseridoPedido = ItensPedido::with('produto') // Carregue o relacionamento 'produto'
            ->where('item_pedido_pedido_id', $pedido_id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();
        $pedido = Pedido::find($pedido_id);

        // Retorna o PDF para download
        //return $pdf->stream('invoice.pdf');
        return view('myPDF', ['itens_inserido_pedido' => $itensInseridoPedido, 'pedido' => $pedido]);
    }

    public function sessaoMesaPDF(Request $request)
    {

        // Carrega a view 'myPDF' com os dados e converte para PDF
        //$pdf = PDF::loadView('myPDF', $data);
        $sessaoMesaId = $request->id;
        $sessaoMesa = SessaoMesa::find($sessaoMesaId);
        $itensInseridoPedido = $itensPedidos = ItensPedido::whereHas('pedido', function ($query) use ($sessaoMesaId) {
            $query->where('pedido_sessao_mesa_id', $sessaoMesaId);
        })->where('item_pedido_status', 'INSERIDO')->get();

        // Retorna o PDF para download
        //return $pdf->stream('invoice.pdf');
        return view('sessaoMesaPDF', ['sessao_mesa' => $sessaoMesa, 'itens_inserido_pedido' => $itensInseridoPedido]);
    }
    public function generatePDF1()
    {
        $data = ['title' => 'Welcome to Laravel PDF generation'];

        $pdf = PDF::loadView('myPDF', $data);

        // Define o caminho onde o PDF será salvo
        $path = storage_path('app/public/pdfs/invoice.pdf');

        // Salva o PDF no caminho especificado
        $pdf->save($path);

        // Retorna uma mensagem de sucesso ou redireciona conforme necessário
        return response()->json(['message' => 'PDF gerado com sucesso!', 'path' => $path]);
    }
}
