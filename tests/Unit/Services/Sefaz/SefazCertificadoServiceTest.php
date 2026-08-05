<?php

namespace Tests\Unit\Services\Sefaz;

use App\Exceptions\SefazAutenticacaoException;
use App\Services\Sefaz\SefazCertificadoService;
use Tests\TestCase;

class SefazCertificadoServiceTest extends TestCase
{
    private function conteudoPfx(): string
    {
        return file_get_contents(base_path('tests/Fixtures/certificado-teste.pfx'));
    }

    public function test_abre_certificado_valido_e_extrai_metadados(): void
    {
        $metadados = (new SefazCertificadoService)->validarEExtrairMetadados($this->conteudoPfx(), 'senha-teste-123');

        $this->assertSame('Empresa Teste LTDA', $metadados->nomeTitular);
        $this->assertTrue($metadados->validade->format('Y') >= (string) now()->year);
        // Certificado de teste self-signed não carrega o OID de CNPJ do ICP-Brasil.
        $this->assertNull($metadados->cnpj);
    }

    public function test_lanca_excecao_para_senha_errada(): void
    {
        $this->expectException(SefazAutenticacaoException::class);

        (new SefazCertificadoService)->validarEExtrairMetadados($this->conteudoPfx(), 'senha-errada');
    }

    public function test_lanca_excecao_para_arquivo_invalido(): void
    {
        $this->expectException(SefazAutenticacaoException::class);

        (new SefazCertificadoService)->validarEExtrairMetadados('isto não é um pfx', 'qualquer-senha');
    }
}
