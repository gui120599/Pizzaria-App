<?php

namespace App\Observers;

use App\Models\Cliente;

/**
 * Garante que celular/CPF/CNPJ/CEP fiquem sempre em dígitos puros no banco,
 * independente de quem grava (ClienteForm com máscara, ClienteResolverService,
 * seeders, tinker). É isso que permite ClienteResolverService/ClienteMergeService
 * buscarem por igualdade exata em vez de LIKE.
 */
class ClienteObserver
{
    private const CAMPOS_SOMENTE_DIGITOS = [
        'cliente_celular',
        'cliente_cpf',
        'cliente_cnpj',
        'cliente_cep',
    ];

    public function saving(Cliente $cliente): void
    {
        foreach (self::CAMPOS_SOMENTE_DIGITOS as $campo) {
            if (! $cliente->isDirty($campo)) {
                continue;
            }

            $valor = $cliente->{$campo};
            $digitos = $valor !== null ? preg_replace('/\D/', '', (string) $valor) : null;
            $cliente->{$campo} = filled($digitos) ? $digitos : null;
        }
    }
}
