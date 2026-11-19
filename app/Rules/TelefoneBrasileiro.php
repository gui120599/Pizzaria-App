<?php

namespace App\Rules;

use App\Support\Telefone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Telefone brasileiro reconhecível (DDD válido + número de celular ou fixo),
 * em qualquer formatação aceita por App\Support\Telefone. Sem isso, um número
 * sem DDD ou com dígito a menos vira um cadastro que nunca mais é encontrado.
 */
class TelefoneBrasileiro implements ValidationRule
{
    public function __construct(private bool $somenteCelular = false) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $canonico = Telefone::canonico(is_scalar($value) ? (string) $value : null);

        if ($canonico === null || ($this->somenteCelular && strlen($canonico) !== 11)) {
            $fail($this->somenteCelular
                ? 'Informe um celular válido com DDD, ex.: (62) 99999-9999.'
                : 'Informe um telefone válido com DDD, ex.: (62) 99999-9999.');
        }
    }
}
