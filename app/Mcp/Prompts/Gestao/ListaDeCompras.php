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

#[Name('lista_de_compras')]
#[Title('Lista de compras')]
#[Description('O que comprar, por fornecedor, para cobrir os próximos dias pelo consumo médio.')]
class ListaDeCompras extends PromptPorPermissao
{
    public function arguments(): array
    {
        return [new Argument('dias', 'Quantos dias de estoque cobrir. Padrão: 7.')];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view_any:compra');
    }

    protected function roteiro(Request $request): string
    {
        $dias = (int) ($this->argumento($request, 'dias') ?? 7) ?: 7;

        return <<<TXT
        Monte a lista de compras para cobrir {$dias} dias usando o conector "PizzariaAPP Gestão".
        1. Chame sugestao_compras com cobertura_dias = {$dias}.
        2. Em Markdown, um bloco por fornecedor (maior valor primeiro): item, quantidade na unidade de compra (ex.: 2 SC), dias de estoque que restam e custo estimado; total por fornecedor e total geral.
        3. Destaque no topo os itens com menos de 2 dias de estoque.
        4. Ofereça uma versão curta, por fornecedor, pronta para eu copiar no WhatsApp.
        É só a sugestão: não faça pedido nenhum nem altere nada no sistema.
        TXT;
    }
}
