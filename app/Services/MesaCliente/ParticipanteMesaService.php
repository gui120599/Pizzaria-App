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
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Identificação do celular que lê o QR da mesa: entra na conta aberta com nome
 * e celular, recebe um token (guardado no cookie) e é reconhecido por ele
 * enquanto a conta estiver aberta. Para entrar é preciso o PIN da conta, que o
 * garçom passa a quem está na mesa: quem fotografou o QR e volta depois cai
 * numa conta nova, com outro PIN.
 */
class ParticipanteMesaService
{
    /** Erros de PIN por celular (IP) e conta antes de esperar. */
    public const TENTATIVAS_PIN = 5;

    public const ESPERA_PIN_SEGUNDOS = 600;

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

    /** A identificação pede o PIN da conta? (config pizzaria.mesa_cliente.exigir_pin) */
    public function exigePin(): bool
    {
        return (bool) config('pizzaria.mesa_cliente.exigir_pin');
    }

    /**
     * Identifica o celular na conta aberta da mesa, conferindo o PIN da conta.
     * O cliente é cadastrado (ou encontrado) pelo celular e entra nas pessoas
     * da mesa, como os chips do garçom — assim a conta por pessoa e o caixa
     * funcionam sem mudança.
     *
     * @return array{participante: MesaParticipante, token: string} o token só existe aqui, em texto
     *
     * @throws RuntimeException
     */
    public function entrar(Mesa $mesa, string $nome, string $celular, ?string $pin, ?string $ip = null, ?string $userAgent = null): array
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

        return DB::transaction(function () use ($mesa, $nome, $celular, $pin, $ip, $userAgent) {
            $sessao = $this->sessaoAberta($mesa->fresh());

            if (! $sessao) {
                throw new RuntimeException('Esta mesa ainda não está aberta. Chame o garçom.');
            }

            if ($this->exigePin()) {
                $this->conferirPin($sessao, (string) $pin, $ip);
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
     * PIN errado conta tentativa por celular (IP) e conta: 4 dígitos não
     * resistem a chute sem limite.
     *
     * @throws RuntimeException
     */
    private function conferirPin(SessaoMesa $sessao, string $pin, ?string $ip): void
    {
        $pin = trim($pin);
        $chave = 'mesa-pin:'.$sessao->id.':'.($ip ?? 'sem-ip');

        if ($pin === '') {
            throw new RuntimeException('Informe o PIN da mesa. Peça ao garçom.');
        }

        if (RateLimiter::tooManyAttempts($chave, self::TENTATIVAS_PIN)) {
            $minutos = (int) ceil(RateLimiter::availableIn($chave) / 60);

            throw new RuntimeException("Muitas tentativas com o PIN errado. Tente de novo em {$minutos} min ou chame o garçom.");
        }

        if (! filled($sessao->sessao_mesa_pin) || ! hash_equals((string) $sessao->sessao_mesa_pin, $pin)) {
            RateLimiter::hit($chave, self::ESPERA_PIN_SEGUNDOS);

            throw new RuntimeException('PIN incorreto. Confira com o garçom.');
        }

        RateLimiter::clear($chave);
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
