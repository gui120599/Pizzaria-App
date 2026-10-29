<?php

namespace App\Console\Commands;

use App\Enums\StonePedidoOrigem;
use App\Enums\StonePedidoStatus;
use App\Models\StonePedido;
use App\Services\Stone\StoneConnectService;
use App\Services\Stone\StoneRecebimentoService;
use App\Services\Stone\StoneVendaAutomaticaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rede de segurança do fluxo de recebimento em maquininha Stone. No caminho
 * feliz o pedido é fechado síncrono no handler do webhook charge.paid; este
 * comando cobre as falhas:
 *   1. pedidos PAGOS que não conseguiram ser fechados na Stone (PATCH falhou);
 *   2. pedidos AGUARDANDO antigos cujo webhook charge.paid pode ter se perdido
 *      — consulta a Stone e reprocessa;
 *   3. pedidos AGUARDANDO muito antigos (> 2h) COM order_id viram FALHA;
 *   4. pedidos de origem Pedido/SessaoMesa PAGOS sem Venda (a ambiguidade de
 *      sessão de caixa que gerou stp_erro pode ter se resolvido sozinha —
 *      ver StoneVendaAutomaticaService::recuperarVendaEPagamento());
 *   5. pedidos de RESULTADO DESCONHECIDO (timeout/5xx no POST) e AGUARDANDO
 *      sem order_id: reconsulta/reenvia o MESMO registro com a mesma
 *      Idempotency-Key (ver StoneRecebimentoService::reconsultar()). Nunca
 *      viram Falha sozinhos: o pedido pode existir na maquininha.
 */
class StoneConciliarPedidos extends Command
{
    protected $signature = 'stone:conciliar-pedidos
        {--minutos-perdido=10 : Idade mínima (min) de um pedido AGUARDANDO para consultar a Stone}
        {--minutos-sem-order=2 : Idade mínima (min) de um pedido AGUARDANDO sem order_id para ser reenviado}
        {--horas-expira=2 : Idade (h) para marcar um pedido AGUARDANDO como FALHA}';

    protected $description = 'Fecha pedidos Stone pendentes e recupera webhooks charge.paid perdidos';

    public function handle(StoneConnectService $connect, StoneRecebimentoService $recebimento, StoneVendaAutomaticaService $vendaAutomatica): int
    {
        if (blank(config('services.stone.secret_key'))) {
            $this->warn('Integração Stone não configurada — nada a fazer.');

            return self::SUCCESS;
        }

        $fechados = $this->fecharPendentes($connect);
        $recuperados = $this->recuperarPerdidos($connect, $recebimento);
        $vendasGeradas = $this->reprocessarPagosSemVenda($vendaAutomatica);
        $resolvidos = $this->resolverIndeterminados($recebimento);
        $expirados = $this->expirarAntigos();

        $this->table(
            ['Fechados', 'Recuperados', 'Vendas geradas', 'Indeterminados resolvidos', 'Expirados'],
            [[$fechados, $recuperados, $vendasGeradas, $resolvidos, $expirados]],
        );

        return self::SUCCESS;
    }

    private function fecharPendentes(StoneConnectService $connect): int
    {
        $pedidos = StonePedido::where('stp_status', StonePedidoStatus::Pago->value)
            ->whereNull('stp_fechado_em')
            ->whereNotNull('stp_order_id')
            ->get();

        $ok = 0;
        foreach ($pedidos as $pedido) {
            try {
                $connect->fecharPedido($pedido->stp_order_id, 'paid');
                $pedido->update(['stp_fechado_em' => now()]);
                $ok++;
            } catch (\Throwable $e) {
                Log::channel('stone')->error('Conciliação: falha ao fechar pedido', [
                    'stone_pedido_id' => $pedido->id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        return $ok;
    }

    private function recuperarPerdidos(StoneConnectService $connect, StoneRecebimentoService $recebimento): int
    {
        $limite = now()->subMinutes((int) $this->option('minutos-perdido'));

        $pedidos = StonePedido::where('stp_status', StonePedidoStatus::Aguardando->value)
            ->whereNotNull('stp_order_id')
            ->where('created_at', '<', $limite)
            ->get();

        $recuperados = 0;
        foreach ($pedidos as $pedido) {
            try {
                $order = $connect->consultarPedido($pedido->stp_order_id);
            } catch (\Throwable $e) {
                Log::channel('stone')->warning('Conciliação: falha ao consultar pedido', [
                    'stone_pedido_id' => $pedido->id, 'erro' => $e->getMessage(),
                ]);

                continue;
            }

            $recuperados += $recebimento->aplicarPedidoDaStone($pedido, $order);
        }

        return $recuperados;
    }

    /**
     * Pedidos de origem Pedido/SessaoMesa pagos que ficaram sem Venda por
     * ambiguidade de sessão de caixa (stp_erro preenchido) — tenta de novo
     * agora que o caixa pode ter sido aberto/fechado nesse meio tempo.
     */
    private function reprocessarPagosSemVenda(StoneVendaAutomaticaService $vendaAutomatica): int
    {
        $pedidos = StonePedido::whereIn('stp_status', [StonePedidoStatus::Pago->value, StonePedidoStatus::PagoParcial->value])
            ->whereNull('stp_venda_id')
            ->where('stp_origem', '!=', StonePedidoOrigem::Venda->value)
            ->get();

        $gerados = 0;
        foreach ($pedidos as $pedido) {
            try {
                if ($vendaAutomatica->recuperarVendaEPagamento($pedido)) {
                    $gerados++;
                }
            } catch (\Throwable $e) {
                Log::channel('stone')->error('Conciliação: falha ao reprocessar pedido pago sem venda', [
                    'stone_pedido_id' => $pedido->id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        return $gerados;
    }

    /**
     * Resultado desconhecido e AGUARDANDO sem order_id (o POST pode ter chegado
     * à Stone sem que a resposta fosse gravada): reconsulta o mesmo registro.
     * Quando a Stone ainda não responde, o registro segue como está.
     */
    private function resolverIndeterminados(StoneRecebimentoService $recebimento): int
    {
        $limite = now()->subMinutes((int) $this->option('minutos-sem-order'));

        $pedidos = StonePedido::query()
            ->where(fn ($query) => $query
                ->where('stp_status', StonePedidoStatus::Indeterminado->value)
                ->orWhere(fn ($semOrder) => $semOrder
                    ->where('stp_status', StonePedidoStatus::Aguardando->value)
                    ->whereNull('stp_order_id')
                    ->where('created_at', '<', $limite)))
            ->get();

        $resolvidos = 0;
        foreach ($pedidos as $pedido) {
            try {
                $atual = $recebimento->reconsultar($pedido);

                if ($atual->stp_status !== StonePedidoStatus::Indeterminado) {
                    $resolvidos++;
                }
            } catch (\Throwable $e) {
                Log::channel('stone')->warning('Conciliação: cobrança de resultado desconhecido segue sem resolução', [
                    'stone_pedido_id' => $pedido->id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        return $resolvidos;
    }

    /** Só expira o que tem order_id: sem ele a cobrança pode existir na Stone, e vira Indeterminado (não Falha) em reconsultar(). */
    private function expirarAntigos(): int
    {
        $limite = now()->subHours((int) $this->option('horas-expira'));

        return StonePedido::where('stp_status', StonePedidoStatus::Aguardando->value)
            ->whereNotNull('stp_order_id')
            ->where('created_at', '<', $limite)
            ->update(['stp_status' => StonePedidoStatus::Falha->value]);
    }
}
