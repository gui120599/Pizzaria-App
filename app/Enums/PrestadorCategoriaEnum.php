<?php

namespace App\Enums;

enum PrestadorCategoriaEnum: string
{
    case FUNCIONARIO = 'funcionario';
    case AUTONOMO = 'autonomo';
    case FORNECEDOR = 'fornecedor';

    public function label(): string
    {
        return match ($this) {
            self::FUNCIONARIO => 'Funcionário',
            self::AUTONOMO => 'Autônomo',
            self::FORNECEDOR => 'Fornecedor de Produtos/Serviços',
        };
    }
}
