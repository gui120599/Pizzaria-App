<?php

namespace Tests\Unit\Services\Nfe;

use App\Exceptions\DanfeGeracaoException;
use App\Services\Nfe\DanfeService;
use Tests\TestCase;

class DanfeServiceTest extends TestCase
{
    public function test_gera_pdf_a_partir_do_xml_da_nfe(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/nfe-danfe-exemplo.xml'));

        $pdf = (new DanfeService)->gerar($xml);

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_lanca_excecao_para_xml_sem_destinatario(): void
    {
        // Fixture minimalista usada nos testes do parser: sem <dest>, campo
        // obrigatório pra montar o canhoto do DANFE.
        $xml = file_get_contents(base_path('tests/Fixtures/nfe-compra-exemplo.xml'));

        $this->expectException(DanfeGeracaoException::class);
        (new DanfeService)->gerar($xml);
    }

    public function test_lanca_excecao_para_xml_invalido(): void
    {
        $this->expectException(DanfeGeracaoException::class);
        (new DanfeService)->gerar('<xml>não é uma nota fiscal</xml>');
    }
}
