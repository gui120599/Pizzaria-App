<?php

namespace App\Services\Compras\Dto;

/**
 * Dados externos à NF-e de que o classificador precisa. Quem monta é o
 * chamador (NfeImportService), para o classificador não ler o banco.
 */
final readonly class ContextoClassificacao
{
    /**
     * @param  string|null  $empresaCnpj  CNPJ da empresa (só dígitos); null = não configurado
     * @param  bool  $fornecedorTemHistorico  o emitente já tem compra confirmada conosco
     * @param  string[]  $notasReferenciadasNossas  chaves das NFref que correspondem a compras nossas
     */
    public function __construct(
        public ?string $empresaCnpj = null,
        public bool $fornecedorTemHistorico = false,
        public array $notasReferenciadasNossas = [],
    ) {}
}
