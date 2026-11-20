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
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('produto_acabou')]
#[Title('Produto acabou')]
#[Description('Tira do cardápio os produtos que dependem de um insumo que acabou, até um horário.')]
class ProdutoAcabou extends PromptPorPermissao
{
    public function arguments(): array
    {
        return [
            new Argument('produto', 'O que acabou (ex.: calabresa, massa de pastel).', required: true),
            new Argument('ate', 'Até quando (ex.: amanhã 18h). Padrão: perguntar.'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return app(AcoesClaudeService::class)->permitido(TipoAcaoClaude::PausaProduto, $usuario);
    }

    protected function roteiro(Request $request): string
    {
        $produto = $this->argumento($request, 'produto') ?? 'o produto';
        $ate = $this->argumento($request, 'ate');
        $quando = $ate ? "até {$ate}" : 'até um horário que você vai me perguntar';

        return <<<TXT
        Acabou "{$produto}". Pause no PizzariaAPP, pelo conector "PizzariaAPP Gestão", os produtos que dependem disso, {$quando}.
        1. Ache os produtos com preparar_pausa_produto usando busca (nome ambíguo devolve os candidatos com id) — ex.: todos os tamanhos com esse sabor. Liste os candidatos e pergunte quais pausar se houver dúvida.
        2. Converta o horário para AAAA-MM-DD HH:MM e chame preparar_pausa_produto (acao pausar, nos dois canais, salvo se eu disser outra coisa).
        3. Mostre o resumo_texto e só chame confirmar_acao depois do meu "sim".
        TXT;
    }
}
