<?php

namespace App\Console\Commands;

use App\Enums\StonePedidoStatus;
use App\Models\StonePedido;
use App\Models\StoneWebhook;
use App\Services\Stone\StoneConnectService;
use App\Services\Stone\StoneRecebimentoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rede de segurança do fluxo de recebimento em maquininha Stone. No caminho
 * feliz o pedido é fechado síncrono no handler do webhook charge.paid; este
 * comando cobre as falhas:
 *   1. pedidos PAGOS que não conseguiram ser fechados na Stone (PATCH falhou);
 *   2. pedidos AGUARDANDO antigos cujo webhook charge.paid pode ter se perdido
 *      — consulta a Stone e reprocessa;
 *   3. pedidos AGUARDANDO muito antigos (> 2h) viram FALHA.
 */
class StoneConciliarPedidos extends Command
{
    protected $signature = 'stone:conciliar-pedidos
        {--minutos-perdido=10 : Idade mínima (min) de um pedido AGUARDANDO para consultar a Stone}
        {--horas-expira=2 : Idade (h) para marcar um pedido AGUARDANDO como FALHA}';

    protected $description = 'Fecha pedidos Stone pendentes e recupera webhooks charge.paid perdidos';

    public function handle(StoneConnectService $connect, StoneRecebimentoService $recebimento): int
    {
        if (blank(config('services.stone.secret_key'))) {
            $this->warn('Integração Stone não configurada — nada a fazer.');

            return self::SUCCESS;
        }

        $fechados = $this->fecharPendentes($connect);
        $recuperados = $this->recuperarPerdidos($connect, $recebimento);
        $expirados = $this->expirarAntigos();

        $this->table(
            ['Fechados', 'Recuperados', 'Expirados'],
            [[$fechados, $recuperados, $expirados]],
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

            $status = $order['status'] ?? null;

            if (in_array($status, ['canceled', 'failed'], true)) {
                $pedido->update([
                    'stp_status' => $status === 'canceled' ? StonePedidoStatus::Cancelado : StonePedidoStatus::Falha,
                ]);

                continue;
            }

            foreach ($order['charges'] ?? [] as $charge) {
                if (($charge['status'] ?? null) !== 'paid') {
                    continue;
                }

                $webhook = StoneWebhook::create([
                    'stw_evento' => 'charge.paid',
                    'stw_hook_id' => 'sync-'.($charge['id'] ?? uniqid()),
                    'stw_charge_id' => $charge['id'] ?? null,
                    'stw_charge_code' => $charge['code'] ?? null,
                    'stw_order_id' => $pedido->stp_order_id,
                    'stw_order_code' => $order['code'] ?? $pedido->stp_order_code,
                    'stw_venda_id' => $pedido->stp_venda_id,
                    'stw_stone_pedido_id' => $pedido->id,
                    'stw_payload' => $this->payloadSintetico($order, $charge),
                    'stw_processado_em' => now(),
                ]);

                try {
                    $recebimento->processarWebhook($webhook);
                    $recuperados++;
                } catch (\Throwable $e) {
                    Log::channel('stone')->error('Conciliação: falha ao reprocessar charge', [
                        'stone_pedido_id' => $pedido->id, 'charge_id' => $charge['id'] ?? null, 'erro' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $recuperados;
    }

    private function expirarAntigos(): int
    {
        $limite = now()->subHours((int) $this->option('horas-expira'));

        return StonePedido::where('stp_status', StonePedidoStatus::Aguardando->value)
            ->where('created_at', '<', $limite)
            ->update(['stp_status' => StonePedidoStatus::Falha->value]);
    }

    private function payloadSintetico(array $order, array $charge): array
    {
        return [
            'id' => 'sync-'.($charge['id'] ?? uniqid()),
            'type' => 'charge.paid',
            'data' => [
                'id' => $charge['id'] ?? null,
                'code' => $charge['code'] ?? null,
                'amount' => $charge['amount'] ?? null,
                'paid_amount' => $charge['paid_amount'] ?? $charge['amount'] ?? null,
                'status' => 'paid',
                'payment_method' => $charge['payment_method'] ?? null,
                'order' => ['id' => $order['id'] ?? null, 'code' => $order['code'] ?? null],
                'metadata' => $charge['last_transaction']['metadata'] ?? $charge['metadata'] ?? [],
                // Necessário para o StoneRecebimentoService inferir a forma de
                // pagamento nos pedidos Listado (sem stp_opcaopagamento_id).
                'last_transaction' => $charge['last_transaction'] ?? [],
            ],
        ];
    }
}
