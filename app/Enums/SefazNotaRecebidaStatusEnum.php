<?php

namespace App\Enums;

enum SefazNotaRecebidaStatusEnum: string
{
    case PENDENTE = 'pendente';
    case IMPORTADA = 'importada';
    case IGNORADA = 'ignorada';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::IMPORTADA => 'Importada',
            self::IGNORADA => 'Ignorada',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::PENDENTE => 'warning',
            self::IMPORTADA => 'success',
            self::IGNORADA => 'gray',
        };
    }
}
