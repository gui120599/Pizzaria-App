<?php

namespace App\Services\Gestao\Acoes;

use App\Models\Produto;
use App\Models\User;
use App\Services\BalancoEstoqueService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Contagem de estoque pelo chat (foto da folha de contagem ou mensagem):
 * cada item vira um balanço físico, o mesmo da tela Balanços (ajusta o saldo
 * para a quantidade contada). Permissão: criar balanço.
 */
class ContagemEstoqueAcao extends AcaoBase
{
    public function __construct(private readonly BalancoEstoqueService $balancos) {}

    public function permitido(User $usuario): bool
    {
        return $this->pode($usuario, 'create:movimentacao_balanco');
    }

    public function preparar(array $entrada, User $usuario): array
    {
        $dados = Validator::make($entrada, [
            'itens' => ['required', 'array', 'min:1', 'max:'.(int) config('gestao.acoes.contagem_max_itens', 150)],
            'itens.*.produto_id' => ['nullable', 'integer'],
            'itens.*.busca' => ['nullable', 'string', 'max:100'],
            'itens.*.quantidade' => ['required', 'numeric', 'min:0'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ], ['itens.*.quantidade.min' => 'Quantidade contada não pode ser negativa.'])->validate();

        $limitePct = (float) config('gestao.acoes.contagem_diferenca_pct', 30);
        $alertas = [];
        $vistos = [];
        $linhas = [];

        foreach ($dados['itens'] as $indice => $item) {
            $rotulo = $item['busca'] ?? ('#'.($item['produto_id'] ?? '?'));

            try {
                $produto = $this->produtoPor($item['produto_id'] ?? null, $item['busca'] ?? null);
            } catch (ValidationException $e) {
                $alertas[] = $this->alerta('produto_nao_identificado', 'Linha '.($indice + 1)." ({$rotulo}): ".collect($e->errors())->flatten()->first(), bloqueia: true);

                continue;
            }

            if (isset($vistos[$produto->id])) {
                $alertas[] = $this->alerta('produto_repetido', "{$produto->produto_descricao} aparece mais de uma vez: some as quantidades numa linha só.", bloqueia: true);

                continue;
            }
            $vistos[$produto->id] = true;

            if (! $produto->produto_controla_estoque) {
                $alertas[] = $this->alerta('nao_controla_estoque', "{$produto->produto_descricao} não controla estoque: tire da contagem.", bloqueia: true);

                continue;
            }

            $saldo = round((float) $produto->produto_saldo_estoque, 3);
            $contado = round((float) $item['quantidade'], 3);
            $diferenca = round($contado - $saldo, 3);
            $custo = (float) $produto->produto_custo_medio;

            if ($diferenca != 0.0 && abs($diferenca) > max(abs($saldo), 0.001) * $limitePct / 100) {
                $alertas[] = $this->alerta('diferenca_grande', "{$produto->produto_descricao}: diferença de {$this->numero($diferenca)} {$produto->produto_unidade_estoque} sobre saldo de {$this->numero($saldo)} — confira a unidade e a contagem.");
            }
            if ($diferenca > 0 && ($produto->produto_controla_lote || $produto->produto_controla_marca)) {
                $alertas[] = $this->alerta('sobra_sem_lote', "{$produto->produto_descricao} controla lote/marca: a sobra entra sem lote/marca informados. Se precisar, lance esse item pela tela de Balanços.");
            }

            $linhas[] = [
                'produto_id' => $produto->id,
                'produto' => $produto->produto_descricao,
                'unidade' => $produto->produto_unidade_estoque,
                'saldo_sistema' => $saldo,
                'contado' => $contado,
                'diferenca' => $diferenca,
                'valor_diferenca' => round($diferenca * $custo, 2),
            ];
        }

        $comDiferenca = collect($linhas)->where('diferenca', '!=', 0.0);
        if ($linhas !== [] && $comDiferenca->isEmpty()) {
            $alertas[] = $this->alerta('sem_diferencas', 'Tudo bate com o sistema: nenhum ajuste a fazer.', bloqueia: true);
        }

        $previa = [
            'itens' => $linhas,
            'totais' => [
                'itens_contados' => count($linhas),
                'itens_com_ajuste' => $comDiferenca->count(),
                'valor_sobras' => round($comDiferenca->where('valor_diferenca', '>', 0)->sum('valor_diferenca'), 2),
                'valor_faltas' => round(abs($comDiferenca->where('valor_diferenca', '<', 0)->sum('valor_diferenca')), 2),
            ],
            'observacao' => $dados['observacao'] ?? null,
            'alertas' => $alertas,
        ];
        $previa['resumo_texto'] = $this->resumo($previa);

        return [
            'entrada' => [
                'itens' => array_map(fn (array $l): array => ['produto_id' => $l['produto_id'], 'quantidade' => $l['contado']], $linhas),
                'observacao' => $dados['observacao'] ?? null,
            ],
            'previa' => $previa,
        ];
    }

    public function executar(array $entrada, array $previa, User $usuario): array
    {
        $observacao = trim('Contagem pelo Claude. '.($entrada['observacao'] ?? ''));
        $ajustes = [];

        foreach ($entrada['itens'] as $item) {
            $produto = Produto::query()->findOrFail($item['produto_id']);
            $saldoAntes = (float) $produto->produto_saldo_estoque;
            $balanco = $this->balancos->realizarBalanco($produto, (float) $item['quantidade'], $observacao);

            if ($balanco !== null) {
                $ajustes[] = [
                    'produto' => $produto->produto_descricao,
                    'balanco_id' => $balanco->id,
                    'saldo_antes' => round($saldoAntes, 3),
                    'saldo_agora' => round((float) $item['quantidade'], 3),
                    'tipo' => $balanco->mbal_tipo_movimentacao->label(),
                ];
            }
        }

        return ['ajustes' => $ajustes, 'sem_ajuste' => count($entrada['itens']) - count($ajustes)];
    }

    /** @param  array<string, mixed>  $previa */
    private function resumo(array $previa): string
    {
        $t = $previa['totais'];
        $linhas = ["Contagem: {$t['itens_contados']} itens, {$t['itens_com_ajuste']} com ajuste (sobras {$this->moeda($t['valor_sobras'])}, faltas {$this->moeda($t['valor_faltas'])})."];

        foreach ($previa['itens'] as $item) {
            if ($item['diferenca'] == 0.0) {
                continue;
            }
            $sinal = $item['diferenca'] > 0 ? '+' : '';
            $linhas[] = "- {$item['produto']}: sistema {$this->numero($item['saldo_sistema'])} → contado {$this->numero($item['contado'])} {$item['unidade']} ({$sinal}{$this->numero($item['diferenca'])}; {$this->moeda($item['valor_diferenca'])})";
        }

        foreach ($previa['alertas'] as $alerta) {
            $linhas[] = ($alerta['bloqueia'] ? '⛔ ' : '⚠️ ').$alerta['mensagem'];
        }

        return implode("\n", $linhas);
    }
}
