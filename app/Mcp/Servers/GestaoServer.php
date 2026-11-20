<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Gestao\ContasPagarReceber;
use App\Mcp\Tools\Gestao\DebitosClientes;
use App\Mcp\Tools\Gestao\FechamentoCaixa;
use App\Mcp\Tools\Gestao\RankingProdutos;
use App\Mcp\Tools\Gestao\ResumoVendas;
use App\Mcp\Tools\Gestao\SaldosContas;
use App\Mcp\Tools\Gestao\SituacaoCaixaAgora;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Conector "PizzariaAPP Gestão" (roteiro docs/planos/claude-mcp-gestao.md,
 * etapa G1): perguntas do dono e dos gerentes no Claude, só leitura. Cada
 * tool aparece conforme as permissões do perfil no painel e usa a mesma conta
 * da tela correspondente.
 */
#[Name('PizzariaAPP Gestão')]
#[Version('1.0.0')]
#[Instructions('Assistente de gestão do Empório da Pizza (pizzaria, pastelaria e sanduicheria). Responda em português, curto e com números em reais (R$ 1.234,56). Use as ferramentas para buscar os dados — nunca invente valores. Converta datas relativas ("ontem", "semana passada", "este mês") para AAAA-MM-DD antes de chamar; hoje é a data do servidor. Ao comparar períodos, use os campos de comparativo que as ferramentas devolvem. Se uma ferramenta disser que o perfil não tem acesso, explique que esse dado não está liberado para o usuário no painel. Estas ferramentas só leem: para alterar algo, oriente o usuário a usar o painel.')]
class GestaoServer extends Server
{
    protected array $tools = [
        ResumoVendas::class,
        RankingProdutos::class,
        SituacaoCaixaAgora::class,
        FechamentoCaixa::class,
        ContasPagarReceber::class,
        SaldosContas::class,
        DebitosClientes::class,
    ];
}
