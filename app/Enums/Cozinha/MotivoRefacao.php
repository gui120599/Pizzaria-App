<?php

namespace App\Enums\Cozinha;

use Filament\Support\Contracts\HasLabel;

enum MotivoRefacao: string implements HasLabel
{
    case ErroPreparo = 'erro_preparo';
    case Queimou = 'queimou';
    case Caiu = 'caiu';
    case SaborErrado = 'sabor_errado';
    case ReclamacaoCliente = 'reclamacao_cliente';
    case Outro = 'outro';

    public function getLabel(): string
    {
        return match ($this) {
            self::ErroPreparo => 'Erro no preparo',
            self::Queimou => 'Queimou',
            self::Caiu => 'Caiu',
            self::SaborErrado => 'Sabor errado',
            self::ReclamacaoCliente => 'Reclamação do cliente',
            self::Outro => 'Outro',
        };
    }
}
