<?php

namespace App\Services\Sefaz;

use App\Exceptions\SefazAutenticacaoException;
use App\Services\Sefaz\Dto\CertificadoMetadados;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\CertificateException;

/** Abre/valida um certificado A1 (.pfx) e extrai os dados usados na tela de configuração. */
class SefazCertificadoService
{
    /**
     * @throws SefazAutenticacaoException se a senha estiver errada ou o arquivo não for um .pfx válido
     */
    public function validarEExtrairMetadados(string $conteudoPfx, string $senha): CertificadoMetadados
    {
        try {
            $certificado = Certificate::readPfx($conteudoPfx, $senha);
        } catch (CertificateException $e) {
            throw new SefazAutenticacaoException(
                'Não foi possível abrir o certificado: senha incorreta ou arquivo inválido.',
                previous: $e,
            );
        }

        return new CertificadoMetadados(
            cnpj: $certificado->getCnpj(),
            nomeTitular: $certificado->getCompanyName(),
            validade: $certificado->getValidTo(),
        );
    }
}
