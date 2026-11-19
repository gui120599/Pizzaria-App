<?php

namespace App\Http\Controllers;

use App\Enums\CanalLancamentoEnum;
use App\Exceptions\ComboSaboresInvalidoException;
use App\Exceptions\ItemIndisponivelException;
use App\Exceptions\PerguntaNaoRespondidaException;
use App\Exceptions\PromocaoIndisponivelException;
use App\Services\ItemSolicitado;
use App\Services\LancamentoItemPedidoService;
use App\Services\LinhaPrecificada;
use App\Services\PrecificadorService;
use App\Services\PromocaoCombo\AplicacaoCombo;
use App\Services\PromocaoCombo\PendenciaCombo;
use App\Services\PromocaoCombo\PromocaoComboService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prévia da sacola do cardápio calculada no servidor: as promoções combo
 * fechadas, o desconto de cada linha e o que falta para fechar outro combo.
 * O navegador só exibe; o checkout recalcula tudo do zero.
 */
class CardapioCarrinhoController extends Controller
{
    public function precificar(
        Request $request,
        LancamentoItemPedidoService $lancamento,
        PromocaoComboService $combos,
        PrecificadorService $precificador,
    ): JsonResponse {
        $request->validate([
            'opcao_entrega_id' => 'nullable|integer|exists:opcoes_entregas,id',
            'opcao_pagamento_id' => 'nullable|integer|exists:opcoes_pagamentos,id',
            'mesa' => 'nullable|boolean',
            'itens' => 'required|array|min:1|max:60',
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

        $mesa = $request->boolean('mesa');
        $canal = $mesa ? CanalLancamentoEnum::MESA_QR : CanalLancamentoEnum::CARDAPIO;
        $opcaoPagamentoId = $request->integer('opcao_pagamento_id') ?: null;

        try {
            $previas = $precificador->noContextoMesa(fn (): array => collect($request->itens)
                ->map(fn (array $item): LinhaPrecificada => $lancamento->precificar(ItemSolicitado::doCarrinho($item), $canal, $opcaoPagamentoId))
                ->map(function (LinhaPrecificada $linha) {
                    $previa = $linha->previa();
                    // Brinde aceito conta como "linha com Promoção Adicional" (ver PromocaoComboService::linha()).
                    $previa->setAttribute('itens_oferta_count', $linha->oferta ? 1 : 0);

                    return $previa;
                })
                ->mapWithKeys(fn ($previa, int $indice): array => [(string) $indice => $previa])
                ->all(), $mesa);
        } catch (ItemIndisponivelException|ComboSaboresInvalidoException|PerguntaNaoRespondidaException|PromocaoIndisponivelException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $plano = $combos->planejar($previas, opcaoEntregaId: $request->integer('opcao_entrega_id') ?: null, ehMesa: $mesa);

        return response()->json([
            'desconto' => $plano->descontoTotalCentavos() / 100,
            'linhas' => array_map(fn (int $centavos): float => $centavos / 100, $plano->descontoPorLinha()),
            'aplicacoes' => array_map(fn (AplicacaoCombo $aplicacao): array => [
                'promocao_id' => $aplicacao->promocaoId,
                'nome' => $aplicacao->nome,
                'quantidade' => $aplicacao->quantidade,
                'preco' => $aplicacao->precoCentavos / 100,
                'valor_cheio' => $aplicacao->valorCheioCentavos() / 100,
                'desconto' => $aplicacao->descontoCentavos() / 100,
                'itens' => array_map('intval', array_keys($aplicacao->partes)),
            ], $plano->aplicacoes),
            'pendencias' => array_map(fn (PendenciaCombo $pendencia): array => [
                'promocao_id' => $pendencia->promocaoId,
                'faltam' => $pendencia->faltam,
            ], $plano->pendencias),
        ]);
    }
}
