<?php

namespace App\Mcp\Prompts\Gestao;

use App\Mcp\Prompts\PromptPorPermissao;
use App\Mcp\Tools\Gestao\ResumoDoDia;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('fechamento_do_dia')]
#[Title('Fechamento do dia')]
#[Description('Resumo de um dia: vendas contra a semana passada, caixa e o que precisa de atenção.')]
class FechamentoDoDia extends PromptPorPermissao
{
    public function arguments(): array
    {
        return [new Argument('data', 'Dia (ex.: ontem, hoje, 21/11). Padrão: ontem.')];
    }

    protected function permitido(User $usuario): bool
    {
        return ResumoDoDia::algumaSecao($usuario);
    }

    protected function roteiro(Request $request): string
    {
        $dia = $this->argumento($request, 'data') ?? 'ontem';

        return <<<TXT
        Faça o fechamento do dia "{$dia}" do Empório da Pizza usando o conector "PizzariaAPP Gestão".
        1. Converta o dia para AAAA-MM-DD e chame resumo_do_dia e anomalias (se disponível) com essa data.
        2. Responda curto, em Markdown:
           - **Vendas:** faturamento, nº de vendas e ticket médio, com a variação contra o mesmo dia da semana passada; os 3 mais vendidos.
           - **Caixa:** recebido, fiado e se algum fechamento teve diferença (diga qual caixa e operador).
           - **Atenção:** cada alerta numa linha, do mais grave para o menos grave (NFC-e com falha, Stone travada, pagamentos de compra a confirmar, contas vencidas ou vencendo).
           - Se estiver tudo normal, diga isso numa frase.
        3. No fim, pergunte se quero ver o cartão do painel (painel_do_dia) ou detalhar algum ponto.
        Não altere nada no sistema.
        TXT;
    }
}
