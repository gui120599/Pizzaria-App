<?php

namespace App\Http\Controllers;

use App\Enums\PedidoOrigemEnum;
use App\Models\Cliente;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CardapioCheckoutController extends Controller
{
    public function lookupCliente(Request $request)
    {
        $telefone = preg_replace('/\D/', '', $request->string('telefone'));

        if (strlen($telefone) < 8) {
            return response()->json(['encontrado' => false]);
        }

        $cliente = Cliente::where('cliente_celular', 'like', "%{$telefone}%")->first();

        if (! $cliente) {
            return response()->json(['encontrado' => false]);
        }

        $endereco = collect([
            $cliente->cliente_endereco,
            $cliente->cliente_numero_endereco,
            $cliente->cliente_bairro,
        ])->filter()->implode(', ');

        return response()->json([
            'encontrado'  => true,
            'cliente_id'  => $cliente->id,
            'nome'        => $cliente->cliente_nome,
            'endereco'    => $endereco,
        ]);
    }

    public function checkout(Request $request)
    {
        if (! HorarioFuncionamento::estaAberto()) {
            $proximo = HorarioFuncionamento::proximoHorario();
            $msg     = 'Não estamos aceitando pedidos no momento.';
            if ($proximo) {
                $msg .= ' Voltamos ' . $proximo . '.';
            }
            return response()->json(['message' => $msg], 422);
        }

        $request->validate([
            'nome'             => 'required|string|max:255',
            'telefone'         => 'required|string|min:8',
            'opcao_entrega_id' => 'required|integer|exists:opcoes_entregas,id',
            'pagamento_nome'   => 'required|string|max:100',
            'troco_para'       => 'nullable|numeric|min:0',
            'itens'            => 'required|array|min:1',
            'itens.*.id'             => 'required|integer|exists:produtos,id',
            'itens.*.qty'            => 'required|integer|min:1',
            'itens.*.preco'          => 'required|numeric|min:0',
            'itens.*.nome'           => 'nullable|string|max:500',
            'itens.*.preco_original'      => 'nullable|numeric|min:0',
            'itens.*.sabores'             => 'nullable|array|min:2|max:3',
            'itens.*.sabores.*.id'        => 'required|integer|exists:produtos,id',
            'itens.*.sabores.*.preco'     => 'required|numeric|min:0',
            'itens.*.sabores.*.precoOriginal' => 'nullable|numeric|min:0',
        ]);

        // Valida reCAPTCHA apenas se as chaves estiverem configuradas
        $secret = config('services.recaptcha.secret');
        if ($secret && $request->filled('recaptcha_token')) {
            $resp = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => $secret,
                'response' => $request->recaptcha_token,
                'remoteip' => $request->ip(),
            ]);
            if (! ($resp->json('success'))) {
                return response()->json(['message' => 'Verificação de segurança falhou. Tente novamente.'], 422);
            }
        }

        // Busca ou cria o cliente
        $telefone = preg_replace('/\D/', '', $request->telefone);
        $cliente  = Cliente::where('cliente_celular', 'like', "%{$telefone}%")->first();

        if ($cliente) {
            $cliente->update(['cliente_nome' => $request->nome]);
            if ($request->filled('endereco')) {
                $cliente->update(['cliente_endereco' => $request->endereco]);
            }
        } else {
            $cliente = Cliente::create([
                'cliente_nome'     => $request->nome,
                'cliente_celular'  => $telefone,
                'cliente_endereco' => $request->endereco ?? null,
                'cliente_tipo'     => 'Física',
            ]);
        }

        // Monta descrição do pagamento
        $descricaoPagamento = $request->pagamento_nome;
        $observacaoPagamento = null;
        if ($request->filled('troco_para') && $request->troco_para > 0) {
            $observacaoPagamento = 'Troco para R$ ' . number_format($request->troco_para, 2, ',', '.');
        }

        // Monta as linhas de itens já com o valor LÍQUIDO (desconto embutido).
        // Para sabores (meia/terço) usa distribuição em centavos para evitar
        // perda de arredondamento com quantidades fracionadas (ex.: 1/3 = 0,3333).
        $itens  = collect($request->itens);
        $linhas = [];

        foreach ($itens as $item) {
            $qty           = (int) $item['qty'];
            $precoOriginal = max((float) ($item['preco_original'] ?? 0), (float) $item['preco']);
            $descontoUnit  = max(0.0, $precoOriginal - (float) $item['preco']);
            $sabores       = $item['sabores'] ?? null;
            $observacao    = $item['observacao'] ?? null;

            if (! empty($sabores) && count($sabores) > 1) {
                // Meia a meia / terços: um item_pedido por sabor com quantidade fracionada
                $numSabores = count($sabores);
                // Quantidade distribuída em centésimos para somar exatamente $qty inteiros
                // (ex.: 1 pizza em 1/3 → 0,33 + 0,33 + 0,34). O resto vai para os últimos sabores.
                $qtdCentesimos = $qty * 100;
                $qtdPorItem    = intdiv($qtdCentesimos, $numSabores);
                $qtdExtra      = $qtdCentesimos % $numSabores;

                foreach ($sabores as $idx => $sabor) {
                    // Quantidade da fração: resto distribuído nos últimos sabores (último = 0,34)
                    $qtdFracao  = ($qtdPorItem + ($idx >= $numSabores - $qtdExtra ? 1 : 0)) / 100;

                    $sPrecoOrig = max((float) ($sabor['precoOriginal'] ?? 0), (float) $sabor['preco']);
                    $sPreco     = (float) $sabor['preco'];
                    $sPrecoBase = max($sPrecoOrig, $sPreco);
                    $sDescUnit  = max(0.0, $sPrecoOrig - $sPreco);

                    // 1/N do preço (e do desconto) do sabor, em centavos, por pizza
                    $totalCentavos  = (int) round($sPrecoBase * 100);
                    $centsPorItem   = intdiv($totalCentavos, $numSabores);
                    $centsExtra     = $totalCentavos % $numSabores;
                    $brutoFracaoUma = ($centsPorItem + ($idx < $centsExtra ? 1 : 0)) / 100;

                    $descCentavos   = (int) round($sDescUnit * 100);
                    $descPorItem    = intdiv($descCentavos, $numSabores);
                    $descExtra      = $descCentavos % $numSabores;
                    $descFracaoUma  = ($descPorItem + ($idx < $descExtra ? 1 : 0)) / 100;

                    // Multiplica pela quantidade de pizzas pedidas
                    $brutoFracao    = round($brutoFracaoUma * $qty, 2);
                    $descontoFracao = round($descFracaoUma * $qty, 2);

                    $linhas[] = [
                        'item_pedido_produto_id'       => $sabor['id'],
                        'item_pedido_quantidade'       => $qtdFracao,
                        'item_pedido_valor_unitario'   => $sPrecoBase,
                        'item_pedido_valor'            => round($brutoFracao - $descontoFracao, 2),
                        'item_pedido_desconto'         => $descontoFracao,
                        'item_pedido_valor_adicionais' => 0,
                        'item_pedido_observacao'       => $observacao,
                        'item_pedido_status'           => 'INSERIDO',
                    ];
                }
            } else {
                $bruto    = round($precoOriginal * $qty, 2);
                $desconto = round($descontoUnit * $qty, 2);

                $linhas[] = [
                    'item_pedido_produto_id'       => $item['id'],
                    'item_pedido_quantidade'       => $qty,
                    'item_pedido_valor_unitario'   => $precoOriginal,
                    'item_pedido_valor'            => round($bruto - $desconto, 2),
                    'item_pedido_desconto'         => $desconto,
                    'item_pedido_valor_adicionais' => 0,
                    'item_pedido_observacao'       => $observacao,
                    'item_pedido_status'           => 'INSERIDO',
                ];
            }
        }

        // Totais derivados das próprias linhas (total == soma exata dos itens)
        $somaLiquido   = round(array_sum(array_column($linhas, 'item_pedido_valor')), 2);
        $totalDesconto = round(array_sum(array_column($linhas, 'item_pedido_desconto')), 2);
        $totalBruto    = round($somaLiquido + $totalDesconto, 2);

        // Calcula taxa de entrega
        $valorFrete   = 0.0;
        $opcaoEntrega = OpcoesEntregas::find($request->opcao_entrega_id);
        if ($opcaoEntrega && $opcaoEntrega->opcaoentrega_valor_frete > 0) {
            if ($opcaoEntrega->opcaoentrega_min_valor_frete <= 0 || $somaLiquido < $opcaoEntrega->opcaoentrega_min_valor_frete) {
                $valorFrete = (float) $opcaoEntrega->opcaoentrega_valor_frete;
            }
        }

        // Cria pedido com totais reais
        $pedido = Pedido::create([
            'pedido_cliente_id'           => $cliente->id,
            'pedido_opcaoentrega_id'       => $request->opcao_entrega_id,
            'pedido_endereco_entrega'      => $request->endereco ?? null,
            'pedido_descricao_pagamento'   => $descricaoPagamento,
            'pedido_observacao_pagamento'  => $observacaoPagamento,
            'pedido_valor_itens'           => $totalBruto,
            'pedido_valor_desconto'        => $totalDesconto,
            'pedido_valor_frete'           => $valorFrete,
            'pedido_valor_total'           => round(max(0, $somaLiquido + $valorFrete), 2),
            'pedido_status'                => 'INICIADO',
            'pedido_origem'                => PedidoOrigemEnum::CARDAPIO,
            'pedido_datahora_abertura'     => now(),
        ]);

        // Cria os itens do pedido
        foreach ($linhas as $linha) {
            $linha['item_pedido_pedido_id'] = $pedido->id;
            ItensPedido::create($linha);
        }

        return response()->json([
            'pedido_id'      => $pedido->id,
            'cliente_id'     => $cliente->id,
            'total_bruto'    => $totalBruto,
            'total_desconto' => $totalDesconto,
            'valor_frete'    => $valorFrete,
            'total_final'    => round(max(0, $somaLiquido + $valorFrete), 2),
        ]);
    }
}
