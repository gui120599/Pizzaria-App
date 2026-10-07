<?php

namespace App\Services\Garcom;

use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusMapaMesaEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Enums\TipoMesaEnum;
use App\Models\Mesa;
use App\Models\MesaChamado;
use App\Models\Pedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Monta o mapa de mesas/comandas do Painel do Garçom em poucas queries: as
 * mesas com a sessão atual, um agregado do status das rodadas por sessão e
 * o que veio do QR da mesa (pedidos esperando aprovação e chamados).
 */
class MapaMesasService
{
    /**
     * @return Collection<int, array{id: int, nome: string, tipo: TipoMesaEnum, numero: ?int, area: string, capacidade: ?int, status: StatusMapaMesaEnum, sessao_id: ?int, garcom: ?string, pessoas: ?int, minutos_ocupada: ?int, parada: bool, total: float, prontas: int, aprovar: int, querem_abrir: bool}>
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

        $sessaoIds = $mesas->pluck('mesa_sessao_atual_id')->filter()->all();
        $rodadas = $this->rodadasPorSessao($sessaoIds);
        $aprovar = $this->aguardandoAprovacaoPorSessao($sessaoIds);
        $chamados = $this->chamadosPorMesa($mesas->pluck('id')->all());
        $limiteParada = (int) config('pizzaria.salao.alerta_mesa_parada_minutos');

        return $mesas->map(function (Mesa $mesa) use ($rodadas, $aprovar, $chamados, $limiteParada) {
            $sessao = $mesa->sessaoAtual?->sessao_mesa_status === 'ABERTA' ? $mesa->sessaoAtual : null;
            $agregado = $sessao ? ($rodadas[$sessao->id] ?? null) : null;
            $aguardando = $sessao ? ($aprovar[$sessao->id] ?? 0) : 0;
            $chamadosDaMesa = $chamados[$mesa->id] ?? [];
            $status = match (true) {
                $sessao !== null && $aguardando > 0 => StatusMapaMesaEnum::APROVAR_PEDIDO,
                $sessao !== null && in_array(TipoChamadoMesaEnum::CHAMAR_GARCOM->value, $chamadosDaMesa, true) => StatusMapaMesaEnum::CHAMOU_GARCOM,
                default => $this->status($mesa, $sessao !== null, (bool) $sessao?->sessao_mesa_conta_solicitada_em, $agregado),
            };

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
                'aprovar' => $aguardando,
                'querem_abrir' => $sessao === null && in_array(TipoChamadoMesaEnum::ABRIR_MESA->value, $chamadosDaMesa, true),
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
     * O que pede o garçom agora: pedidos do QR esperando aprovação e chamados
     * das mesas dele, e pedidos de abertura de qualquer mesa (ainda não têm
     * garçom). A chave identifica o aviso para não repetir no próximo poll.
     *
     * @return list<array{chave: string, titulo: string, mesa: string}>
     */
    public function avisosDoGarcom(int $garcomId): array
    {
        $pendentes = Pedido::query()
            ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
            ->where('pedido_aprovacao_status', StatusAprovacaoPedidoEnum::PENDENTE->value)
            ->whereHas('sessaoMesa', fn ($q) => $q->where('sessao_mesa_status', 'ABERTA')->where('sessao_mesa_usuario_id', $garcomId))
            ->with('sessaoMesa.mesa:id,mesa_nome')
            ->get(['id', 'pedido_sessao_mesa_id'])
            ->map(fn (Pedido $pedido) => [
                'chave' => 'p'.$pedido->id,
                'titulo' => 'Pedido do cliente para aprovar',
                'mesa' => $pedido->sessaoMesa?->mesa?->mesa_nome ?? 'Mesa',
            ]);

        $chamados = MesaChamado::pendentes()
            ->where(fn ($q) => $q
                ->where('mc_tipo', TipoChamadoMesaEnum::ABRIR_MESA->value)
                ->orWhereHas('sessaoMesa', fn ($s) => $s->where('sessao_mesa_usuario_id', $garcomId)))
            ->with('mesa:id,mesa_nome')
            ->get(['id', 'mc_mesa_id', 'mc_tipo'])
            ->map(fn (MesaChamado $chamado) => [
                'chave' => 'c'.$chamado->id,
                'titulo' => $chamado->mc_tipo->label(),
                'mesa' => $chamado->mesa?->mesa_nome ?? 'Mesa',
            ]);

        return $pendentes->concat($chamados)->values()->all();
    }

    /**
     * @param  array<int, int>  $sessaoIds
     * @return array<int, int> sessão => pedidos do QR esperando aprovação
     */
    private function aguardandoAprovacaoPorSessao(array $sessaoIds): array
    {
        if ($sessaoIds === []) {
            return [];
        }

        return Pedido::query()
            ->whereIn('pedido_sessao_mesa_id', $sessaoIds)
            ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
            ->where('pedido_aprovacao_status', StatusAprovacaoPedidoEnum::PENDENTE->value)
            ->selectRaw('pedido_sessao_mesa_id, COUNT(*) AS total')
            ->groupBy('pedido_sessao_mesa_id')
            ->toBase()
            ->pluck('total', 'pedido_sessao_mesa_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @param  array<int, int>  $mesaIds
     * @return array<int, list<string>> mesa => tipos de chamado pendentes
     */
    private function chamadosPorMesa(array $mesaIds): array
    {
        if ($mesaIds === []) {
            return [];
        }

        return MesaChamado::pendentes()
            ->whereIn('mc_mesa_id', $mesaIds)
            ->toBase()
            ->get(['mc_mesa_id', 'mc_tipo'])
            ->groupBy('mc_mesa_id')
            ->map(fn ($linhas) => $linhas->pluck('mc_tipo')->unique()->values()->all())
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
