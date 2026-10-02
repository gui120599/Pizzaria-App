<?php

namespace App\Services\Garcom;

use App\Exceptions\AutorizacaoNegadaException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Conferência de PIN com bloqueio por tentativas, por usuário — usado no
 * login rápido do Painel do Garçom e na autorização de gerente.
 */
class PinService
{
    /** @throws AutorizacaoNegadaException quando o PIN está bloqueado ou não confere. */
    public function verificar(User $user, string $pin): void
    {
        $chave = $this->chave($user);

        if (RateLimiter::tooManyAttempts($chave, (int) config('pizzaria.salao.pin_tentativas'))) {
            $minutos = (int) ceil(RateLimiter::availableIn($chave) / 60);

            throw new AutorizacaoNegadaException("PIN bloqueado por excesso de tentativas. Tente de novo em {$minutos} min.");
        }

        if (! $user->conferePin($pin)) {
            RateLimiter::hit($chave, (int) config('pizzaria.salao.pin_bloqueio_segundos'));

            throw new AutorizacaoNegadaException($user->temPin() ? 'PIN incorreto.' : 'Usuário sem PIN cadastrado.');
        }

        RateLimiter::clear($chave);
    }

    private function chave(User $user): string
    {
        return 'pin:'.$user->id;
    }
}
