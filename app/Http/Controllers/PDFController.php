<?php

namespace App\Http\Controllers;

use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use Illuminate\Http\Request;
use PDF;

class PDFController extends Controller
{
    public function pedidoPDF(Request $request)
    {
        $pedido_id = $request->id;
        $itensInseridoPedido = ItensPedido::with('produto') // Carregue o relacionamento 'produto'
            ->where('item_pedido_pedido_id', $pedido_id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();
        $pedido = Pedido::find($pedido_id);
        return view('pedidoPDF', ['itens_inserido_pedido' => $itensInseridoPedido, 'pedido' => $pedido]);
    }

    public function sessaoMesaPDF(Request $request)
    {
        $sessaoMesaId = $request->id;
        $sessaoMesa = SessaoMesa::find($sessaoMesaId);

        // Carregar itens de pedido com os pedidos relacionados
        $itensInseridoPedido = ItensPedido::whereHas('pedido', function ($query) use ($sessaoMesaId) {
            $query->where('pedido_sessao_mesa_id', $sessaoMesaId)->where('pedido_status','<>','CANCELADO');
        })->where('item_pedido_status', 'INSERIDO')->with('pedido')->get();

        $pedidos = Pedido::where('pedido_sessao_mesa_id',$sessaoMesaId)->where('pedido_status','<>','CANCELADO')->get();

        return view('sessaoMesaPDF', [
            'sessao_mesa' => $sessaoMesa,
            'itens_inserido_pedido' => $itensInseridoPedido,
            'pedidos' => $pedidos
        ]);
    }

    /*public function generatePDF1()
    {
        $data = ['title' => 'Welcome to Laravel PDF generation'];

        $pdf = PDF::loadView('myPDF', $data);

        // Define o caminho onde o PDF será salvo
        $path = storage_path('app/public/pdfs/invoice.pdf');

        // Salva o PDF no caminho especificado
        $pdf->save($path);

        // Retorna uma mensagem de sucesso ou redireciona conforme necessário
        return response()->json(['message' => 'PDF gerado com sucesso!', 'path' => $path]);
    }*/
}
