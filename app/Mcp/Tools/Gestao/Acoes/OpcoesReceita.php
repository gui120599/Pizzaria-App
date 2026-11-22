<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use App\Exceptions\FinanceiroException;
use App\Mcp\Tools\Gestao\DebitosClientes;
use App\Mcp\Tools\Gestao\FerramentaGestao;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\PlanoReceita;
use App\Models\User;
use App\Services\Financeiro\CaixaPdvBridge;
use App\Services\Gestao\Acoes\AcoesClaudeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('opcoes_receita')]
#[Description('Opções para preparar_receita: planos de receita (só os lançáveis, sem os grupos), contas onde o dinheiro pode entrar (Caixa PDV diz se o caixa está aberto) e, com busca_cliente, os clientes parecidos (telefone mascarado).')]
#[IsReadOnly]
class OpcoesReceita extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'busca_cliente' => $schema->string()->description('Parte do nome do cliente.'),
            'busca_plano' => $schema->string()->description('Filtra os planos pelo nome (ex.: eventos).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return app(AcoesClaudeService::class)->permitido(TipoAcaoClaude::Receita, $usuario);
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'busca_cliente' => ['nullable', 'string', 'min:2', 'max:100'],
            'busca_plano' => ['nullable', 'string', 'max:100'],
        ]);

        $planos = PlanoReceita::query()
            ->whereDoesntHave('filhos')
            ->when($dados['busca_plano'] ?? null, fn ($q, string $busca) => $q->where('nome', 'like', "%{$busca}%"))
            ->with('pai')
            ->orderBy('codigo')->orderBy('nome')
            ->limit(150)
            ->get()
            ->map(fn (PlanoReceita $p): array => ['plano_receita_id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'grupo' => $p->pai?->nome])
            ->all();

        $caixa = app(CaixaPdvBridge::class);
        $contas = ContaFinanceira::query()->ordenadas()->get()->map(function (ContaFinanceira $conta) use ($caixa): array {
            $linha = ['conta_financeira_id' => $conta->id, 'nome' => $conta->nome];
            if ($conta->ehCaixaPdv()) {
                try {
                    $caixa->sessaoParaMovimentar();
                    $linha['caixa_aberto'] = true;
                } catch (FinanceiroException) {
                    $linha['caixa_aberto'] = false;
                }
            }

            return $linha;
        })->all();

        $clientes = filled($dados['busca_cliente'] ?? null)
            ? Cliente::query()->where('cliente_nome', 'like', '%'.$dados['busca_cliente'].'%')->orderBy('cliente_nome')->limit(10)->get()
                ->map(fn (Cliente $c): array => ['cliente_id' => $c->id, 'nome' => $c->cliente_nome, 'telefone' => DebitosClientes::mascarar($c->cliente_celular)])
                ->all()
            : null;

        return Response::json(array_filter([
            'planos_receita' => $planos,
            'contas' => $contas,
            'clientes' => $clientes,
        ], fn ($valor): bool => $valor !== null));
    }
}
