<?php

namespace App\Enums;

/** Quem recebe no celular os avisos de uma mesa (parâmetro do Admin). */
enum DestinoAvisoMesaEnum: string
{
    case GARCOM_DA_MESA = 'GARCOM_DA_MESA';
    case TODOS_EM_TURNO = 'TODOS_EM_TURNO';

    public function label(): string
    {
        return match ($this) {
            self::GARCOM_DA_MESA => 'Só o garçom que abriu a mesa',
            self::TODOS_EM_TURNO => 'Todos os garçons com ponto aberto',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function opcoes(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $caso): array => [$caso->value => $caso->label()])->all();
    }
}
