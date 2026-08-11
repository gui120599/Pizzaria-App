<?php

namespace App\Services\Nfe;

use App\Exceptions\DanfeGeracaoException;
use NFePHP\DA\NFe\Danfe;
use Throwable;

/** Gera o PDF do DANFE a partir do XML da NF-e (nfephp-org/sped-da). */
class DanfeService
{
    /** @throws DanfeGeracaoException */
    public function gerar(string $xmlConteudo): string
    {
        try {
            return (new Danfe($xmlConteudo))->render();
        } catch (Throwable $e) {
            throw new DanfeGeracaoException("Não foi possível gerar o DANFE: {$e->getMessage()}", previous: $e);
        }
    }
}
