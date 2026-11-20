<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('preparar_despesa')]
#[Description('PRÉVIA (nada é gravado) de uma despesa avulsa (boleto, conta de luz/água/gás, recibo, serviço): título a pagar com favorecido, plano de despesa e vencimento; se já foi paga, a baixa na conta escolhida. Para compra de mercadoria use o conector de compras avulsas. Use opcoes_despesa para planos, contas e favorecidos. Grave com confirmar_acao após o "sim" do usuário.')]
class PrepararDespesa extends FerramentaAcao
{
    protected function tipo(): TipoAcaoClaude
    {
        return TipoAcaoClaude::Despesa;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'acao_uuid' => $schema->string()->description('Prévia a corrigir (opcional).'),
            'descricao' => $schema->string()->description('Ex.: "Conta de energia — outubro".')->required(),
            'valor' => $schema->number()->required(),
            'plano_despesa_id' => $schema->integer()->description('De opcoes_despesa.')->required(),
            'favorecido_id' => $schema->integer()->description('Favorecido existente (de opcoes_despesa).'),
            'favorecido_novo' => $schema->object(fn (JsonSchema $s): array => [
                'nome' => $s->string()->required(),
                'telefone' => $s->string(),
            ])->description('Favorecido que ainda não existe: cadastrado só na confirmação.'),
            'situacao_pagamento' => $schema->string()->enum(['pago', 'a_pagar'])->required(),
            'vencimento' => $schema->string()->description('AAAA-MM-DD. Obrigatório se a_pagar.'),
            'data_pagamento' => $schema->string()->description('AAAA-MM-DD, se pago. Padrão: hoje.'),
            'competencia' => $schema->string()->description('Mês da despesa, AAAA-MM (ex.: conta de outubro paga em novembro). Padrão: mês do vencimento.'),
            'forma_pagamento' => $schema->string()->enum(['dinheiro', 'pix', 'pix_cnpj', 'cartao_credito', 'cartao_debito', 'boleto', 'transferencia']),
            'conta_financeira_id' => $schema->integer()->description('De onde saiu o dinheiro (obrigatório se pago).'),
            'numero_documento' => $schema->string()->description('Linha digitável/código de barras do boleto ou nº do documento — evita lançar a mesma conta duas vezes.'),
            'observacao' => $schema->string(),
        ];
    }
}
