<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Compras\Avulsa\BuscarFornecedores;
use App\Mcp\Tools\Compras\Avulsa\ConfirmarCompraAvulsa;
use App\Mcp\Tools\Compras\Avulsa\DescartarRascunho;
use App\Mcp\Tools\Compras\Avulsa\ListarContasPagamento;
use App\Mcp\Tools\Compras\Avulsa\PrepararCompraAvulsa;
use App\Mcp\Tools\Compras\BuscarProdutos;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Compras avulsas sem NF-e (Fase 5, spec B §9.2 e §14): usado pelo usuário
 * humano (Admin/Gerente) no próprio Claude, logado com o próprio usuário do
 * PizzariaAPP. Separado do /mcp/compras do agente: cada conector do Claude
 * guarda um login, e a tarefa agendada nunca deve lançar compra.
 */
#[Name('PizzariaAPP Compras Avulsas')]
#[Version('1.0.0')]
#[Instructions('Lançamento de compras sem nota do Empório da Pizza (mercado, feira, açougue). Leia a foto ou a mensagem e extraia fornecedor, data, itens (descrição, quantidade, unidade, valor total) e forma de pagamento. Se faltar algo essencial — fornecedor, valor de algum item, ou de onde saiu o dinheiro — pergunte antes de preparar. Use buscar_fornecedores, buscar_produtos e listar_contas_pagamento; nunca invente ids. Chame preparar_compra_avulsa, mostre ao usuário o resumo_texto e os alertas, e só chame confirmar_compra_avulsa depois que o usuário responder confirmando explicitamente. Valores lidos de foto com baixa legibilidade devem ser destacados para conferência. Se pode_confirmar for false, corrija com o usuário e prepare de novo com o mesmo rascunho_uuid. Depois de confirmar, envie o link para anexar a foto do comprovante.')]
class CompraAvulsaServer extends Server
{
    protected array $tools = [
        BuscarFornecedores::class,
        BuscarProdutos::class,
        ListarContasPagamento::class,
        PrepararCompraAvulsa::class,
        ConfirmarCompraAvulsa::class,
        DescartarRascunho::class,
    ];
}
