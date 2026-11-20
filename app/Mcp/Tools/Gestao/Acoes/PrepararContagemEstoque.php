<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('preparar_contagem_estoque')]
#[Description('PRÉVIA (nada é gravado) de uma contagem física de estoque (foto da folha de contagem ou mensagem): compara cada item com o saldo do sistema e mostra a diferença em quantidade e em R$. Na confirmação, cada item com diferença vira um balanço (o saldo passa a ser o contado). Quantidade na UNIDADE DE ESTOQUE do produto (veja saldo_estoque). Confirme logo após contar: vendas entre a contagem e a confirmação não entram.')]
class PrepararContagemEstoque extends FerramentaAcao
{
    protected function tipo(): TipoAcaoClaude
    {
        return TipoAcaoClaude::ContagemEstoque;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'acao_uuid' => $schema->string()->description('Prévia a corrigir (opcional).'),
            'itens' => $schema->array()->items($schema->object(fn (JsonSchema $s): array => [
                'produto_id' => $s->integer()->description('Produto (de saldo_estoque). Informe isto OU busca.'),
                'busca' => $s->string()->description('Nome do produto.'),
                'quantidade' => $s->number()->description('Quantidade contada, na unidade de estoque.')->required(),
            ]))->required(),
            'observacao' => $schema->string(),
        ];
    }
}
