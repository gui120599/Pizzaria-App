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

#[Name('lancar_receita')]
#[Title('Lançar receita')]
#[Description('Lança uma receita fora do PDV (evento, aluguel, reembolso) como título a receber — parcelado se preciso — ou já recebida.')]
class LancarReceita extends PromptPorPermissao
{
    protected function permitido(User $usuario): bool
    {
        return app(AcoesClaudeService::class)->permitido(TipoAcaoClaude::Receita, $usuario);
    }

    protected function roteiro(Request $request): string
    {
        return <<<'TXT'
        Quero lançar uma receita no PizzariaAPP pelo conector "PizzariaAPP Gestão" (não é venda do PDV nem do cardápio — essas entram sozinhas).
        1. Pergunte o que faltar: descrição, valor, de quem (cliente, se houver), se já recebi (quando e em qual conta) ou o vencimento, e se é parcelado.
        2. Chame opcoes_receita (com busca_cliente se eu disser o nome) para achar o plano de receita, a conta e o cliente.
        3. Chame preparar_receita e me mostre o resumo_texto, as parcelas e os alertas.
        4. Só chame confirmar_acao depois do meu "sim". Se eu mudar algo, prepare de novo com o mesmo acao_uuid.
        TXT;
    }
}
