<?php

namespace App\Http\Controllers;

use App\Enums\PedidoOrigemEnum;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\Cliente;
use App\Models\HorarioFuncionamento;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;
use App\Services\ClienteResolverService;
use App\Services\EstoqueService;
use App\Services\PrecificadorService;
use App\Services\PromocaoAdicionalService;
use App\Services\PromocaoRelampagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'encontrado' => true,
            'cliente_id' => $cliente->id,
            'nome' => $cliente->cliente_nome,
            'endereco' => $endereco,
        ]);
    }

    public function buscarClientesPorNome(Request $request)
    {
        $termo = trim($request->string('nome'));

        if (mb_strlen($termo) < 2) {
            return response()->json(['clientes' => []]);
        }

        $clientes = Cliente::where('cliente_nome', 'like', "%{$termo}%")
            ->orderBy('cliente_nome')
            ->limit(15)
            ->get()
            ->map(fn (Cliente $cliente) => [
                'cliente_id' => $cliente->id,
                'nome' => $cliente->cliente_nome,
                'celular' => $cliente->cliente_celular,
                'endereco' => collect([
                    $cliente->cliente_endereco,
                    $cliente->cliente_numero_endereco,
                    $cliente->cliente_bairro,
                ])->filter()->implode(', '),
            ]);

        return response()->json(['clientes' => $clientes]);
    }

    public function checkout(
        Request $request,
        PrecificadorService $precificador,
        PromocaoRelampagoService $promocoes,
        PromocaoAdicionalService $promocoesAdicionais,
        ClienteResolverService $clienteResolver,
    ) {
        if (! HorarioFuncionamento::estaAberto()) {
            $proximo = HorarioFuncionamento::proximoHorario();
            $msg = 'Não estamos aceitando pedidos no momento.';
            if ($proximo) {
                $msg .= ' Voltamos '.$proximo.'.';
            }

            return response()->json(['message' => $msg], 422);
        }

        // Preço NÃO é aceito do cliente: o navegador informa apenas o que ele quer
        // comprar, e o servidor decide quanto custa (ver PrecificadorService).
        $request->validate([
            'nome' => 'required|string|max:255',
            'telefone' => 'required|string|min:8',
            'opcao_entrega_id' => 'required|integer|exists:opcoes_entregas,id',
            'pagamento_nome' => 'required|string|max:100',
            'opcao_pagamento_id' => 'nullable|integer|exists:opcoes_pagamentos,id',
            'troco_para' => 'nullable|numeric|min:0',
            'itens' => 'required|array|min:1',
            'itens.*.id' => 'required|integer|exists:produtos,id',
            'itens.*.qty' => 'required|integer|min:1|max:99',
            'itens.*.observacao' => 'nullable|string|max:500',
            'itens.*.sabores' => 'nullable|array|min:2|max:3',
            'itens.*.sabores.*.id' => 'required|integer|exists:produtos,id',
            // Só sinaliza QUAL produto o cliente escolheu entre as opções de
            // oferta do gatilho — o servidor decide se essa oferta existe, está
            // vigente, tem saldo e é permitida pela forma de pagamento (ver
            // PrecificadorService::regraAdicionalDoProduto()); nunca aceita
            // valor ou id de regra/oferta vindo do cliente.
            'itens.*.oferta_produto_id' => 'nullable|integer|exists:produtos,id',
        ]);

        $opcaoPagamentoId = $request->integer('opcao_pagamento_id') ?: null;

        // Valida reCAPTCHA apenas se as chaves estiverem configuradas
        $secret = config('services.recaptcha.secret');
        if ($secret && $request->filled('recaptcha_token')) {
            $resp = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $request->recaptcha_token,
                'remoteip' => $request->ip(),
            ]);
            if (! ($resp->json('success'))) {
                return response()->json(['message' => 'Verificação de segurança falhou. Tente novamente.'], 422);
            }
        }

        // Busca ou cria o cliente
        $cliente = $clienteResolver->resolverOuCriar([
            'nome' => $request->nome,
            'celular' => $request->telefone,
            'endereco' => $request->endereco,
        ]);

        // Monta descrição do pagamento
        $descricaoPagamento = $request->pagamento_nome;
        $observacaoPagamento = null;
        if ($request->filled('troco_para') && $request->troco_para > 0) {
            $observacaoPagamento = 'Troco para R$ '.number_format($request->troco_para, 2, ',', '.');
        }

        // Monta as linhas de itens já com o valor LÍQUIDO (desconto embutido).
        // Para sabores (meia/terço) o rateio em centavos vive no PrecificadorService.
        $itens = collect($request->itens);

        $produtoIds = $itens->flatMap(
            fn (array $item) => empty($item['sabores'])
                ? [$item['id']]
                : collect($item['sabores'])->pluck('id')->all()
        )->unique();

        // Eager load de categoria: maxSaboresEfetivo() consulta categoria_max_sabores.
        $produtos = Produto::with('categoria')->whereIn('id', $produtoIds)->get()->keyBy('id');

        if ($produtos->count() !== $produtoIds->count()) {
            return response()->json(['message' => 'Um dos produtos do carrinho não está mais disponível.'], 422);
        }

        $linhas = [];

        foreach ($itens as $item) {
            $qty = (int) $item['qty'];
            $sabores = $item['sabores'] ?? null;
            $observacao = $item['observacao'] ?? null;

            if (! empty($sabores) && count($sabores) > 1) {
                // Meia a meia / terços: um item_pedido por sabor com quantidade fracionada.
                $saboresProdutos = collect($sabores)
                    ->map(fn (array $sabor) => $produtos->get($sabor['id']))
                    ->all();

                foreach ($precificador->ratearCombo($saboresProdutos, $qty, $opcaoPagamentoId) as $rateio) {
                    $linhas[] = [
                        'item_pedido_produto_id' => $rateio['produto_id'],
                        'item_pedido_promocao_id' => $rateio['promocao_id'],
                        'item_pedido_promocao_adicional_regra_id' => $rateio['promocao_adicional_regra_id'],
                        'item_pedido_quantidade' => $rateio['quantidade'],
                        'item_pedido_valor_unitario' => $rateio['valor_unitario'],
                        'item_pedido_valor' => $rateio['valor'],
                        'item_pedido_desconto' => $rateio['desconto'],
                        'item_pedido_desconto_unitario' => $rateio['desconto_unitario'],
                        'item_pedido_valor_adicionais' => 0,
                        'item_pedido_observacao' => $observacao,
                        'item_pedido_status' => 'INSERIDO',
                    ];
                }

                continue;
            }

            $preco = $precificador->resolver($produtos->get($item['id']), opcaoPagamentoId: $opcaoPagamentoId);
            $linha = ItensPedido::calcularLinha($qty, $preco->valorUnitario, $preco->descontoUnitario);

            // Oferta da promoção adicional: só existe se o cliente escolheu um
            // produto E o servidor confirma, agora, que ele é uma das opções
            // vigentes com saldo (e forma de pagamento permitida) para ESTE
            // gatilho — nunca confia em id/valor vindo do request. Fora de
            // escopo para combo de sabores (branch acima).
            $regraGatilho = null;
            $ofertaEscolhida = null;

            if (! empty($item['oferta_produto_id'])) {
                $regraGatilho = $precificador->regraAdicionalDoProduto((int) $item['id'], $opcaoPagamentoId);
                $ofertaEscolhida = $regraGatilho?->ofertasDisponiveis()
                    ->first(fn (PromocaoAdicionalOferta $o) => (int) $o->pao_produto_oferta_id === (int) $item['oferta_produto_id']);

                if (! $ofertaEscolhida) {
                    $regraGatilho = null;
                }
            }

            $linhas[] = [
                'item_pedido_produto_id' => $item['id'],
                'item_pedido_promocao_id' => $preco->promocaoId,
                'item_pedido_promocao_adicional_regra_id' => $preco->promocaoAdicionalRegraId,
                'item_pedido_quantidade' => $qty,
                'item_pedido_valor_unitario' => $linha['valor_unitario'],
                'item_pedido_valor' => $linha['valor'],
                'item_pedido_desconto' => $linha['desconto'],
                'item_pedido_desconto_unitario' => $preco->descontoUnitario,
                'item_pedido_valor_adicionais' => 0,
                'item_pedido_observacao' => $observacao,
                'item_pedido_status' => 'INSERIDO',
                '_oferta_regra' => $regraGatilho,
                '_oferta_escolhida' => $ofertaEscolhida,
            ];
        }

        // Disponibilidade de estoque: soma por produto (um mesmo produto pode
        // aparecer em mais de uma linha, ex.: combo de sabores) e bloqueia o
        // checkout se algum item em modo BLOQUEAR não tiver saldo suficiente.
        $bloqueiosEstoque = [];
        $estoque = app(EstoqueService::class);

        foreach (collect($linhas)->groupBy('item_pedido_produto_id') as $produtoId => $doGrupo) {
            $produtoLinha = $produtos->get($produtoId);
            if (! $produtoLinha) {
                continue;
            }

            $qtdTotal = (float) collect($doGrupo)->sum('item_pedido_quantidade');
            $resultado = $estoque->checarDisponibilidade($produtoLinha, $qtdTotal);
            array_push($bloqueiosEstoque, ...$resultado['bloqueios']);
        }

        if ($bloqueiosEstoque !== []) {
            return response()->json(['message' => 'Item sem estoque suficiente: '.implode(' | ', $bloqueiosEstoque)], 422);
        }

        // Limite por pedido: somado por promoção, antes de gravar qualquer coisa.
        $promocoesVigentes = $precificador->promocoesVigentes()->keyBy('id');

        try {
            foreach (collect($linhas)->whereNotNull('item_pedido_promocao_id')->groupBy('item_pedido_promocao_id') as $promocaoId => $doGrupo) {
                $promocoes->validarLimitePorPedido(
                    $promocoesVigentes->get($promocaoId),
                    (float) collect($doGrupo)->sum('item_pedido_quantidade'),
                );
            }
        } catch (PromocaoIndisponivelException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Limite por pedido da promoção adicional: soma quantas vezes cada
        // regra (gatilho) foi pedida no carrinho, antes de gravar qualquer
        // coisa — não importa qual das N ofertas foi escolhida em cada linha.
        $regrasAdicionaisSolicitadas = collect($linhas)->pluck('_oferta_regra')->filter();
        $ofertasAdicionaisSolicitadas = collect($linhas)->pluck('_oferta_escolhida')->filter();

        try {
            foreach ($regrasAdicionaisSolicitadas->groupBy('id') as $doGrupo) {
                $promocoesAdicionais->validarLimitePorPedido($doGrupo->first(), $doGrupo->count());
            }
        } catch (PromocaoIndisponivelException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Totais derivados das próprias linhas (total == soma exata dos itens),
        // incluindo o valor das ofertas aceitas (sem desconto — é acréscimo).
        $somaLiquido = round(array_sum(array_column($linhas, 'item_pedido_valor')), 2);
        $totalDesconto = round(array_sum(array_column($linhas, 'item_pedido_desconto')), 2);
        $valorOfertasAdicionais = round((float) $ofertasAdicionaisSolicitadas->sum('pao_valor_adicional'), 2);
        $somaLiquido = round($somaLiquido + $valorOfertasAdicionais, 2);
        $totalBruto = round($somaLiquido + $totalDesconto, 2);

        // Calcula taxa de entrega
        $valorFrete = 0.0;
        $opcaoEntrega = OpcoesEntregas::find($request->opcao_entrega_id);
        if ($opcaoEntrega && $opcaoEntrega->opcaoentrega_valor_frete > 0) {
            if ($opcaoEntrega->opcaoentrega_min_valor_frete <= 0 || $somaLiquido < $opcaoEntrega->opcaoentrega_min_valor_frete) {
                $valorFrete = (float) $opcaoEntrega->opcaoentrega_valor_frete;
            }
        }

        // Pedido, itens e débito do saldo promocional numa transação só: se a
        // última pizza da promoção acabar aqui, nada é gravado.
        try {
            $pedido = DB::transaction(function () use (
                $linhas, $promocoes, $promocoesAdicionais, $request, $cliente, $descricaoPagamento,
                $observacaoPagamento, $totalBruto, $totalDesconto, $valorFrete, $somaLiquido
            ) {
                $pedido = Pedido::create([
                    'pedido_cliente_id' => $cliente->id,
                    'pedido_opcaoentrega_id' => $request->opcao_entrega_id,
                    'pedido_endereco_entrega' => $request->endereco ?? null,
                    'pedido_descricao_pagamento' => $descricaoPagamento,
                    'pedido_observacao_pagamento' => $observacaoPagamento,
                    'pedido_valor_itens' => $totalBruto,
                    'pedido_valor_desconto' => $totalDesconto,
                    'pedido_valor_frete' => $valorFrete,
                    'pedido_valor_total' => round(max(0, $somaLiquido + $valorFrete), 2),
                    'pedido_status' => 'INICIADO',
                    'pedido_origem' => PedidoOrigemEnum::CARDAPIO,
                    'pedido_datahora_abertura' => now(),
                ]);

                foreach ($linhas as $linha) {
                    /** @var ?PromocaoAdicionalRegra $ofertaRegra */
                    $ofertaRegra = $linha['_oferta_regra'] ?? null;
                    /** @var ?PromocaoAdicionalOferta $ofertaEscolhida */
                    $ofertaEscolhida = $linha['_oferta_escolhida'] ?? null;
                    unset($linha['_oferta_regra'], $linha['_oferta_escolhida']);

                    $linha['item_pedido_pedido_id'] = $pedido->id;
                    $itemModel = ItensPedido::create($linha);
                    $promocoes->consumir($itemModel);

                    if ($ofertaRegra && $ofertaEscolhida) {
                        $itemOferta = ItensPedido::create([
                            'item_pedido_pedido_id' => $pedido->id,
                            'item_pedido_produto_id' => $ofertaEscolhida->pao_produto_oferta_id,
                            'item_pedido_promocao_adicional_regra_id' => $ofertaRegra->id,
                            'item_pedido_promocao_adicional_oferta_id' => $ofertaEscolhida->id,
                            'item_pedido_origem_id' => $itemModel->id,
                            'item_pedido_quantidade' => 1,
                            'item_pedido_valor_unitario' => $ofertaEscolhida->pao_valor_adicional,
                            'item_pedido_valor' => $ofertaEscolhida->pao_valor_adicional,
                            'item_pedido_desconto' => 0,
                            'item_pedido_valor_adicionais' => 0,
                            'item_pedido_status' => 'INSERIDO',
                        ]);

                        $promocoesAdicionais->consumir($itemOferta);
                    }
                }

                return $pedido;
            });
        } catch (PromocaoIndisponivelException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'pedido_id' => $pedido->id,
            'cliente_id' => $cliente->id,
            'total_bruto' => $totalBruto,
            'total_desconto' => $totalDesconto,
            'valor_frete' => $valorFrete,
            'total_final' => round(max(0, $somaLiquido + $valorFrete), 2),
        ]);
    }
}
