<?php

namespace App\Mcp\Prompts\Gestao;

use App\Enums\Gestao\TipoAcaoClaude;
use App\Mcp\Prompts\PromptPorPermissao;
use App\Models\User;
use App\Services\Gestao\Acoes\AcoesClaudeService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('contagem_de_estoque')]
#[Title('Contagem de estoque')]
#[Description('Lança a contagem física (foto da folha ou lista) e ajusta o saldo do sistema.')]
class ContagemDeEstoque extends PromptPorPermissao
{
    protected function permitido(User $usuario): bool
    {
        return app(AcoesClaudeService::class)->permitido(TipoAcaoClaude::ContagemEstoque, $usuario);
    }

    protected function roteiro(Request $request): string
    {
        return <<<'TXT'
        Vou mandar a contagem física do estoque (foto da folha ou lista). Lance pelo conector "PizzariaAPP Gestão".
        1. Leia cada linha: produto e quantidade contada. Confira em saldo_estoque o nome e a UNIDADE DE ESTOQUE de cada produto e converta se eu contei em outra unidade (ex.: caixas → unidades). Se uma linha estiver ilegível ou ambígua, pergunte.
        2. Chame preparar_contagem_estoque com todos os itens e me mostre o resumo_texto: diferenças em quantidade e em R$, e os alertas.
        3. Só chame confirmar_acao depois do meu "sim". Se eu corrigir alguma linha, prepare de novo com o mesmo acao_uuid.
        TXT;
    }
}
