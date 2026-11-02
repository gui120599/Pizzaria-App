<?php

namespace App\Services\Push;

use App\Enums\EventoPushGarcomEnum;
use App\Exceptions\PushException;
use App\Models\DispositivoPush;
use App\Models\PushEnvio;
use App\Models\User;
use App\Support\Push\PayloadPush;
use App\Support\Push\ResultadoEnvioPush;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Ciclo de vida das inscrições Web Push. A inscrição é do aparelho: quem
 * entra (login/sincronização) assume o aparelho, quem sai o larga.
 */
class DispositivoPushService
{
    public const CHAVE_SESSAO = 'push.dispositivo_id';

    public function __construct(private readonly EnviadorPush $enviador) {}

    public static function ativo(): bool
    {
        return (bool) config('pizzaria.push.ativo');
    }

    /** Só quem opera o salão recebe push do painel do garçom. */
    public function podeReceber(User $user): bool
    {
        return self::ativo() && $user->atendeSalao();
    }

    /**
     * Registra ou atualiza a inscrição pelo hash do endpoint. Se o aparelho
     * estava com outro usuário, passa para o atual (aparelho compartilhado).
     *
     * @param  array{endpoint?: mixed, keys?: array{p256dh?: mixed, auth?: mixed}, contentEncoding?: mixed}  $inscricao  JSON do PushSubscription
     *
     * @throws PushException
     */
    public function registrar(User $user, array $inscricao, ?string $userAgent = null, bool $instalado = false): DispositivoPush
    {
        if (! $this->podeReceber($user)) {
            throw new PushException('Este usuário não recebe notificações do salão.');
        }

        $endpoint = (string) ($inscricao['endpoint'] ?? '');
        $p256dh = (string) ($inscricao['keys']['p256dh'] ?? '');
        $auth = (string) ($inscricao['keys']['auth'] ?? '');

        if (! $this->endpointPermitido($endpoint)) {
            Log::warning('Push: endpoint recusado.', ['user_id' => $user->id, 'host' => parse_url($endpoint, PHP_URL_HOST)]);

            throw new PushException('Endereço de notificação não reconhecido.');
        }

        if ($p256dh === '' || $auth === '') {
            throw new PushException('Inscrição incompleta.');
        }

        $encoding = ($inscricao['contentEncoding'] ?? null) === 'aesgcm' ? 'aesgcm' : 'aes128gcm';
        $userAgent = $userAgent !== null ? Str::limit($userAgent, 255, '') : null;

        $dispositivo = DispositivoPush::query()->firstOrNew([
            'dp_endpoint_hash' => DispositivoPush::hashDoEndpoint($endpoint),
        ]);

        if ($dispositivo->exists && $dispositivo->dp_user_id !== $user->id) {
            $dispositivo->dp_pausado_ate = null;
        }

        $dispositivo->fill([
            'dp_user_id' => $user->id,
            'dp_endpoint' => $endpoint,
            'dp_chave_p256dh' => $p256dh,
            'dp_chave_auth' => $auth,
            'dp_content_encoding' => $encoding,
            'dp_plataforma' => $this->plataformaDe($userAgent),
            'dp_navegador' => $this->navegadorDe($userAgent),
            'dp_instalado' => $instalado,
            'dp_user_agent' => $userAgent,
            'dp_ativo' => true,
            'dp_falhas_consecutivas' => 0,
            'dp_desativado_em' => null,
            'dp_motivo_desativacao' => null,
        ])->save();

        Session::put(self::CHAVE_SESSAO, $dispositivo->id);

        return $dispositivo;
    }

    /** Anti-SSRF: https e host de serviço de push conhecido (`*.` casa subdomínios). */
    public function endpointPermitido(string $endpoint): bool
    {
        $partes = parse_url($endpoint);

        if (! is_array($partes) || ($partes['scheme'] ?? '') !== 'https' || isset($partes['user']) || isset($partes['port'])) {
            return false;
        }

        $host = Str::lower((string) ($partes['host'] ?? ''));

        if ($host === '') {
            return false;
        }

        foreach ((array) config('pizzaria.push.hosts_permitidos', []) as $permitido) {
            $permitido = Str::lower((string) $permitido);

            if (Str::startsWith($permitido, '*.')) {
                if (Str::endsWith($host, substr($permitido, 1))) {
                    return true;
                }
            } elseif ($host === $permitido) {
                return true;
            }
        }

        return false;
    }

    public function desativar(DispositivoPush $dispositivo, string $motivo): void
    {
        $dispositivo->forceFill([
            'dp_ativo' => false,
            'dp_desativado_em' => now(),
            'dp_motivo_desativacao' => $motivo,
        ])->save();
    }

    /** Logout: o aparelho deixa de receber os avisos de quem saiu. */
    public function desvincularDaSessao(): void
    {
        $id = Session::pull(self::CHAVE_SESSAO);

        if ($id === null) {
            return;
        }

        DispositivoPush::query()->whereKey($id)->update([
            'dp_user_id' => null,
            'dp_pausado_ate' => null,
            'dp_desativado_em' => now(),
            'dp_motivo_desativacao' => 'logout',
            'dp_ativo' => false,
        ]);
    }

    /** O dispositivo desta sessão, se ainda for do usuário. */
    public function daSessao(User $user): ?DispositivoPush
    {
        $id = Session::get(self::CHAVE_SESSAO);

        return $id === null
            ? null
            : DispositivoPush::query()->whereKey($id)->where('dp_user_id', $user->id)->first();
    }

    public function pausar(DispositivoPush $dispositivo, \DateTimeInterface $ate): void
    {
        $dispositivo->forceFill(['dp_pausado_ate' => $ate])->save();
    }

    public function retomar(DispositivoPush $dispositivo): void
    {
        $dispositivo->forceFill(['dp_pausado_ate' => null])->save();
    }

    /**
     * Envia uma notificação de teste só para este aparelho (1 a cada 30 s por usuário).
     *
     * @throws PushException
     */
    public function enviarTeste(User $user, DispositivoPush $dispositivo): PushEnvio
    {
        $intervalo = (int) config('pizzaria.push.teste_intervalo_segundos', 30);

        if (! Cache::add("push:teste:{$user->id}", true, $intervalo)) {
            throw new PushException("Aguarde {$intervalo} segundos para enviar outro teste.");
        }

        $evento = EventoPushGarcomEnum::TESTE;
        $chave = 'teste:'.Str::uuid();
        $payload = PayloadPush::montar(
            '✅ Notificações funcionando',
            'Você vai receber os chamados do salão neste aparelho.',
            '/garcom',
            'teste',
            $chave,
        );

        $envio = PushEnvio::query()->create([
            'pe_chave' => $chave,
            'pe_evento' => $evento,
            'pe_user_id' => $user->id,
            'pe_dispositivo_id' => $dispositivo->id,
            'pe_status' => PushEnvio::PENDENTE,
            'pe_payload' => $payload,
            'pe_expira_em' => now()->addSeconds($evento->ttl()),
        ]);

        $resultado = $this->enviador->enviar($dispositivo, $payload, $evento->ttl(), 'high', 'teste');
        $this->aplicarResultado($envio, $dispositivo, $resultado);

        return $envio;
    }

    /** Grava o desfecho do envio no registro e na saúde do aparelho (404/410 e excesso de falhas desativam). */
    public function aplicarResultado(PushEnvio $envio, DispositivoPush $dispositivo, ResultadoEnvioPush $resultado): void
    {
        $status = match (true) {
            $resultado->sucesso => PushEnvio::ENVIADO,
            $resultado->inscricaoExpirada() => PushEnvio::EXPIRADO,
            default => PushEnvio::FALHOU,
        };

        $envio->forceFill([
            'pe_status' => $status,
            'pe_tentativas' => $envio->pe_tentativas + 1,
            'pe_http_status' => $resultado->httpStatus,
            'pe_erro' => $resultado->erro,
        ])->save();

        if ($resultado->sucesso) {
            $dispositivo->forceFill([
                'dp_ultimo_sucesso_em' => now(),
                'dp_falhas_consecutivas' => 0,
            ])->save();

            return;
        }

        $dispositivo->forceFill([
            'dp_ultima_falha_em' => now(),
            'dp_falhas_consecutivas' => $dispositivo->dp_falhas_consecutivas + 1,
        ])->save();

        if ($resultado->inscricaoExpirada()) {
            $this->desativar($dispositivo, 'expirado');
        } elseif ($dispositivo->dp_falhas_consecutivas > (int) config('pizzaria.push.max_falhas_consecutivas', 5)) {
            $this->desativar($dispositivo, 'falhas');
        }
    }

    private function plataformaDe(?string $userAgent): string
    {
        return match (true) {
            $userAgent === null => 'outro',
            (bool) preg_match('/iPhone|iPad|iPod/i', $userAgent) => 'ios',
            (bool) preg_match('/Android/i', $userAgent) => 'android',
            (bool) preg_match('/Windows|Macintosh|Linux|CrOS/i', $userAgent) => 'desktop',
            default => 'outro',
        };
    }

    private function navegadorDe(?string $userAgent): ?string
    {
        return match (true) {
            $userAgent === null => null,
            (bool) preg_match('/SamsungBrowser/i', $userAgent) => 'Samsung Internet',
            (bool) preg_match('/Firefox|FxiOS/i', $userAgent) => 'Firefox',
            (bool) preg_match('/Edg/i', $userAgent) => 'Edge',
            (bool) preg_match('/CriOS|Chrome/i', $userAgent) => 'Chrome',
            (bool) preg_match('/Safari/i', $userAgent) => 'Safari',
            default => 'Outro',
        };
    }
}
