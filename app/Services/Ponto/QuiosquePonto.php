<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\OrigemMarcacao;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\PontoMarcacao;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Tablet da loja (quiosque): o colaborador se identifica só pelo PIN. O
 * tablet precisa estar pareado (token de config('ponto.quiosque.tokens') em
 * cookie) e erros de PIN são limitados por tablet.
 */
class QuiosquePonto
{
    public const COOKIE = 'ponto_quiosque';

    public function __construct(private readonly PontoService $ponto) {}

    public function tokenValido(?string $token): bool
    {
        if (blank($token)) {
            return false;
        }

        foreach ((array) config('ponto.quiosque.tokens', []) as $autorizado) {
            if (hash_equals((string) $autorizado, (string) $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws PontoException PIN errado, tablet bloqueado por tentativas ou colaborador sem PIN cadastrado
     */
    public function identificar(string $pin, string $token): Colaborador
    {
        $chave = self::chaveLimite($token);
        $tentativas = (int) config('ponto.quiosque.tentativas_pin', 5);

        if (RateLimiter::tooManyAttempts($chave, $tentativas)) {
            $minutos = (int) ceil(RateLimiter::availableIn($chave) / 60);

            throw new PontoException("Muitas tentativas erradas. Aguarde {$minutos} min ou chame o gerente.");
        }

        $pin = preg_replace('/\D/', '', $pin) ?? '';
        $colaborador = $pin === '' ? null : Colaborador::doPin($pin);

        if ($colaborador === null) {
            RateLimiter::hit($chave, (int) config('ponto.quiosque.bloqueio_minutos', 5) * 60);
            $restantes = max(0, $tentativas - RateLimiter::attempts($chave));

            throw new PontoException($restantes > 0
                ? "PIN não reconhecido. Restam {$restantes} tentativa(s)."
                : 'PIN não reconhecido. Tablet bloqueado por alguns minutos.');
        }

        RateLimiter::clear($chave);

        return $colaborador;
    }

    /** @throws PontoException */
    public function registrar(Colaborador $colaborador, ?string $ip, ?string $userAgent): PontoMarcacao
    {
        return $this->ponto->registrarMarcacao($colaborador, OrigemMarcacao::Quiosque, [
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    private static function chaveLimite(string $token): string
    {
        return 'ponto-quiosque:'.hash('sha256', $token);
    }
}
