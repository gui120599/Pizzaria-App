<?php

namespace App\Services\Garcom;

use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\User;
use App\Services\ClienteResolverService;
use App\Services\PedidoStatusService;
use App\Support\JanelaOperacional;
use App\Support\TotaisPedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Retirada atendida pelo garçom fora de sessão de mesa (pedido para levar).
 *
 * Mesmo desenho da rodada de mesa (ver AtendimentoMesaService): o garçom
 * monta um rascunho INICIADO só dele, com os itens gravados pelo
 * PedidoProdutoSelector, e o envio o confirma para ABERTO — reenviar depois de
 * uma queda de rede vira no-op. Sem taxa de serviço.
 */
class RetiradaService
{
    public function __construct(
        private PedidoStatusService $status,
        private ClienteResolverService $clientes,
    ) {}

    /** Rascunho da próxima retirada deste garçom (cria se não houver). */
    public function rascunho(User $garcom): Pedido
    {
        return Pedido::query()
            ->whereNull('pedido_sessao_mesa_id')
            ->where('pedido_origem', PedidoOrigemEnum::GARCOM->value)
            ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
            ->where('pedido_usuario_garcom_id', $garcom->id)
            ->latest('id')
            ->first()
            ?? Pedido::create([
                'pedido_status' => StatusPedidoEnum::INICIADO->value,
                'pedido_origem' => PedidoOrigemEnum::GARCOM,
                'pedido_usuario_garcom_id' => $garcom->id,
            ]);
    }

    /**
     * Envia a retirada para a cozinha (INICIADO → ABERTO), vinculando o cliente.
     *
     * @return bool false quando a retirada já tinha sido enviada (reenvio)
     *
     * @throws RuntimeException quando faltam itens, nome/celular ou opção de retirada
     */
    public function enviar(Pedido $rascunho, string $nome, string $celular): bool
    {
        $nome = trim($nome);
        $digitosCelular = preg_replace('/\D/', '', $celular);

        if ($nome === '') {
            throw new RuntimeException('Informe o nome do cliente.');
        }

        if (strlen($digitosCelular) < 10) {
            throw new RuntimeException('Informe o celular do cliente com DDD.');
        }

        return DB::transaction(function () use ($rascunho, $nome, $digitosCelular) {
            $pedido = Pedido::whereKey($rascunho->getKey())->lockForUpdate()->firstOrFail();

            if ($pedido->pedido_status !== StatusPedidoEnum::INICIADO->value) {
                return false;
            }

            $itens = $pedido->item_pedido_pedido_id()->where('item_pedido_status', 'INSERIDO')->get();

            if ($itens->isEmpty()) {
                throw new RuntimeException('Adicione pelo menos um item antes de enviar.');
            }

            $opcao = $this->opcaoEntregaRetirada();
            $cliente = $this->clientes->resolverOuCriar(['nome' => $nome, 'celular' => $digitosCelular]);
            $totais = TotaisPedido::paraItens($itens, $opcao);

            $pedido->fill([
                'pedido_cliente_id' => $cliente->id,
                'pedido_opcaoentrega_id' => $opcao->id,
                'pedido_valor_itens' => $totais['itens'],
                'pedido_valor_desconto' => $totais['desconto'],
                'pedido_valor_frete' => $totais['frete'],
                'pedido_valor_total' => $totais['total'],
                'pedido_datahora_abertura' => Carbon::now(),
            ])->save();

            $this->status->confirmar($pedido);

            return true;
        });
    }

    /** Retirada pronta entregue ao cliente (PRONTO → ENTREGUE, ou FINALIZADO se já paga). */
    public function marcarEntregue(Pedido $pedido, User $garcom): Pedido
    {
        return $this->status->marcarEntregue($pedido, $garcom);
    }

    /**
     * Retiradas do turno ainda em andamento — inclui ENTREGUE sem venda
     * (cliente levou e vai pagar no caixa).
     *
     * @return Collection<int, Pedido>
     */
    public function retiradasDoTurno(): Collection
    {
        [$inicio] = JanelaOperacional::atual();

        return Pedido::query()
            ->whereNull('pedido_sessao_mesa_id')
            ->where('pedido_origem', PedidoOrigemEnum::GARCOM->value)
            ->whereNotIn('pedido_status', [
                StatusPedidoEnum::INICIADO->value,
                StatusPedidoEnum::CANCELADO->value,
                StatusPedidoEnum::FINALIZADO->value,
            ])
            ->where('pedido_datahora_abertura', '>=', $inicio)
            ->with(['cliente:id,cliente_nome', 'garcom:id,name,name_first'])
            ->orderBy('pedido_datahora_abertura')
            ->get();
    }

    /** @throws RuntimeException quando não há opção de entrega sem endereço */
    public function opcaoEntregaRetirada(): OpcoesEntregas
    {
        $configurada = config('pizzaria.salao.opcao_entrega_retirada_id');

        $opcao = $configurada
            ? OpcoesEntregas::find($configurada)
            : OpcoesEntregas::where('opcaoentrega_requer_endereco', false)->orderBy('id')->first();

        return $opcao ?? throw new RuntimeException('Cadastre uma opção de entrega de retirada (sem endereço).');
    }
}
