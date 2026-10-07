<?php

namespace App\Services\MesaCliente;

use App\Enums\MotivoAprovacaoMesaEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use App\Models\Produto;
use App\Services\LinhaPrecificada;

/**
 * Quando o pedido do QR precisa do garçom antes de ir para a cozinha
 * (config pizzaria.mesa_cliente): primeiro pedido, produto marcado (ex.:
 * bebida alcoólica) ou quantidade alta de um item. Sem motivo, vai direto.
 */
class RegraAprovacaoMesa
{
    /**
     * Chamar com a conta travada (lockForUpdate na sessão): é o que impede
     * dois "primeiros pedidos" simultâneos de passarem direto.
     *
     * @param  list<LinhaPrecificada>  $linhas
     * @return list<MotivoAprovacaoMesaEnum>
     */
    public function motivos(MesaParticipante $participante, array $linhas): array
    {
        $motivos = [];

        if ($this->ePrimeiroPedido($participante)) {
            $motivos[] = MotivoAprovacaoMesaEnum::PRIMEIRO_PEDIDO;
        }

        if ($this->temProdutoMarcado($linhas)) {
            $motivos[] = MotivoAprovacaoMesaEnum::PRODUTO_MARCADO;
        }

        $limite = (int) config('pizzaria.mesa_cliente.quantidade_aprovacao');

        if ($limite > 0 && collect($linhas)->contains(fn (LinhaPrecificada $linha) => $linha->quantidade() >= $limite)) {
            $motivos[] = MotivoAprovacaoMesaEnum::QUANTIDADE_ALTA;
        }

        return $motivos;
    }

    /**
     * Ainda não há pedido do QR aceito (enviado direto ou aprovado) na conta —
     * ou do celular, conforme a config. Pedido pendente não conta: enquanto o
     * garçom não confirma o primeiro, os seguintes também esperam.
     */
    private function ePrimeiroPedido(MesaParticipante $participante): bool
    {
        $escopo = config('pizzaria.mesa_cliente.aprovacao_primeiro_pedido');

        if ($escopo === 'off') {
            return false;
        }

        return ! Pedido::query()
            ->where('pedido_sessao_mesa_id', $participante->mp_sessao_mesa_id)
            ->where('pedido_origem', PedidoOrigemEnum::MESA_QR->value)
            ->when($escopo === 'participante', fn ($q) => $q->where('pedido_mesa_participante_id', $participante->id))
            ->whereNotIn('pedido_status', [StatusPedidoEnum::INICIADO->value, StatusPedidoEnum::CANCELADO->value])
            ->exists();
    }

    /**
     * Algum produto da rodada — cada sabor da pizza e o produto da oferta
     * inclusive — marcado para aprovação, nele ou na categoria (ou na mãe dela).
     *
     * @param  list<LinhaPrecificada>  $linhas
     */
    private function temProdutoMarcado(array $linhas): bool
    {
        $produtoIds = collect($linhas)
            ->flatMap(fn (LinhaPrecificada $linha) => [
                ...array_keys($linha->previa()->consumosPorProduto()),
                ...array_filter([$linha->oferta?->pao_produto_oferta_id]),
            ])
            ->unique()
            ->all();

        return Produto::with('categoria.pai')
            ->whereIn('id', $produtoIds)
            ->get()
            ->contains(fn (Produto $produto) => $produto->produto_requer_aprovacao_mesa
                || $produto->categoria?->categoria_requer_aprovacao_mesa
                || $produto->categoria?->pai?->categoria_requer_aprovacao_mesa);
    }
}
