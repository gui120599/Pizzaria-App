<?php

namespace App\Mcp\Tools\Compras\Avulsa;

use App\Services\Compras\CompraAvulsaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('preparar_compra_avulsa')]
#[Description('Monta o RASCUNHO de uma compra sem nota (nada é gravado ainda). Devolve rascunho_uuid, a prévia (produtos reconhecidos, custos, totais, pagamento), os alertas, pode_confirmar e resumo_texto para mostrar ao usuário. Para corrigir, chame de novo com o mesmo rascunho_uuid.')]
class PrepararCompraAvulsa extends FerramentaCompraAvulsa
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'rascunho_uuid' => $schema->string()->description('Rascunho a corrigir (opcional).'),
            'fornecedor_id' => $schema->integer()->description('Fornecedor existente (de buscar_fornecedores). Informe este OU fornecedor_novo.'),
            'fornecedor_novo' => $schema->object(fn (JsonSchema $s): array => [
                'nome' => $s->string()->required(),
                'telefone' => $s->string(),
                'observacao' => $s->string(),
            ])->description('Fornecedor que ainda não existe: é cadastrado só na confirmação, como avulso (sem CNPJ).'),
            'data_compra' => $schema->string()->description('Data da compra, AAAA-MM-DD.')->required(),
            'origem_informacao' => $schema->string()->enum(['imagem', 'mensagem', 'misto'])->description('De onde vieram os dados.'),
            'itens' => $schema->array()->items($schema->object(fn (JsonSchema $s): array => [
                'descricao' => $s->string()->description('Como está no cupom/mensagem.')->required(),
                'quantidade' => $s->number()->required(),
                'unidade' => $s->string()->description('KG, UN, CX, PCT…')->required(),
                'valor_total' => $s->number()->description('Valor total do item (não o unitário).')->required(),
                'produto_id' => $s->integer()->description('Produto do estoque, se tiver certeza (de buscar_produtos).'),
                'fator_conversao' => $s->number()->description('1 unidade da compra = X unidades de estoque do produto.'),
            ]))->required(),
            'valor_total_informado' => $schema->number()->description('Total que aparece no cupom, para conferência.'),
            'desconto' => $schema->number(),
            'situacao_pagamento' => $schema->string()->enum(['pago', 'a_pagar'])->required(),
            'forma_pagamento' => $schema->string()->enum(['dinheiro', 'pix', 'pix_cnpj', 'cartao_credito', 'cartao_debito', 'boleto', 'transferencia'])->description('Padrão: o do fornecedor.'),
            'conta_financeira_id' => $schema->integer()->description('De onde saiu o dinheiro (de listar_contas_pagamento). Padrão: a do fornecedor.'),
            'vencimento' => $schema->string()->description('Obrigatório se a_pagar. AAAA-MM-DD.'),
            'plano_despesa_id' => $schema->integer(),
            'observacao' => $schema->string(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $rascunho = app(CompraAvulsaService::class)->preparar($request->all(), $this->usuario($request));

        return Response::json([
            'rascunho_uuid' => $rascunho->uuid,
            'expira_em' => $rascunho->expira_em->toIso8601String(),
            ...$rascunho->previa,
        ]);
    }
}
