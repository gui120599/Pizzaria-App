<?php

namespace App\Mcp\Prompts\Gestao;

use App\Mcp\Prompts\PromptPorPermissao;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('analise_da_semana')]
#[Title('Análise da semana')]
#[Description('Semana contra a anterior: vendas, produtos, promoções, exceções e cortesias, com recomendações.')]
class AnaliseDaSemana extends PromptPorPermissao
{
    public function arguments(): array
    {
        return [new Argument('semana', 'Qual semana (ex.: semana passada, esta semana, 10/11 a 16/11). Padrão: a semana passada (segunda a domingo).')];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:financeiro_dashboard');
    }

    protected function roteiro(Request $request): string
    {
        $semana = $this->argumento($request, 'semana') ?? 'a semana passada (segunda a domingo)';

        return <<<TXT
        Analise {$semana} do Empório da Pizza usando o conector "PizzariaAPP Gestão".
        1. Defina início e fim (AAAA-MM-DD) e a semana anterior de mesmo tamanho para comparar.
        2. Chame resumo_vendas nos dois períodos, ranking_produtos (top 10) e, se estiverem disponíveis para o meu perfil, desempenho_promocoes, excecoes_pedidos e cortesias_periodo.
        3. Monte em Markdown:
           - **Números da semana:** faturamento, vendas, ticket, CMV e margem, com a variação contra a semana anterior.
           - **Produtos:** o que subiu e o que caiu no ranking.
           - **Promoções:** quanto cada uma vendeu e se valeu a pena (margem).
           - **Controle:** cancelamentos, descontos e cortesias fora do comum, por operador.
           - **3 recomendações práticas** para a próxima semana, cada uma ligada a um número acima.
        Se uma ferramenta não estiver disponível para o meu perfil, pule a seção sem comentar. Não altere nada no sistema.
        TXT;
    }
}
