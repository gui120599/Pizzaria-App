<?php

namespace App\Services\MesaCliente;

use App\Enums\StatusChamadoMesaEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Models\Mesa;
use App\Models\MesaChamado;
use App\Models\MesaParticipante;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Chamados do QR para o garçom. Um PENDENTE por mesa e tipo: tocar de novo
 * em "Chamar garçom" não enfileira outro. "Pedir a conta" também marca a
 * conta como solicitada (a mesa fica roxa no mapa, como quando o garçom marca).
 */
class MesaChamadoService
{
    public function __construct(private ParticipanteMesaService $participantes) {}

    /**
     * @param  ?MesaParticipante  $participante  obrigatório, menos no ABRIR_MESA (ainda não há conta)
     *
     * @throws RuntimeException
     */
    public function abrir(Mesa $mesa, TipoChamadoMesaEnum $tipo, ?MesaParticipante $participante = null, ?string $ip = null): MesaChamado
    {
        return DB::transaction(function () use ($mesa, $tipo, $participante, $ip) {
            $mesa = Mesa::whereKey($mesa->getKey())->lockForUpdate()->firstOrFail();
            $sessao = $this->participantes->sessaoAberta($mesa);

            if ($tipo === TipoChamadoMesaEnum::ABRIR_MESA && $sessao) {
                throw new RuntimeException('Esta mesa já está aberta.');
            }

            if ($tipo !== TipoChamadoMesaEnum::ABRIR_MESA
                && (! $sessao || ! $participante || (int) $participante->mp_sessao_mesa_id !== (int) $sessao->id)) {
                throw new RuntimeException('A conta desta mesa não está aberta.');
            }

            $pendente = MesaChamado::pendentes()
                ->where('mc_mesa_id', $mesa->id)
                ->where('mc_tipo', $tipo->value)
                ->first();

            if ($pendente) {
                return $pendente;
            }

            $chamado = MesaChamado::create([
                'mc_mesa_id' => $mesa->id,
                'mc_sessao_mesa_id' => $sessao?->id,
                'mc_mesa_participante_id' => $participante?->id,
                'mc_tipo' => $tipo,
                'mc_status' => StatusChamadoMesaEnum::PENDENTE,
                'mc_ip' => $ip,
            ]);

            if ($tipo === TipoChamadoMesaEnum::PEDIR_CONTA) {
                app(AtendimentoMesaService::class)->solicitarConta($sessao);
            }

            return $chamado;
        });
    }

    /** @return bool false quando outro garçom já tinha atendido */
    public function atender(MesaChamado $chamado, User $garcom): bool
    {
        return MesaChamado::whereKey($chamado->getKey())
            ->where('mc_status', StatusChamadoMesaEnum::PENDENTE->value)
            ->update([
                'mc_status' => StatusChamadoMesaEnum::ATENDIDO->value,
                'mc_atendido_por_id' => $garcom->id,
                'mc_atendido_em' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]) > 0;
    }

    /** A mesa foi aberta (por qualquer tela): o pedido de abertura está atendido. */
    public function atenderAberturaDaMesa(int $mesaId, ?int $usuarioId): int
    {
        return MesaChamado::pendentes()
            ->where('mc_mesa_id', $mesaId)
            ->where('mc_tipo', TipoChamadoMesaEnum::ABRIR_MESA->value)
            ->update([
                'mc_status' => StatusChamadoMesaEnum::ATENDIDO->value,
                'mc_atendido_por_id' => $usuarioId,
                'mc_atendido_em' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }

    /** A conta fechou: chamados dela que ninguém atendeu deixam a fila. */
    public function encerrarDaSessao(SessaoMesa $sessao): int
    {
        return MesaChamado::pendentes()
            ->where('mc_sessao_mesa_id', $sessao->id)
            ->update([
                'mc_status' => StatusChamadoMesaEnum::CANCELADO->value,
                'updated_at' => Carbon::now(),
            ]);
    }
}
