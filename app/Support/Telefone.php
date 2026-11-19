<?php

namespace App\Support;

/**
 * Regra única de telefone brasileiro: é a partir daqui que todos os pontos de
 * atendimento (cardápio, Atender Pedido, garçom, Nova Venda, cadastro) comparam,
 * gravam e exibem o celular do cliente.
 *
 * Forma canônica = somente dígitos, sem código do país e sem prefixo de
 * operadora: DDD + número. Celular tem 11 dígitos (DDD + 9 + 8), fixo tem 10
 * (DDD + 8, começando de 2 a 5).
 *
 * Equivalências aceitas (plano de numeração da Anatel):
 * - código do país 55 (com ou sem "+", ou "00 55" de discagem internacional);
 * - zero de discagem interurbana ("0 62 ...") e código de operadora ("0 21 62 ...");
 * - celular antigo de 8 dígitos começando de 6 a 9: desde 2016 todo celular
 *   recebeu o 9 na frente, então "(62) 8123-4567" é o mesmo "(62) 98123-4567".
 *
 * O que não se encaixa (sem DDD, DDD inexistente, dígitos a mais ou a menos,
 * número estrangeiro) NÃO é adivinhado: fica sem forma canônica e só bate com
 * os mesmos dígitos exatos.
 */
final class Telefone
{
    /** DDDs existentes no Brasil. */
    private const DDDS = [
        11, 12, 13, 14, 15, 16, 17, 18, 19,
        21, 22, 24, 27, 28,
        31, 32, 33, 34, 35, 37, 38,
        41, 42, 43, 44, 45, 46, 47, 48, 49,
        51, 53, 54, 55,
        61, 62, 63, 64, 65, 66, 67, 68, 69,
        71, 73, 74, 75, 77, 79,
        81, 82, 83, 84, 85, 86, 87, 88, 89,
        91, 92, 93, 94, 95, 96, 97, 98, 99,
    ];

    /**
     * Expressão do x-mask:dynamic (Alpine) usada em todos os campos de
     * telefone: "(62) 9999-9999" até 10 dígitos e "(62) 99999-9999" com 11.
     * Colado/autopreenchido com +55 ou com o zero do DDD, mostra o prefixo em
     * vez de cortar o fim do número — quem tira o prefixo é o servidor.
     */
    public const MASCARA_ALPINE = <<<'JS'
        (() => { const d = $input.replace(/\D/g, ''); if (d.length > 11 && d.startsWith('55')) { return d.length > 12 ? '+99 (99) 99999-9999' : '+99 (99) 9999-99999'; } if (d.length > 10 && d.startsWith('0')) { return d.length > 11 ? '9 (99) 99999-9999' : '9 (99) 9999-99999'; } return d.length > 10 ? '(99) 99999-9999' : '(99) 9999-99999'; })()
        JS;

    /** Somente os dígitos, ou null quando não sobra nenhum. */
    public static function digitos(?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor) ?? '';

        return $digitos !== '' ? $digitos : null;
    }

    /**
     * Forma canônica (DDD + número, 10 ou 11 dígitos) ou null quando o valor
     * não é um telefone brasileiro reconhecível sem adivinhação.
     */
    public static function canonico(?string $valor): ?string
    {
        $digitos = self::digitos($valor);

        if ($digitos === null) {
            return null;
        }

        $internacional = str_starts_with(ltrim((string) $valor), '+') || str_starts_with($digitos, '00');

        if (str_starts_with($digitos, '00')) {
            $digitos = substr($digitos, 2);
        }

        if ($internacional) {
            if (! str_starts_with($digitos, '55')) {
                return null;
            }
            $digitos = substr($digitos, 2);
        } elseif (str_starts_with($digitos, '0')) {
            // 0 + DDD + número (11/12 dígitos) ou 0 + operadora + DDD + número (13/14).
            $digitos = match (strlen($digitos)) {
                11, 12 => substr($digitos, 1),
                13, 14 => substr($digitos, 3),
                default => $digitos,
            };
        } elseif (in_array(strlen($digitos), [12, 13], true) && str_starts_with($digitos, '55')) {
            // 12/13 dígitos nunca são DDD + número; com 55 na frente é o código do país.
            $digitos = substr($digitos, 2);
        }

        return self::canonicoNacional($digitos);
    }

    /**
     * Chave de busca/comparação: a forma canônica quando reconhecida; senão os
     * próprios dígitos (só bate com o mesmo número digitado igual).
     */
    public static function normalizar(?string $valor): ?string
    {
        return self::canonico($valor) ?? self::digitos($valor);
    }

    public static function ehCelular(?string $valor): bool
    {
        return strlen((string) self::canonico($valor)) === 11;
    }

    /** "(62) 99999-9999" / "(62) 3333-4444"; o que não é reconhecido volta como veio. */
    public static function formatar(?string $valor): ?string
    {
        $canonico = self::canonico($valor);

        if ($canonico === null) {
            return filled($valor) ? $valor : null;
        }

        return sprintf('(%s) %s-%s', substr($canonico, 0, 2), substr($canonico, 2, -4), substr($canonico, -4));
    }

    /** Número para o link wa.me (55 + canônico), ou null se não reconhecido. */
    public static function whatsApp(?string $valor): ?string
    {
        $canonico = self::canonico($valor);

        return $canonico !== null ? '55'.$canonico : null;
    }

    private static function canonicoNacional(string $digitos): ?string
    {
        $tamanho = strlen($digitos);

        if (! in_array($tamanho, [10, 11], true) || ! in_array((int) substr($digitos, 0, 2), self::DDDS, true)) {
            return null;
        }

        $ddd = substr($digitos, 0, 2);
        $numero = substr($digitos, 2);
        $primeiro = $numero[0];

        if ($tamanho === 11) {
            return $primeiro === '9' ? $digitos : null;
        }

        return match (true) {
            $primeiro >= '2' && $primeiro <= '5' => $digitos,
            $primeiro >= '6' => $ddd.'9'.$numero,
            default => null,
        };
    }
}
