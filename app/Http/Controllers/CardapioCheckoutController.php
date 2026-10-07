<?php

namespace App\Http\Controllers;

use App\Enums\CanalLancamentoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Exceptions\ComboSaboresInvalidoException;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\ItemIndisponivelException;
use App\Exceptions\PerguntaNaoRespondidaException;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\Cliente;
use App\Models\HorarioFuncionamento;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Services\ClienteResolverService;
use App\Services\ItemSolicitado;
use App\Services\LancamentoItemPedidoService;
use App\Services\LinhaPrecificada;
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
        LancamentoItemPedidoService $lancamento,
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
        // comprar, e o servidor decide quanto custa (ver LancamentoItemPedidoService).
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
            // Teto real vem das opções de quantidade da categoria
            // (PrecificadorService::opcaoDoCombo); aqui só um limite de sanidade.
            'itens.*.sabores' => 'nullable|array|min:2|max:6',
            'itens.*.sabores.*.id' => 'required|integer|exists:produtos,id',
            // Só sinaliza QUAL produto o cliente escolheu entre as opções de
            // oferta do gatilho — o servidor decide se essa oferta existe, está
            // vigente, tem saldo e é permitida pela forma de pagamento (ver
            // PrecificadorService::regraAdicionalDoProduto()); nunca aceita
            // valor ou id de regra/oferta vindo do cliente.
            'itens.*.oferta_produto_id' => 'nullable|integer|exists:produtos,id',
            // Perguntas do item: pergunta_id => opções escolhidas. O servidor
            // confere se a pergunta vale para o item e se as opções são dela.
            'itens.*.respostas' => 'nullable|array|max:20',
            'itens.*.respostas.*' => 'array|max:20',
            'itens.*.respostas.*.*' => 'integer',
            // Adicionais escolhidos; o servidor só aceita os vinculados ao
            // produto (na pizza de sabores, a todos os sabores) e usa o valor do cadastro.
            'itens.*.adicionais' => 'nullable|array|max:20',
            'itens.*.adicionais.*' => 'integer',
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

        // O carrinho inteiro é recusado se um item não puder ser vendido:
        // produto oculto do cardápio, combo de sabores inválido, estoque em modo
        // BLOQUEAR sem saldo ou limite por pedido de promoção excedido.
        try {
            $linhas = collect($request->itens)
                ->map(fn (array $item) => $lancamento->precificar(
                    ItemSolicitado::doCarrinho($item),
                    CanalLancamentoEnum::CARDAPIO,
                    $opcaoPagamentoId,
                ))
                ->all();

            $lancamento->validarEstoque($linhas);
            $lancamento->validarLimitesPromocao($linhas);
            $lancamento->validarLimitesOferta($linhas);
        } catch (EstoqueInsuficienteException $e) {
            return response()->json(['message' => 'Item sem estoque suficiente: '.$e->getMessage()], 422);
        } catch (ItemIndisponivelException|ComboSaboresInvalidoException|PerguntaNaoRespondidaException|PromocaoIndisponivelException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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

        // Totais derivados das próprias linhas (total == soma exata dos itens),
        // incluindo o valor das ofertas aceitas (sem desconto — é acréscimo).
        $somaLiquido = round(array_sum(array_map(fn (LinhaPrecificada $linha) => $linha->valor(), $linhas)), 2);
        $totalDesconto = round(array_sum(array_map(fn (LinhaPrecificada $linha) => $linha->desconto(), $linhas)), 2);
        $valorOfertasAdicionais = round(array_sum(array_map(fn (LinhaPrecificada $linha) => (float) $linha->oferta?->pao_valor_adicional, $linhas)), 2);
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
                $linhas, $lancamento, $request, $cliente, $descricaoPagamento,
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
                    $item = $lancamento->gravar($pedido->id, $linha);
                    $lancamento->gravarOferta($pedido->id, $item, $linha);
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
