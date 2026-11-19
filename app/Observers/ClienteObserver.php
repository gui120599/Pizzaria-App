<?php

namespace App\Observers;

use App\Models\Cliente;
use App\Support\Telefone;

/**
 * Garante que celular/CPF/CNPJ/CEP fiquem sempre em dígitos puros no banco,
 * independente de quem grava (ClienteForm com máscara, ClienteResolverService,
 * seeders, tinker). É isso que permite ClienteResolverService/ClienteMergeService
 * buscarem por igualdade exata em vez de LIKE.
 *
 * O celular vai além: é gravado na forma canônica (App\Support\Telefone —
 * DDD + número, sem +55 nem zero, com o nono dígito) e mantém a chave de busca
 * cliente_celular_normalizado em dia.
 */
class ClienteObserver
{
    private const CAMPOS_SOMENTE_DIGITOS = [
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

            $cliente->{$campo} = Telefone::digitos($cliente->{$campo});
        }

        if ($cliente->isDirty('cliente_celular')) {
            $celular = $cliente->cliente_celular;
            $cliente->cliente_celular = Telefone::canonico($celular) ?? Telefone::digitos($celular);
            $cliente->cliente_celular_normalizado = Telefone::normalizar($celular);
        }
    }
}
