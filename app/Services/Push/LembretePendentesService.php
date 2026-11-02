<?php

namespace App\Services\Push;

use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\Empresa;
use App\Models\MesaChamado;
use App\Models\Pedido;
use App\Models\PushEnvio;
use App\Support\Push\EventoGarcom;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Repete o aviso de chamados e pedidos para aprovar enquanto estiverem
 * PENDENTES (plano, seção 8.5). O avanço é derivado de push_envios: cada
 * lembrete tem a chave `{assunto}:lembrete:{n}`, então rodar o comando duas
 * vezes no mesmo minuto não duplica. Os destinatários são recalculados a cada
 * lembrete (quem abriu o ponto depois passa a receber).
 */
class LembretePendentesService
{
    public function __construct(private readonly NotificadorGarcom $notificador) {}

    /** @return int quantos assuntos receberam um novo lembrete */
    public function executar(): int
    {
        $config = Empresa::configuracaoPush();

        if (! $config['repetir']) {
            return 0;
        }

        $lembrados = 0;

        MesaChamado::pendentes()->with('mesa:id,mesa_nome', 'sessaoMesa:id,sessao_mesa_usuario_id')->get()
            ->each(function (MesaChamado $chamado) use ($config, &$lembrados): void {
                $lembrados += $this->lembrar(
                    $chamado->created_at,
                    "chamado:{$chamado->id}",
                    fn (int $n, int $min): EventoGarcom => EventoGarcom::chamado($chamado, $n, $min),
                    $config,
                );
            });

        Pedido::query()
            ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
            ->where('pedido_aprovacao_status', StatusAprovacaoPedidoEnum::PENDENTE->value)
            ->whereHas('sessaoMesa', fn ($q) => $q->where('sessao_mesa_status', 'ABERTA'))
            ->with('sessaoMesa.mesa:id,mesa_nome')
            ->get()
            ->each(function (Pedido $pedido) use ($config, &$lembrados): void {
                $lembrados += $this->lembrar(
                    $pedido->created_at,
                    "aprovar:{$pedido->id}",
                    fn (int $n, int $min): EventoGarcom => EventoGarcom::aprovacao($pedido, $n, $min),
                    $config,
                );
            });

        return $lembrados;
    }

    /**
     * @param  Closure(int, int): EventoGarcom  $evento  recebe o número do lembrete e os minutos de espera
     * @param  array{intervalo_min: int, repetir_max: int}  $config
     */
    private function lembrar(Carbon $desde, string $base, Closure $evento, array $config): int
    {
        $envios = PushEnvio::query()
            ->where(fn ($q) => $q->where('pe_chave', $base)->orWhere('pe_chave', 'like', "{$base}:lembrete:%"))
            ->get(['pe_chave', 'created_at']);

        $lembretesFeitos = $envios->pluck('pe_chave')->unique()->filter(fn (string $chave): bool => str_contains($chave, ':lembrete:'))->count();

        if ($lembretesFeitos >= $config['repetir_max']) {
            return 0;
        }

        $ultimoAviso = $envios->max('created_at') ?? $desde;

        if (Carbon::parse($ultimoAviso)->addMinutes($config['intervalo_min'])->isFuture()) {
            return 0;
        }

        $espera = max(1, (int) $desde->diffInMinutes(now()));

        return $this->notificador->enfileirar($evento($lembretesFeitos + 1, $espera), true) !== [] ? 1 : 0;
    }
}
