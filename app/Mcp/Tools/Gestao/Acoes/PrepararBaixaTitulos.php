<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('preparar_baixa_titulos')]
#[Description('PRÉVIA (nada é gravado) para registrar o PAGAMENTO de títulos a pagar ou o RECEBIMENTO de títulos a receber já lançados (ex.: "paguei o boleto do Moinho", "a empresa X pagou a parcela 2"). Um ou vários títulos, mesma data, forma e conta; sem valor, quita o que falta (valor menor = pagamento parcial; maior, só a pagar, = juros/multa). Ache os títulos com buscar_titulos. Grave com confirmar_acao após o "sim" do usuário.')]
class PrepararBaixaTitulos extends FerramentaAcao
{
    protected function tipo(): TipoAcaoClaude
    {
        return TipoAcaoClaude::BaixaTitulos;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'acao_uuid' => $schema->string()->description('Prévia a corrigir (opcional).'),
            'titulos' => $schema->array()->items($schema->object(fn (JsonSchema $s): array => [
                'lancamento_id' => $s->integer()->description('De buscar_titulos.')->required(),
                'valor' => $s->number()->description('Quanto foi pago/recebido. Padrão: o que falta.'),
            ]))->required(),
            'data' => $schema->string()->description('AAAA-MM-DD do pagamento. Padrão: hoje.'),
            'forma_pagamento' => $schema->string()->enum(['dinheiro', 'pix', 'pix_cnpj', 'cartao_credito', 'cartao_debito', 'boleto', 'transferencia']),
            'conta_financeira_id' => $schema->integer()->description('De onde saiu (a pagar) ou onde entrou (a receber) o dinheiro.')->required(),
            'observacao' => $schema->string(),
        ];
    }
}
