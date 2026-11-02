<?php

namespace App\Services\Push;

use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusChamadoMesaEnum;
use App\Models\MesaChamado;
use App\Models\Pedido;
use App\Support\Push\PendenciaPush;

/**
 * Texto para quem toca numa notificação cujo assunto já foi resolvido (por
 * outro garçom ou outro aparelho). Nulo quando ainda está pendente ou a chave
 * não é reconhecida.
 */
class AvisoResolvidoService
{
    public function mensagem(?string $chave): ?string
    {
        if ($chave === null || ! preg_match('/^(chamado|aprovar|pronto):\d+$/', $chave)) {
            return null;
        }

        if (PendenciaPush::aindaVale($chave)) {
            return null;
        }

        [$tipo, $id] = explode(':', $chave);

        return match ($tipo) {
            'chamado' => $this->chamado((int) $id),
            'aprovar' => $this->aprovacao((int) $id),
            default => 'Este pedido já foi servido.',
        };
    }

    private function chamado(int $id): ?string
    {
        $chamado = MesaChamado::query()->with('atendidoPor:id,name_first')->find($id);

        return match ($chamado?->mc_status) {
            StatusChamadoMesaEnum::ATENDIDO => 'Chamado já atendido'.$this->porQuem($chamado->atendidoPor?->name_first, $chamado->mc_atendido_em?->format('H:i')).'.',
            StatusChamadoMesaEnum::CANCELADO => 'Chamado encerrado: a conta da mesa foi fechada.',
            default => null,
        };
    }

    private function aprovacao(int $id): ?string
    {
        $pedido = Pedido::query()->with('aprovador:id,name_first')->find($id);

        $rotulo = match ($pedido?->pedido_aprovacao_status) {
            StatusAprovacaoPedidoEnum::APROVADO => 'aprovado',
            StatusAprovacaoPedidoEnum::RECUSADO => 'recusado',
            default => null,
        };

        return $rotulo === null
            ? null
            : "Pedido já {$rotulo}".$this->porQuem($pedido->aprovador?->name_first, $pedido->pedido_aprovado_em?->format('H:i')).'.';
    }

    private function porQuem(?string $nome, ?string $hora): string
    {
        return ($nome ? " por {$nome}" : '').($hora ? " às {$hora}" : '');
    }
}
