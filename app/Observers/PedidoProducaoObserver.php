<?php

namespace App\Observers;

use App\Models\Pedido;
use App\Services\Cozinha\PedidoStatusGateway;
use App\Services\Cozinha\ProducaoService;
use App\Services\Cozinha\RoteadorProducao;
use App\Support\Cozinha\SincronizacaoContexto;
use Illuminate\Support\Facades\DB;

/**
 * Pedido → produção (spec §5.5). Registrado via #[ObservedBy] em Pedido, ao
 * lado do PedidoObserver (estoque/comissão) e do HistoricoStatusPedidoObserver.
 *
 * Ignora as mudanças feitas pela própria cozinha (SincronizacaoContexto) e só
 * age depois do commit — o roteador precisa enxergar os itens gravados.
 */
class PedidoProducaoObserver
{
    public function __construct(private PedidoStatusGateway $gateway) {}

    public function created(Pedido $pedido): void
    {
        if (! SincronizacaoContexto::ativo() && $this->gateway->liberaProducao($pedido)) {
            $this->depoisDoCommit(fn () => app(RoteadorProducao::class)->gerarParaPedido($pedido));
        }
    }

    public function updated(Pedido $pedido): void
    {
        if (SincronizacaoContexto::ativo() || ! $pedido->wasChanged('pedido_status')) {
            return;
        }

        self::reagirAoStatus($pedido);
    }

    /**
     * Também chamado à mão pelos caminhos que gravam o status por query
     * builder (EntregaService::aceitar), que não disparam eventos.
     */
    public static function reagirAoStatus(Pedido $pedido): void
    {
        $gateway = app(PedidoStatusGateway::class);

        $acao = match (true) {
            $gateway->estaCancelado($pedido) => fn () => app(RoteadorProducao::class)->cancelarPedido($pedido),
            $gateway->liberaProducao($pedido) => fn () => app(RoteadorProducao::class)->gerarParaPedido($pedido),
            $gateway->jaSaiuDaCozinha($pedido) => fn () => app(ProducaoService::class)->forcarPeloPainel($pedido, expedir: true),
            $gateway->estaPronto($pedido) => fn () => app(ProducaoService::class)->forcarPeloPainel($pedido, expedir: false),
            default => null,
        };

        if ($acao !== null) {
            self::depoisDoCommit($acao);
        }
    }

    private static function depoisDoCommit(callable $acao): void
    {
        DB::afterCommit($acao);
    }
}
