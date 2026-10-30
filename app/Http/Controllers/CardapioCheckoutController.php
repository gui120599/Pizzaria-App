<?php

namespace App\Http\Controllers;

use App\Enums\CanalLancamentoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Exceptions\ComboSaboresInvalidoException;
use App\Exceptions\CupomInvalidoException;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\ItemIndisponivelException;
use App\Exceptions\PerguntaNaoRespondidaException;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\Cliente;
use App\Models\HorarioFuncionamento;
use App\Models\Influenciador;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Notifications\CupomUtilizadoNotification;
use App\Services\ClienteResolverService;
use App\Services\CupomPedidoService;
use App\Services\CupomService;
use App\Services\ItemSolicitado;
use App\Services\LancamentoItemPedidoService;
use App\Services\LinhaPrecificada;
use App\Support\TotaisPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CardapioCheckoutController extends Controller
{
    /** Genérica de propósito: não revela se o código existe, está inativo ou esgotado. */
    public const MENSAGEM_CUPOM_INVALIDO = 'Cupom inválido ou indisponível.';

    /**
     * Prévia do desconto do cupom para o carrinho, sem gravar nada. O valor vem
     * dos itens precificados no servidor, nunca do navegador.
     */
    public function validarCupom(
        Request $request,
        LancamentoItemPedidoService $lancamento,
        CupomService $cupomService,
    ): JsonResponse {
        $request->validate([
            'codigo_cupom' => 'required|string|max:50',
            'telefone' => 'nullable|string|max:30',
            'opcao_pagamento_id' => 'nullable|integer|exists:opcoes_pagamentos,id',
            'itens' => 'required|array|min:1',
            'itens.*.id' => 'required|integer|exists:produtos,id',
            'itens.*.qty' => 'required|integer|min:1|max:99',
            'itens.*.sabores' => 'nullable|array|min:2|max:6',
            'itens.*.sabores.*.id' => 'required|integer|exists:produtos,id',
            'itens.*.oferta_produto_id' => 'nullable|integer|exists:produtos,id',
            'itens.*.respostas' => 'nullable|array|max:20',
            'itens.*.respostas.*' => 'array|max:20',
            'itens.*.respostas.*.*' => 'integer',
            'itens.*.adicionais' => 'nullable|array|max:20',
            'itens.*.adicionais.*' => 'integer',
        ]);

        try {
            $linhas = collect($request->itens)
                ->map(fn (array $item) => $lancamento->precificar(
                    ItemSolicitado::doCarrinho($item),
                    CanalLancamentoEnum::CARDAPIO,
                    $request->integer('opcao_pagamento_id') ?: null,
                ))
                ->all();

            $telefone = preg_replace('/\D/', '', (string) $request->string('telefone'));
            $cliente = strlen($telefone) >= 8
                ? Cliente::where('cliente_celular', 'like', "%{$telefone}%")->first()
                : null;

            $resultado = $cupomService->validar((string) $request->string('codigo_cupom'), $this->somaLiquido($linhas), $cliente);
        } catch (CupomInvalidoException|ItemIndisponivelException|ComboSaboresInvalidoException|PerguntaNaoRespondidaException|PromocaoIndisponivelException) {
            return response()->json(['message' => self::MENSAGEM_CUPOM_INVALIDO], 422);
        }

        return response()->json([
            'codigo' => $resultado['cupom']->cupom_codigo,
            'valor_desconto' => $resultado['valor_desconto'],
        ]);
    }

    /**
     * Líquido das linhas precificadas, incluindo o valor das ofertas aceitas
     * (acréscimo, sem desconto).
     *
     * @param  array<int, LinhaPrecificada>  $linhas
     */
    private function somaLiquido(array $linhas): float
    {
        $soma = round(array_sum(array_map(fn (LinhaPrecificada $linha) => $linha->valor(), $linhas)), 2);
        $ofertas = round(array_sum(array_map(fn (LinhaPrecificada $linha) => (float) $linha->oferta?->pao_valor_adicional, $linhas)), 2);

        return round($soma + $ofertas, 2);
    }

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
        CupomService $cupomService,
        CupomPedidoService $cupomPedidoService,
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
            'codigo_cupom' => 'nullable|string|max:50',
        ]);

        $opcaoPagamentoId = $request->integer('opcao_pagamento_id') ?: null;

        if ($opcaoPagamentoId && OpcoesPagamento::find($opcaoPagamentoId)?->ehCarteiraInfluenciador()) {
            return response()->json(['message' => 'Forma de pagamento indisponível no cardápio.'], 422);
        }

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
        $somaLiquido = $this->somaLiquido($linhas);
        $totalDesconto = round(array_sum(array_map(fn (LinhaPrecificada $linha) => $linha->desconto(), $linhas)), 2);
        $totalBruto = round($somaLiquido + $totalDesconto, 2);

        // Cupom de desconto: validação otimista antes da transação, só para dar
        // feedback rápido — a garantia real contra corrida em cupom de uso único
        // vem da revalidação com lock dentro da transação (ver CupomService).
        $cupomAplicado = null;
        $valorDescontoCupom = 0.0;

        if ($request->filled('codigo_cupom')) {
            try {
                $resultadoCupom = $cupomService->validar((string) $request->string('codigo_cupom'), $somaLiquido, $cliente);
            } catch (CupomInvalidoException) {
                return response()->json(['message' => self::MENSAGEM_CUPOM_INVALIDO], 422);
            }

            $cupomAplicado = $resultadoCupom['cupom'];
            $valorDescontoCupom = $resultadoCupom['valor_desconto'];
        }

        // Calcula taxa de entrega
        $valorFrete = 0.0;
        $opcaoEntrega = OpcoesEntregas::find($request->opcao_entrega_id);
        if ($opcaoEntrega && $opcaoEntrega->opcaoentrega_valor_frete > 0) {
            if ($opcaoEntrega->opcaoentrega_min_valor_frete <= 0 || $somaLiquido < $opcaoEntrega->opcaoentrega_min_valor_frete) {
                $valorFrete = (float) $opcaoEntrega->opcaoentrega_valor_frete;
            }
        }

        // Referência do link /r/{slug} (cookie): só métrica, não dá comissão sem cupom.
        $influenciadorRefId = Influenciador::query()
            ->whereKey((int) $request->cookie(ReferenciaInfluenciadorController::COOKIE))
            ->where('influenciador_status', StatusInfluenciadorEnum::ATIVO)
            ->value('id');

        // Pedido, itens e débito do saldo promocional numa transação só: se a
        // última pizza da promoção acabar aqui, nada é gravado.
        try {
            $pedido = DB::transaction(function () use (
                $linhas, $lancamento, $request, $cliente, $descricaoPagamento,
                $observacaoPagamento, $totalBruto, $totalDesconto, $valorFrete, $somaLiquido,
                $cupomAplicado, $valorDescontoCupom, $cupomService, $cupomPedidoService, $opcaoEntrega, $influenciadorRefId
            ) {
                if ($cupomAplicado) {
                    $cupomService->consumir($cupomAplicado, $somaLiquido, $cliente);
                }

                $pedido = Pedido::create([
                    'pedido_cliente_id' => $cliente->id,
                    'pedido_opcaoentrega_id' => $request->opcao_entrega_id,
                    'pedido_endereco_entrega' => $request->endereco ?? null,
                    'pedido_descricao_pagamento' => $descricaoPagamento,
                    'pedido_observacao_pagamento' => $observacaoPagamento,
                    'pedido_valor_itens' => $totalBruto,
                    'pedido_valor_desconto' => $totalDesconto,
                    'pedido_cupom_id' => $cupomAplicado?->id,
                    'pedido_influenciador_ref_id' => $influenciadorRefId,
                    'pedido_valor_desconto_cupom' => $valorDescontoCupom,
                    'pedido_valor_frete' => $valorFrete,
                    'pedido_valor_total' => round(max(0, $somaLiquido - $valorDescontoCupom + $valorFrete), 2),
                    'pedido_status' => 'INICIADO',
                    'pedido_origem' => PedidoOrigemEnum::CARDAPIO,
                    'pedido_datahora_abertura' => now(),
                ]);

                foreach ($linhas as $linha) {
                    $item = $lancamento->gravar($pedido->id, $linha);
                    $lancamento->gravarOferta($pedido->id, $item, $linha);
                }

                if ($cupomAplicado) {
                    // O desconto vai para as linhas e o total passa a vir delas (a regra do
                    // frete mínimo olha o líquido já com o cupom).
                    $cupomPedidoService->aplicar($pedido, $cupomAplicado);

                    $totais = TotaisPedido::paraItens(
                        $pedido->item_pedido_pedido_id()->where('item_pedido_status', 'INSERIDO')->get(),
                        $opcaoEntrega,
                    );

                    $pedido->update([
                        'pedido_valor_itens' => $totais['itens'],
                        'pedido_valor_desconto' => $totais['desconto'],
                        'pedido_valor_frete' => $totais['frete'],
                        'pedido_valor_total' => $totais['total'],
                    ]);
                }

                return $pedido;
            });
        } catch (PromocaoIndisponivelException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (CupomInvalidoException) {
            return response()->json(['message' => self::MENSAGEM_CUPOM_INVALIDO], 422);
        }

        // Fora da transação de propósito: notificação via HTTP não deve
        // segurar a conexão do banco esperando a Evolution API responder.
        if ($cupomAplicado?->influenciador) {
            $cupomAplicado->influenciador->notify(new CupomUtilizadoNotification($pedido));
        }

        $pedido->refresh();

        return response()->json([
            'pedido_id' => $pedido->id,
            'link_acompanhamento' => $pedido->linkAcompanhamento(),
            'cliente_id' => $cliente->id,
            'total_bruto' => $cupomAplicado ? (float) $pedido->pedido_valor_itens : $totalBruto,
            'total_desconto' => $cupomAplicado ? (float) $pedido->pedido_valor_desconto : $totalDesconto,
            'valor_desconto_cupom' => (float) $pedido->pedido_valor_desconto_cupom,
            'valor_frete' => (float) $pedido->pedido_valor_frete,
            'total_final' => (float) $pedido->pedido_valor_total,
        ]);
    }
}
