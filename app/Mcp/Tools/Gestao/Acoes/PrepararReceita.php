<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('preparar_receita')]
#[Description('PRÉVIA (nada é gravado) de uma receita avulsa fora do PDV (evento, aluguel de espaço, reembolso, venda para empresa): título a receber com plano de receita, cliente opcional e vencimento — parcelado se preciso; se já recebida, a entrada na conta escolhida. Vendas do PDV/cardápio NÃO entram aqui (já geram receita sozinhas). Use opcoes_receita para planos, contas e clientes. Grave com confirmar_acao após o "sim" do usuário.')]
class PrepararReceita extends FerramentaAcao
{
    protected function tipo(): TipoAcaoClaude
    {
        return TipoAcaoClaude::Receita;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'acao_uuid' => $schema->string()->description('Prévia a corrigir (opcional).'),
            'descricao' => $schema->string()->description('Ex.: "Evento aniversário — sinal".')->required(),
            'valor' => $schema->number()->description('Valor total.')->required(),
            'plano_receita_id' => $schema->integer()->description('De opcoes_receita.')->required(),
            'cliente_id' => $schema->integer()->description('Cliente (de opcoes_receita com busca_cliente). Opcional.'),
            'situacao' => $schema->string()->enum(['recebido', 'a_receber'])->required(),
            'vencimento' => $schema->string()->description('AAAA-MM-DD. Obrigatório se a_receber (com parcelas: o da 1ª).'),
            'numero_parcelas' => $schema->integer()->min(1)->max(36)->description('Parcelas mensais iguais (só a_receber).'),
            'parcelas' => $schema->array()->items($schema->object(fn (JsonSchema $s): array => [
                'vencimento' => $s->string()->description('AAAA-MM-DD')->required(),
                'valor' => $s->number()->required(),
            ]))->description('Parcelas com datas/valores diferentes; precisam somar o valor total.'),
            'data_recebimento' => $schema->string()->description('AAAA-MM-DD, se recebido. Padrão: hoje.'),
            'competencia' => $schema->string()->description('Mês da receita, AAAA-MM. Padrão: mês do vencimento.'),
            'forma_pagamento' => $schema->string()->enum(['dinheiro', 'pix', 'pix_cnpj', 'cartao_credito', 'cartao_debito', 'boleto', 'transferencia']),
            'conta_financeira_id' => $schema->integer()->description('Onde o dinheiro entrou (obrigatório se recebido).'),
            'numero_documento' => $schema->string()->description('Nº do contrato, recibo ou nota.'),
            'observacao' => $schema->string(),
        ];
    }
}
