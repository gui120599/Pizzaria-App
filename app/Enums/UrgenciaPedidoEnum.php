<?php

namespace App\Enums;

use App\Models\Pedido;
use Illuminate\Support\Carbon;

/**
 * Semáforo de SLA do pedido no Painel de Pedidos.
 *
 * Mede quanto tempo o pedido está parado NO STATUS ATUAL — não desde a
 * abertura. Um pedido aberto há 40 minutos que entrou em PREPARANDO agora está
 * no prazo; o que está em PREPARANDO há 40 minutos, não.
 *
 * Os limites vivem em config('pizzaria.pedidos.sla'), por status, em minutos.
 * Status sem entrada na config nunca acusa urgência.
 *
 * A cor é aplicada como borda fina no card, de propósito: as colunas do painel
 * já são coloridas por status, e pintar o card inteiro tira a legibilidade do
 * conteúdo, que é o que a cozinha precisa ler.
 */
enum UrgenciaPedidoEnum: string
{
    case NORMAL = 'normal';
    case ATENCAO = 'atencao';
    case ATRASADO = 'atrasado';

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'No prazo',
            self::ATENCAO => 'Atenção',
            self::ATRASADO => 'Atrasado',
        };
    }

    /** Paleta do Filament, para badges. */
    public function cor(): string
    {
        return match ($this) {
            self::NORMAL => 'gray',
            self::ATENCAO => 'warning',
            self::ATRASADO => 'danger',
        };
    }

    /** Classe Tailwind da borda esquerda do card. */
    public function classeBorda(): string
    {
        return match ($this) {
            self::NORMAL => 'border-l-4 border-l-transparent',
            self::ATENCAO => 'border-l-4 border-l-amber-400',
            self::ATRASADO => 'border-l-4 border-l-red-500',
        };
    }

    public function ehNormal(): bool
    {
        return $this === self::NORMAL;
    }

    /**
     * Classifica o pedido a partir dos minutos decorridos no status atual.
     */
    public static function paraPedido(Pedido $pedido, StatusPedidoEnum $status, ?Carbon $agora = null): self
    {
        $minutos = self::minutosNoStatus($pedido, $status, $agora);

        if ($minutos === null) {
            return self::NORMAL;
        }

        $limites = config('pizzaria.pedidos.sla.'.$status->value);

        if (! is_array($limites)) {
            return self::NORMAL;
        }

        return match (true) {
            $minutos >= (int) ($limites['atrasado'] ?? PHP_INT_MAX) => self::ATRASADO,
            $minutos >= (int) ($limites['atencao'] ?? PHP_INT_MAX) => self::ATENCAO,
            default => self::NORMAL,
        };
    }

    /**
     * Minutos decorridos desde a entrada no status, ou null quando não há
     * data/hora de referência.
     *
     * INICIADO não tem coluna própria no $fillable do Pedido (a
     * `pedido_datahora_incio` do schema não é preenchida pela aplicação), então
     * cai no `created_at`.
     */
    public static function minutosNoStatus(Pedido $pedido, StatusPedidoEnum $status, ?Carbon $agora = null): ?int
    {
        $campo = $status->campoDataHora();

        $desde = $campo ? $pedido->{$campo} : $pedido->created_at;

        if (! $desde instanceof Carbon) {
            return null;
        }

        return max(0, $desde->diffInMinutes($agora ?? Carbon::now()));
    }
}
