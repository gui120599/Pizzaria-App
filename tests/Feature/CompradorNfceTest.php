<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Services\Nfe\CompradorNfceService;
use App\Support\DocumentoFiscal;
use Tests\TestCase;

/**
 * O cliente só vai identificado na NFC-e com cadastro completo; senão a nota
 * sai sem comprador em vez de ser recusada por CPF ausente.
 */
class CompradorNfceTest extends TestCase
{
    private function cliente(array $dados): Cliente
    {
        return (new Cliente)->forceFill(['id' => 7, 'cliente_nome' => 'Maria', 'cliente_tipo' => 'Física', ...$dados]);
    }

    public function test_documentos_validos_e_invalidos(): void
    {
        $this->assertTrue(DocumentoFiscal::cpfValido('529.982.247-25'));
        $this->assertFalse(DocumentoFiscal::cpfValido('52998224726'));
        $this->assertFalse(DocumentoFiscal::cpfValido('11111111111'));
        $this->assertFalse(DocumentoFiscal::cpfValido(null));
        $this->assertTrue(DocumentoFiscal::cnpjValido('11.222.333/0001-81'));
        $this->assertFalse(DocumentoFiscal::cnpjValido('11222333000182'));
    }

    public function test_cliente_sem_cpf_sai_sem_comprador(): void
    {
        $comprador = new CompradorNfceService;
        $cliente = $this->cliente(['cliente_cpf' => null]);

        $this->assertNull($comprador->montar($cliente));
        $this->assertSame('CPF não informado', $comprador->motivoSemIdentificacao($cliente));
    }

    public function test_cpf_invalido_e_cliente_sem_nome_sao_omitidos(): void
    {
        $comprador = new CompradorNfceService;

        $this->assertSame('CPF inválido', $comprador->motivoSemIdentificacao($this->cliente(['cliente_cpf' => '12345678900'])));
        $this->assertSame('cliente sem nome', $comprador->motivoSemIdentificacao($this->cliente(['cliente_nome' => '', 'cliente_cpf' => '52998224725'])));
        $this->assertSame('venda sem cliente', $comprador->motivoSemIdentificacao(null));
        $this->assertNull($comprador->montar(null));
    }

    public function test_pessoa_fisica_com_cpf_valido_vai_identificada(): void
    {
        $buyer = (new CompradorNfceService)->montar($this->cliente(['cliente_cpf' => '52998224725', 'cliente_email' => 'maria@exemplo.com']));

        $this->assertSame('52998224725', $buyer['federalTaxNumber']);
        $this->assertSame(2, $buyer['type']);
        $this->assertSame('maria@exemplo.com', $buyer['email']);
        $this->assertArrayNotHasKey('stateTaxNumber', $buyer);
    }

    public function test_email_invalido_nao_vai_e_pessoa_juridica_exige_cnpj(): void
    {
        $comprador = new CompradorNfceService;

        $buyer = $comprador->montar($this->cliente(['cliente_cpf' => '52998224725', 'cliente_email' => 'sem-arroba']));
        $this->assertArrayNotHasKey('email', $buyer);

        $juridica = $this->cliente(['cliente_tipo' => 'Jurídica', 'cliente_cnpj' => null, 'cliente_cpf' => null]);
        $this->assertSame('CNPJ não informado', $comprador->motivoSemIdentificacao($juridica));

        $juridica->cliente_cnpj = '11222333000181';
        $buyer = $comprador->montar($juridica);
        $this->assertSame('11222333000181', $buyer['federalTaxNumber']);
        $this->assertSame(4, $buyer['type']);
    }

    public function test_tipo_e_documento_divergentes_usam_o_documento_valido(): void
    {
        $buyer = (new CompradorNfceService)->montar($this->cliente(['cliente_tipo' => 'Jurídica', 'cliente_cnpj' => null, 'cliente_cpf' => '52998224725']));

        $this->assertSame('52998224725', $buyer['federalTaxNumber']);
        $this->assertSame(2, $buyer['type']);
    }
}
