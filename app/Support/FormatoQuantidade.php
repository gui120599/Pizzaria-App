<?php

namespace App\Support;

/**
 * Exibição da quantidade de um item de pedido.
 *
 * Pizza de sabores (meia a meia, terços) é gravada como UM item_pedido por
 * sabor com quantidade fracionada — 0,5 ou 0,333 — e sem chave que agrupe as
 * partes (ver CardapioCheckoutController, branch de sabores). Converter para
 * inteiro mostra "0 CALABRESA"; mostrar o float cru mostra "0.333". Aqui as
 * frações comuns viram o caractere de fração, que é o que a cozinha lê rápido.
 */
class FormatoQuantidade
{
    /**
     * Frações reconhecidas, com a tolerância necessária para 1/3 e 2/3, que
     * ficam gravados como dízima arredondada (0,333 / 0,667).
     *
     * @var array<string, float>
     */
    private const FRACOES = [
        '¼' => 0.25,
        '⅓' => 1 / 3,
        '½' => 0.5,
        '⅔' => 2 / 3,
        '¾' => 0.75,
    ];

    private const TOLERANCIA = 0.01;

    public static function item(float|int|string|null $quantidade): string
    {
        $valor = (float) $quantidade;

        $inteiro = (int) floor($valor + self::TOLERANCIA);
        $resto = $valor - $inteiro;

        if (abs($resto) < self::TOLERANCIA) {
            return (string) $inteiro;
        }

        foreach (self::FRACOES as $simbolo => $fracao) {
            if (abs($resto - $fracao) < self::TOLERANCIA) {
                return $inteiro > 0 ? $inteiro.$simbolo : $simbolo;
            }
        }

        return rtrim(rtrim(number_format($valor, 2, ',', ''), '0'), ',');
    }
}
