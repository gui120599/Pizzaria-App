<?php

namespace App\Services\MesaCliente;

use App\Exceptions\AcessoMesaNegadoException;
use App\Models\Mesa;
use App\Models\MesaParticipante;
use App\Models\SessaoMesa;
use App\Models\SessaoMesaCliente;
use App\Models\User;
use App\Services\ClienteResolverService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Identificação do celular que lê o QR da mesa: entra na conta aberta com nome
 * e celular, recebe um token (guardado no cookie) e é reconhecido por ele
 * enquanto a conta estiver aberta. Quem fotografou o QR e volta depois cai
 * numa conta nova e precisa se identificar de novo.
 */
class ParticipanteMesaService
{
    public function __construct(private ClienteResolverService $clientes) {}

    /** Conta aberta da mesa agora, se houver. */
    public function sessaoAberta(Mesa $mesa): ?SessaoMesa
    {
        $sessao = $mesa->mesa_sessao_atual_id ? SessaoMesa::find($mesa->mesa_sessao_atual_id) : null;

        return $sessao?->sessao_mesa_status === 'ABERTA' ? $sessao : null;
    }

    /** O pedido pelo celular está ligado no sistema e nesta mesa? */
    public function pedidoPeloCelularLigado(Mesa $mesa): bool
    {
        return (bool) config('pizzaria.mesa_cliente.ativo')
            && $mesa->mesa_pedido_cliente_ativo
            && $mesa->mesa_status !== 'INATIVA';
    }

    /**
     * Identifica o celular na conta aberta da mesa. O cliente é cadastrado (ou
     * encontrado) pelo celular e entra nas pessoas da mesa, como os chips do
     * garçom — assim a conta por pessoa e o caixa funcionam sem mudança.
     *
     * @return array{participante: MesaParticipante, token: string} o token só existe aqui, em texto
     *
     * @throws RuntimeException
     */
    public function entrar(Mesa $mesa, string $nome, string $celular, ?string $ip = null, ?string $userAgent = null): array
    {
        $nome = trim($nome);
        $celular = (string) preg_replace('/\D/', '', $celular);

        if ($nome === '') {
            throw new RuntimeException('Informe seu nome.');
        }

        if (! in_array(strlen($celular), [10, 11], true)) {
            throw new RuntimeException('Informe o celular com DDD.');
        }

        if (! $this->pedidoPeloCelularLigado($mesa)) {
            throw new RuntimeException('O pedido pelo celular está desligado nesta mesa. Chame o garçom.');
        }

        return DB::transaction(function () use ($mesa, $nome, $celular, $ip, $userAgent) {
            $sessao = $this->sessaoAberta($mesa->fresh());

            if (! $sessao) {
                throw new RuntimeException('Esta mesa ainda não está aberta. Chame o garçom.');
            }

            $cliente = $this->clientes->resolverOuCriar(['nome' => $nome, 'celular' => $celular]);
            $token = MesaParticipante::gerarToken();

            $participante = MesaParticipante::create([
                'mp_sessao_mesa_id' => $sessao->id,
                'mp_cliente_id' => $cliente->id,
                'mp_nome' => mb_substr($nome, 0, 120),
                'mp_celular' => $celular,
                'mp_token_hash' => MesaParticipante::hashDoToken($token),
                'mp_ip' => $ip,
                'mp_user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'mp_ultimo_acesso_em' => Carbon::now(),
            ]);

            SessaoMesaCliente::firstOrCreate([
                'smc_sessao_mesa_id' => $sessao->id,
                'smc_cliente_id' => $cliente->id,
            ]);

            return ['participante' => $participante, 'token' => $token];
        });
    }

    /**
     * Reconhece o celular pelo token do cookie, nesta mesa.
     *
     * @throws AcessoMesaNegadoException
     */
    public function resolver(Mesa $mesa, ?string $token): MesaParticipante
    {
        $participante = filled($token) ? MesaParticipante::porToken((string) $token) : null;

        if (! $participante) {
            throw AcessoMesaNegadoException::semIdentificacao();
        }

        $sessao = $participante->sessaoMesa;

        if (! $sessao || $sessao->sessao_mesa_status !== 'ABERTA') {
            throw AcessoMesaNegadoException::contaEncerrada();
        }

        // Conta transferida pelo garçom: o celular segue a conta, não o QR.
        if ((int) $sessao->sessao_mesa_mesa_id !== (int) $mesa->id) {
            throw AcessoMesaNegadoException::mesaTrocada(Mesa::whereKey($sessao->sessao_mesa_mesa_id)->value('mesa_codigo_qr'));
        }

        if ($participante->estaBloqueado()) {
            throw AcessoMesaNegadoException::bloqueado();
        }

        // Último acesso com folga de 1 minuto: o polling não grava a cada 15s.
        if ($participante->mp_ultimo_acesso_em === null || $participante->mp_ultimo_acesso_em->lt(Carbon::now()->subMinute())) {
            $participante->forceFill(['mp_ultimo_acesso_em' => Carbon::now()])->saveQuietly();
        }

        return $participante;
    }

    /** O garçom tira o celular da conta (ex.: pedido de brincadeira). */
    public function bloquear(MesaParticipante $participante, User $garcom): MesaParticipante
    {
        $participante->update([
            'mp_bloqueado_em' => Carbon::now(),
            'mp_bloqueado_por_id' => $garcom->id,
        ]);

        return $participante;
    }

    public function desbloquear(MesaParticipante $participante): MesaParticipante
    {
        $participante->update(['mp_bloqueado_em' => null, 'mp_bloqueado_por_id' => null]);

        return $participante;
    }
}
