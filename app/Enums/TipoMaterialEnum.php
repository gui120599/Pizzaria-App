<?php

namespace App\Enums;

enum TipoMaterialEnum: string
{
    case IMAGEM = 'imagem';
    case VIDEO = 'video';
    case TEXTO = 'texto';

    public function label(): string
    {
        return match ($this) {
            self::IMAGEM => 'Imagem',
            self::VIDEO => 'Vídeo',
            self::TEXTO => 'Texto',
        };
    }
}
