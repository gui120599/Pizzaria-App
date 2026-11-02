<?php

namespace App\Support\Push;

use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\MesaChamado;
use App\Models\Pedido;
use App\Services\Garcom\MapaMesasService;

/**
 * O assunto do push ainda precisa do garçom? Conferido logo antes de enviar:
 * chamado já atendido, pedido já aprovado ou já servido não vira notificação.
 * A chave segue o formato `tipo:id[:lembrete:n]`.
 */
final class PendenciaPush
{
    /** `tipo:id` sem o sufixo de lembrete — identifica o assunto, não o aviso. */
    public static function chaveBase(string $chave): string
    {
        return implode(':', array_slice(explode(':', $chave), 0, 2));
    }

    /**
     * Assuntos que ainda pedem o garçom agora. O navegador usa a lista para
     * fechar as notificações já resolvidas (atendidas em outro aparelho).
     *
     * @return list<string>
     */
    public static function chavesAbertas(): array
    {
        $chamados = MesaChamado::pendentes()->pluck('id')->map(fn ($id): string => "chamado:{$id}");

        $aprovar = Pedido::query()
            ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
            ->where('pedido_aprovacao_status', StatusAprovacaoPedidoEnum::PENDENTE->value)
            ->pluck('id')
            ->map(fn ($id): string => "aprovar:{$id}");

        $prontos = Pedido::query()
            ->tap(fn ($q) => MapaMesasService::prontosParaServir($q))
            ->where(fn ($q) => $q
                ->whereNotNull('pedido_sessao_mesa_id')
                ->orWhere('pedido_origem', PedidoOrigemEnum::GARCOM->value))
            ->pluck('id')
            ->map(fn ($id): string => "pronto:{$id}");

        return $chamados->concat($aprovar)->concat($prontos)->values()->all();
    }

    public static function aindaVale(string $chave): bool
    {
        [$tipo, $id] = array_pad(explode(':', self::chaveBase($chave)), 2, null);
        $id = (int) $id;

        return match ($tipo) {
            'chamado' => MesaChamado::pendentes()->whereKey($id)->exists(),
            'aprovar' => Pedido::query()
                ->whereKey($id)
                ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
                ->where('pedido_aprovacao_status', StatusAprovacaoPedidoEnum::PENDENTE->value)
                ->exists(),
            'pronto' => Pedido::query()->tap(fn ($q) => MapaMesasService::prontosParaServir($q))->whereKey($id)->exists(),
            default => true,
        };
    }
}
