<?php

namespace App\Services\Compras;

use Illuminate\Support\Str;

/**
 * Chave do de-para por descrição da compra avulsa: minúsculas, sem acento,
 * sem pontuação e com espaços únicos ("Calabresa  Defumada!" →
 * "calabresa defumada"). Limitada ao tamanho de fp_codigo_fornecedor.
 */
class NormalizadorDescricao
{
    public static function normalizar(?string $descricao): string
    {
        $texto = Str::lower(Str::ascii((string) $descricao));
        $texto = (string) preg_replace('/[^a-z0-9]+/', ' ', $texto);

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $texto)), 0, 255);
    }
}
