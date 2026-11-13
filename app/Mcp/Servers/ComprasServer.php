<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Compras\BuscarProdutos;
use App\Mcp\Tools\Compras\DetalharCompra;
use App\Mcp\Tools\Compras\HistoricoVinculosFornecedor;
use App\Mcp\Tools\Compras\ListarPagamentosAConfirmar;
use App\Mcp\Tools\Compras\ListarPendencias;
use App\Mcp\Tools\Compras\ProporNovoProduto;
use App\Mcp\Tools\Compras\ProporVinculo;
use App\Mcp\Tools\Compras\RegistrarResumo;
use App\Mcp\Tools\Compras\SugerirLancamento;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Servidor MCP do agente de compras (docs/planos/compras-automatizadas.md §9).
 * Expõe só leitura, propostas de vínculo/produto/lançamento e o resumo:
 * nenhuma tool dá baixa, estorna, edita parâmetros ou contas, ou exclui.
 */
#[Name('PizzariaAPP Compras')]
#[Version('1.0.0')]
#[Instructions('Assistente de compras do Empório da Pizza. Resolva só pendências de produto (propor_vinculo, propor_novo_produto) e sugira lançamentos para lançamentos ambíguos (sugerir_lancamento). Use confiança honesta (0 a 1): na dúvida, valor baixo. Prefira sempre um produto existente; proponha produto novo só se nenhum servir. Você não dá baixa, não estorna e não configura fornecedores. Termine com registrar_resumo.')]
class ComprasServer extends Server
{
    protected array $tools = [
        ListarPendencias::class,
        DetalharCompra::class,
        BuscarProdutos::class,
        HistoricoVinculosFornecedor::class,
        ProporVinculo::class,
        ProporNovoProduto::class,
        SugerirLancamento::class,
        ListarPagamentosAConfirmar::class,
        RegistrarResumo::class,
    ];
}
