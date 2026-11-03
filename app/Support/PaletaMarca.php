<?php

namespace App\Support;

/**
 * Cor primária dos painéis (garçom e admin): a brasa do "Forno aceso"
 * (resources/css/marca/forno-aceso.css). O tom 600 é a brasa (#C2410C) e o
 * 400 é a brasa-viva (#E8673A), usada como texto/ícone no modo escuro. O 500
 * é o fundo do botão primário no modo escuro. Contraste do branco: 600 = 5,2:1;
 * 500 = 4,8:1. Texto e ícone no modo escuro: 400 = 5,8:1 sobre gray-900.
 */
class PaletaMarca
{
    /** @return array<int, string> */
    public static function brasa(): array
    {
        return [
            50 => '#FFF7ED',
            100 => '#FFEDD5',
            200 => '#FED7AA',
            300 => '#FDBA74',
            400 => '#E8673A',
            500 => '#CC450E',
            600 => '#C2410C',
            700 => '#9A3412',
            800 => '#7C2D12',
            900 => '#6A2812',
            950 => '#431407',
        ];
    }

    /**
     * Azul-marinho do símbolo e do nome "Razel" da logo RazelFood. Cor de
     * apoio dos painéis internos (tom 800 = #0B3C5C, o marinho da logo).
     *
     * @return array<int, string>
     */
    public static function marinho(): array
    {
        return [
            50 => '#EFF8FD',
            100 => '#D6EEF9',
            200 => '#B0DDF2',
            300 => '#7CC4E6',
            400 => '#3FA3D1',
            500 => '#1E86B6',
            600 => '#146A96',
            700 => '#0F5478',
            800 => '#0B3C5C',
            900 => '#092F49',
            950 => '#061E30',
        ];
    }
}
