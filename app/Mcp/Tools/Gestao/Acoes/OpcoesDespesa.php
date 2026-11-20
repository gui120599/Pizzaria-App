<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Mcp\Tools\Gestao\FerramentaGestao;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\User;
use App\Services\Financeiro\CaixaPdvBridge;
use App\Services\Gestao\Acoes\AcoesClaudeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('opcoes_despesa')]
#[Description('Opções para preparar_despesa: planos de despesa (só os lançáveis, sem os grupos), contas de onde pode sair o dinheiro (com saldo; Caixa PDV diz se o caixa está aberto) e, com busca_favorecido, os favorecidos parecidos com o último plano usado para cada um.')]
#[IsReadOnly]
class OpcoesDespesa extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'busca_favorecido' => $schema->string()->description('Parte do nome do favorecido (ex.: Equatorial, Neoenergia, gás).'),
            'busca_plano' => $schema->string()->description('Filtra os planos pelo nome (ex.: energia).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return app(AcoesClaudeService::class)->permitido(TipoAcaoClaude::Despesa, $usuario);
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'busca_favorecido' => ['nullable', 'string', 'min:2', 'max:100'],
            'busca_plano' => ['nullable', 'string', 'max:100'],
        ]);

        $planos = PlanoDespesa::query()
            ->whereDoesntHave('filhos')
            ->when($dados['busca_plano'] ?? null, fn ($q, string $busca) => $q->where('nome', 'like', "%{$busca}%"))
            ->with('pai')
            ->orderBy('codigo')->orderBy('nome')
            ->limit(150)
            ->get()
            ->map(fn (PlanoDespesa $p): array => [
                'plano_despesa_id' => $p->id,
                'codigo' => $p->codigo,
                'nome' => $p->nome,
                'grupo' => $p->pai?->nome,
            ])->all();

        $caixa = app(CaixaPdvBridge::class);
        $contas = ContaFinanceira::query()->ordenadas()->get()->map(function (ContaFinanceira $conta) use ($caixa): array {
            $linha = ['conta_financeira_id' => $conta->id, 'nome' => $conta->nome, 'saldo' => (float) $conta->saldo_atual];
            if ($conta->ehCaixaPdv()) {
                try {
                    $linha += ['caixa_aberto' => true, 'dinheiro_na_gaveta' => $caixa->dinheiroDisponivel($caixa->sessaoParaMovimentar())];
                } catch (FinanceiroException) {
                    $linha += ['caixa_aberto' => false];
                }
            }

            return $linha;
        })->all();

        $favorecidos = null;
        if (filled($dados['busca_favorecido'] ?? null)) {
            $busca = $dados['busca_favorecido'];
            $favorecidos = Prestador::query()->favorecidos()
                ->where(fn ($q) => $q->where('nome', 'like', "%{$busca}%")->orWhere('nome_fantasia', 'like', "%{$busca}%")->orWhere('razao_social', 'like', "%{$busca}%"))
                ->limit(10)->get()
                ->map(function (Prestador $p): array {
                    $ultimo = Lancamento::query()->where('tipo', TipoLancamento::Pagar)->where('favorecido_id', $p->id)
                        ->whereNotNull('plano_despesa_id')->latest('id')->with('planoDespesa')->first();

                    return [
                        'favorecido_id' => $p->id,
                        'nome' => $p->nome_exibicao,
                        'ultimo_plano' => $ultimo ? ['plano_despesa_id' => $ultimo->plano_despesa_id, 'nome' => $ultimo->planoDespesa?->nome] : null,
                    ];
                })->all();
        }

        return Response::json(array_filter([
            'planos_despesa' => $planos,
            'contas' => $contas,
            'favorecidos' => $favorecidos,
        ], fn ($valor): bool => $valor !== null));
    }
}
