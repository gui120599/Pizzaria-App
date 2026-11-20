<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\FechamentoCaixaService;
use App\Services\Financeiro\CaixaPdvBridge;
use App\Services\RelatorioFechamentoCaixaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('situacao_caixa_agora')]
#[Description('Sessões de caixa abertas agora: caixa, operador, desde quando, vendas finalizadas da sessão e o esperado por forma de pagamento (inclusive o dinheiro que deveria estar na gaveta).')]
#[IsReadOnly]
class SituacaoCaixaAgora extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:relatorio_fechamento_caixa');
    }

    protected function executar(Request $request): Response
    {
        $fechamentos = app(FechamentoCaixaService::class);
        $sessoes = app(CaixaPdvBridge::class)->sessoesAbertas()->load(['caixa', 'user']);

        return Response::json([
            'sessoes_abertas' => $sessoes->count(),
            'sessoes' => $sessoes->map(function (SessaoCaixa $sessao) use ($fechamentos): array {
                $vendas = Venda::query()->where('venda_sessao_caixa_id', $sessao->id)->where('venda_status', 'FINALIZADA');
                $esperado = $fechamentos->calcularEsperado($sessao);

                return [
                    'sessao_id' => $sessao->id,
                    'caixa' => $sessao->caixa?->caixa_nome,
                    'operador' => $sessao->user?->name,
                    'aberta_em' => $sessao->sessaocaixa_data_hora_abertura?->toIso8601String(),
                    'vendas_finalizadas' => (clone $vendas)->count(),
                    'total_vendido' => round((float) (clone $vendas)->sum('venda_valor_total'), 2),
                    'esperado_por_forma' => collect(RelatorioFechamentoCaixaService::CATEGORIAS)
                        ->mapWithKeys(fn (string $rotulo, string $chave): array => [$rotulo => round((float) ($esperado[$chave] ?? 0), 2)])
                        ->all(),
                    'dinheiro_na_gaveta' => round((float) ($esperado['dinheiro'] ?? 0), 2),
                ];
            })->values()->all(),
        ]);
    }
}
