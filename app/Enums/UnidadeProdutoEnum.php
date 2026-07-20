<?php

namespace App\Enums;

enum UnidadeProdutoEnum: string
{
    case UN = 'UN';   // Unidade
    case CX = 'CX';   // Caixa
    case PC = 'PC';   // Peça
    case DZ = 'DZ';   // Dúzia
    case PCT = 'PCT'; // Pacote
    case FD = 'FD';   // Fardo
    case KIT = 'KIT'; // Kit
    case BD = 'BD';   // Balde

    // Peso
    case KG = 'KG';   // Quilograma
    case G = 'G';     // Grama
    case MG = 'MG';   // Miligrama
    case TON = 'TON'; // Tonelada

    // Volume
    case L = 'L';     // Litro
    case ML = 'ML';   // Mililitro

    // Comprimento
    case M = 'M';     // Metro
    case CM = 'CM';   // Centímetro
    case MM = 'MM';   // Milímetro

    // Área
    case M2 = 'M2';   // Metro quadrado

    // Volume cúbico
    case M3 = 'M3';   // Metro cúbico

    public function label(): string
    {
        return match ($this) {

            self::UN => 'Unidade',
            self::CX => 'Caixa',
            self::PC => 'Peça',
            self::DZ => 'Dúzia',
            self::PCT => 'Pacote',
            self::FD => 'Fardo',
            self::KIT => 'Kit',
            self::BD => 'Balde',

            self::KG => 'Quilograma',
            self::G => 'Grama',
            self::MG => 'Miligrama',
            self::TON => 'Tonelada',

            self::L => 'Litro',
            self::ML => 'Mililitro',

            self::M => 'Metro',
            self::CM => 'Centímetro',
            self::MM => 'Milímetro',

            self::M2 => 'Metro Quadrado',

            self::M3 => 'Metro Cúbico',
        };
    }
}
