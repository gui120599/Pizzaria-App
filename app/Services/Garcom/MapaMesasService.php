<?php

namespace App\Services\Garcom;

use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusMapaMesaEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\TipoMesaEnum;
use App\Models\Mesa;
use App\Models\Pedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Monta o mapa de mesas/comandas do Painel do Garçom em duas queries: as
 * mesas com a sessão atual e um agregado do status das rodadas por sessão.
 */
class MapaMesasService
{
    /**
     * @return Collection<int, array{id: int, nome: string, tipo: TipoMesaEnum, numero: ?int, area: string, capacidade: ?int, status: StatusMapaMesaEnum, sessao_id: ?int, garcom: ?string, pessoas: ?int, minutos_ocupada: ?int, parada: bool, total: float, prontas: int}>
     */
    public function mapa(?TipoMesaEnum $tipo = null): Collection
    {
        $mesas = Mesa::query()
            ->with(['sessaoAtual' => fn ($q) => $q
                ->select('id', 'sessao_mesa_status', 'sessao_mesa_usuario_id', 'sessao_mesa_pessoas', 'sessao_mesa_conta_solicitada_em', 'created_at')
                ->with('garcom:id,name_first,name')])
            ->when($tipo, fn ($q, TipoMesaEnum $t) => $q->where('mesa_tipo', $t->value))
            ->orderBy('mesa_area')
            ->orderByRaw('mesa_numero IS NULL, mesa_numero')
            ->orderBy('mesa_nome')
            ->get();

        $rodadas = $this->rodadasPorSessao($mesas->pluck('mesa_sessao_atual_id')->filter()->all());
        $limiteParada = (int) config('pizzaria.salao.alerta_mesa_parada_minutos');

        return $mesas->map(function (Mesa $mesa) use ($rodadas, $limiteParada) {
            $sessao = $mesa->sessaoAtual?->sessao_mesa_status === 'ABERTA' ? $mesa->sessaoAtual : null;
            $agregado = $sessao ? ($rodadas[$sessao->id] ?? null) : null;
            $status = $this->status($mesa, $sessao !== null, (bool) $sessao?->sessao_mesa_conta_solicitada_em, $agregado);

            $ultimaAtividade = $sessao
                ? max($sessao->created_at, $agregado['ultima_rodada'] ?? $sessao->created_at)
                : null;

            return [
                'id' => $mesa->id,
                'nome' => $mesa->mesa_nome,
                'tipo' => $mesa->mesa_tipo ?? TipoMesaEnum::MESA,
                'numero' => $mesa->mesa_numero,
                'area' => $mesa->mesa_area ?: 'Salão',
                'capacidade' => $mesa->mesa_capacidade,
                'status' => $status,
                'sessao_id' => $sessao?->id,
                'garcom' => $sessao?->garcom?->name_first ?: $sessao?->garcom?->name,
                'pessoas' => $sessao?->sessao_mesa_pessoas,
                'minutos_ocupada' => $sessao ? (int) $sessao->created_at->diffInMinutes(Carbon::now()) : null,
                'parada' => $ultimaAtividade !== null
                    && in_array($status, [StatusMapaMesaEnum::AGUARDANDO_PEDIDO, StatusMapaMesaEnum::ATENDIDA], true)
                    && $ultimaAtividade->diffInMinutes(Carbon::now()) >= $limiteParada,
                'total' => (float) ($agregado['total'] ?? 0),
                'prontas' => (int) ($agregado['prontas'] ?? 0),
            ];
        });
    }

    /**
     * Rodadas de mesa e retiradas PRONTO deste garçom — alimenta o aviso de
     * "pedido pronto".
     *
     * @return array<int, array{id: int, mesa: string}>
     */
    public function prontasDoGarcom(int $garcomId): array
    {
        return Pedido::query()
            ->where('pedido_status', StatusPedidoEnum::PRONTO->value)
            ->where('pedido_usuario_garcom_id', $garcomId)
            ->where(fn ($q) => $q
                ->whereNotNull('pedido_sessao_mesa_id')
                ->orWhere('pedido_origem', PedidoOrigemEnum::GARCOM->value))
            ->with('sessaoMesa:id,sessao_mesa_mesa_id', 'sessaoMesa.mesa:id,mesa_nome', 'cliente:id,cliente_nome')
            ->get(['id', 'pedido_sessao_mesa_id', 'pedido_cliente_id'])
            ->map(fn (Pedido $pedido) => [
                'id' => $pedido->id,
                'mesa' => $pedido->pedido_sessao_mesa_id
                    ? ($pedido->sessaoMesa?->mesa?->mesa_nome ?? 'Mesa')
                    : 'Retirada: '.($pedido->cliente?->cliente_nome ?? '#'.$pedido->id),
            ])
            ->all();
    }

    /**
     * @param  array<int, int>  $sessaoIds
     * @return array<int, array{em_preparo: int, prontas: int, enviadas: int, total: float, ultima_rodada: ?Carbon}>
     */
    private function rodadasPorSessao(array $sessaoIds): array
    {
        if ($sessaoIds === []) {
            return [];
        }

        $emPreparo = [StatusPedidoEnum::ABERTO->value, StatusPedidoEnum::PREPARANDO->value];
        $naoContam = [StatusPedidoEnum::INICIADO->value, StatusPedidoEnum::CANCELADO->value];

        return Pedido::query()
            ->whereIn('pedido_sessao_mesa_id', $sessaoIds)
            ->whereNotIn('pedido_status', $naoContam)
            ->selectRaw('pedido_sessao_mesa_id')
            ->selectRaw('SUM(pedido_status IN (?, ?)) AS em_preparo', $emPreparo)
            ->selectRaw('SUM(pedido_status = ?) AS prontas', [StatusPedidoEnum::PRONTO->value])
            ->selectRaw('COUNT(*) AS enviadas')
            ->selectRaw('SUM(pedido_valor_total) AS total')
            ->selectRaw('MAX(pedido_datahora_abertura) AS ultima_rodada')
            ->groupBy('pedido_sessao_mesa_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($linha) => [(int) $linha->pedido_sessao_mesa_id => [
                'em_preparo' => (int) $linha->em_preparo,
                'prontas' => (int) $linha->prontas,
                'enviadas' => (int) $linha->enviadas,
                'total' => round((float) $linha->total, 2),
                'ultima_rodada' => $linha->ultima_rodada ? Carbon::parse($linha->ultima_rodada) : null,
            ]])
            ->all();
    }

    /**
     * @param  ?array{em_preparo: int, prontas: int, enviadas: int}  $rodadas
     */
    private function status(Mesa $mesa, bool $ocupada, bool $contaSolicitada, ?array $rodadas): StatusMapaMesaEnum
    {
        return match (true) {
            $mesa->mesa_status === 'INATIVA' => StatusMapaMesaEnum::INATIVA,
            ! $ocupada => StatusMapaMesaEnum::LIVRE,
            $contaSolicitada => StatusMapaMesaEnum::CONTA_SOLICITADA,
            ($rodadas['prontas'] ?? 0) > 0 => StatusMapaMesaEnum::PRONTO,
            ($rodadas['em_preparo'] ?? 0) > 0 => StatusMapaMesaEnum::EM_PREPARO,
            ($rodadas['enviadas'] ?? 0) === 0 => StatusMapaMesaEnum::AGUARDANDO_PEDIDO,
            default => StatusMapaMesaEnum::ATENDIDA,
        };
    }
}
