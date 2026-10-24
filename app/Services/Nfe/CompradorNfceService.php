<?php

namespace App\Services\Nfe;

use App\Models\Cliente;
use App\Support\DocumentoFiscal;

/**
 * Decide se o cliente da venda vai identificado na NFC-e. Só vai com cadastro
 * completo para a nota (nome + CPF/CNPJ válido); senão a nota sai sem
 * comprador (consumidor não identificado) em vez de ser recusada por
 * documento ausente. A venda continua ligada ao cliente — só o payload fiscal
 * omite o comprador.
 */
class CompradorNfceService
{
    /**
     * Bloco `buyer` da NFe.io, ou null quando o cadastro não está completo.
     *
     * @return array<string, mixed>|null
     */
    public function montar(?Cliente $cliente): ?array
    {
        if ($this->motivoSemIdentificacao($cliente) !== null) {
            return null;
        }

        [$tipo, $numero] = $this->documento($cliente);
        $inscricaoEstadual = filled($cliente->cliente_inscricao_estadual) ? $cliente->cliente_inscricao_estadual : null;
        $email = filter_var($cliente->cliente_email, FILTER_VALIDATE_EMAIL) ? $cliente->cliente_email : null;

        return array_filter([
            'stateTaxNumberIndicator' => 'NonTaxPayer',
            'tradeName' => $cliente->cliente_nome,
            'taxRegime' => 'isento',
            'stateTaxNumber' => $inscricaoEstadual,
            'id' => (string) $cliente->id,
            'name' => $cliente->cliente_nome,
            'federalTaxNumber' => $numero,
            'email' => $email,
            'type' => $tipo === 'cnpj' ? 4 : 2, // 2 Pessoa Física, 4 Pessoa Jurídica
        ], fn (mixed $valor): bool => $valor !== null);
    }

    /**
     * Por que a nota sairia sem comprador; null quando o cadastro está completo.
     */
    public function motivoSemIdentificacao(?Cliente $cliente): ?string
    {
        if (! $cliente) {
            return 'venda sem cliente';
        }

        if (blank($cliente->cliente_nome)) {
            return 'cliente sem nome';
        }

        if ($this->documento($cliente) !== null) {
            return null;
        }

        $juridica = $this->ehJuridica($cliente);
        $preferido = DocumentoFiscal::somenteDigitos($juridica ? $cliente->cliente_cnpj : $cliente->cliente_cpf);
        $nome = $juridica ? 'CNPJ' : 'CPF';

        return $preferido === '' ? "{$nome} não informado" : "{$nome} inválido";
    }

    /**
     * Documento válido a enviar: o do tipo do cliente; se ele não tiver, o outro, quando válido.
     *
     * @return array{0: 'cpf'|'cnpj', 1: string}|null
     */
    private function documento(Cliente $cliente): ?array
    {
        $candidatos = $this->ehJuridica($cliente)
            ? [['cnpj', $cliente->cliente_cnpj], ['cpf', $cliente->cliente_cpf]]
            : [['cpf', $cliente->cliente_cpf], ['cnpj', $cliente->cliente_cnpj]];

        foreach ($candidatos as [$tipo, $valor]) {
            $valido = $tipo === 'cnpj' ? DocumentoFiscal::cnpjValido($valor) : DocumentoFiscal::cpfValido($valor);

            if ($valido) {
                return [$tipo, DocumentoFiscal::somenteDigitos($valor)];
            }
        }

        return null;
    }

    private function ehJuridica(Cliente $cliente): bool
    {
        return in_array($cliente->cliente_tipo, ['Jurídica', 'PJ'], true);
    }
}
