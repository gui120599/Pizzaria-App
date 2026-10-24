<?php

namespace App\Services\Compras;

use App\Enums\CompraStatusEnum;
use App\Enums\MovimentacaoTipoEnum;
use App\Enums\StatusLancamento;
use App\Models\Compra;
use App\Models\EstoqueLote;
use App\Models\Lancamento;
use App\Models\PrestadorCredito;
use App\Models\Produto;
use App\Services\CorrecaoEstoqueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Estorna uma compra confirmada: remove as entradas de estoque (com replay do
 * custo médio, via CorrecaoEstoqueService), cancela os títulos a pagar e
 * move a compra para SEM_EFEITO. Primeira peça da Fase 5 do plano
 * docs/planos/operacoes-fiscais-entrada-nfe.md, usada hoje para desfazer uma
 * nota de devolução que foi confirmada como entrada.
 *
 * Sempre manual e auditável: simular() roda tudo e desfaz (rollback), aplicar()
 * grava. Título já pago (total ou parcialmente) e compra com devolução
 * registrada bloqueiam — o pagamento precisa ser estornado antes.
 */
class CompraEstornoService
{
    public function __construct(private CorrecaoEstoqueService $correcoes) {}

    /**
     * @return array{produtos: array<int, array{produto: string, saldo_antes: float, saldo_depois: float, custo_antes: float, custo_depois: float}>, titulos: array<int, array{id: int, parcela: string, valor: float}>, credito_liberado: float, bloqueios: string[], avisos: string[]}
     */
    public function simular(Compra $compra): array
    {
        return $this->executar($compra, 'Simulação de estorno', true);
    }

    /**
     * @return array{produtos: array<int, array{produto: string, saldo_antes: float, saldo_depois: float, custo_antes: float, custo_depois: float}>, titulos: array<int, array{id: int, parcela: string, valor: float}>, credito_liberado: float, bloqueios: string[], avisos: string[]}
     *
     * @throws ValidationException se houver qualquer bloqueio (nada é gravado)
     */
    public function aplicar(Compra $compra, string $motivo): array
    {
        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Informe o motivo do estorno.']);
        }

        return $this->executar($compra, $motivo, false);
    }

    private function executar(Compra $compra, string $motivo, bool $simular): array
    {
        DB::beginTransaction();

        try {
            $relatorio = $this->processar($compra, $motivo);

            if ($simular || $relatorio['bloqueios'] !== []) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if (! $simular && $relatorio['bloqueios'] !== []) {
            throw ValidationException::withMessages(['compra' => $relatorio['bloqueios'][0]]);
        }

        return $relatorio;
    }

    private function processar(Compra $compra, string $motivo): array
    {
        $relatorio = ['produtos' => [], 'titulos' => [], 'credito_liberado' => 0.0, 'bloqueios' => [], 'avisos' => []];

        /** @var Compra $travada */
        $travada = Compra::lockForUpdate()->findOrFail($compra->getKey());
        $compra->setRawAttributes($travada->getAttributes(), true);
        $compra->unsetRelations();

        if ($compra->compra_status !== CompraStatusEnum::CONFIRMADA) {
            $relatorio['bloqueios'][] = 'Só é possível estornar uma compra confirmada.';

            return $relatorio;
        }

        if ($compra->devolucoes()->exists()) {
            $relatorio['bloqueios'][] = 'Esta compra já tem devolução registrada: não dá para estornar a entrada.';
        }

        $titulos = $compra->lancamentos()->lockForUpdate()->get();
        foreach ($titulos as $titulo) {
            $pago = $titulo->status === StatusLancamento::Pago
                || $titulo->status === StatusLancamento::Parcial
                || $titulo->pagamentos()->exists();

            if ($pago) {
                $relatorio['bloqueios'][] = "A parcela {$titulo->parcela_numero}/{$titulo->parcela_total} (R$ ".number_format((float) $titulo->valor, 2, ',', '.').') já tem pagamento: estorne o pagamento antes de estornar a compra.';
            }
        }

        if ($relatorio['bloqueios'] !== []) {
            return $relatorio;
        }

        $entradas = $compra->movimentacoes()
            ->where('mov_tipo', MovimentacaoTipoEnum::ENTRADA)
            ->orderBy('id')
            ->get();

        if ($entradas->isEmpty()) {
            $relatorio['avisos'][] = 'A compra não tem movimentação de entrada de estoque.';
        }

        $antes = [];
        foreach ($entradas as $entrada) {
            $produto = Produto::findOrFail($entrada->mov_produto_id);
            $antes[$produto->id] ??= [
                'produto' => $produto->nomeExibicao(),
                'saldo_antes' => (float) $produto->produto_saldo_estoque,
                'custo_antes' => (float) $produto->produto_custo_medio,
            ];

            if ($entrada->mov_lote_id) {
                $lote = EstoqueLote::find($entrada->mov_lote_id);
                if ($lote && abs((float) $lote->lote_qtd_atual - (float) $lote->lote_qtd_inicial) >= 0.001) {
                    $relatorio['avisos'][] = "O lote {$lote->lote_codigo} de {$antes[$produto->id]['produto']} já foi consumido em parte: confira o saldo por lote (um balanço pode ser necessário).";
                }
            }

            $this->correcoes->aplicarExclusaoDeEntradaDeCompra($entrada, "Estorno da compra #{$compra->id}: {$motivo}");
        }

        foreach ($antes as $produtoId => $valores) {
            $produto = Produto::findOrFail($produtoId);
            $saldoDepois = (float) $produto->produto_saldo_estoque;

            $relatorio['produtos'][] = $valores + [
                'saldo_depois' => $saldoDepois,
                'custo_depois' => (float) $produto->produto_custo_medio,
            ];

            if ($saldoDepois < -0.0005) {
                $relatorio['avisos'][] = "{$valores['produto']} ficaria com saldo negativo (".number_format($saldoDepois, 3, ',', '.').'): parte da mercadoria já foi consumida. Um balanço físico pode ser necessário depois.';
            }
        }

        foreach ($titulos as $titulo) {
            /** @var Lancamento $titulo */
            $titulo->update(['status' => StatusLancamento::Cancelado]);

            $creditos = PrestadorCredito::where('aplicado_em_lancamento_id', $titulo->id)->get();
            foreach ($creditos as $credito) {
                $relatorio['credito_liberado'] += (float) $credito->valor;
                $credito->update(['aplicado_em_lancamento_id' => null]);
            }

            $relatorio['titulos'][] = [
                'id' => $titulo->id,
                'parcela' => "{$titulo->parcela_numero}/{$titulo->parcela_total}",
                'valor' => (float) $titulo->valor,
            ];
        }

        $nota = 'Estornada em '.now()->format('d/m/Y H:i').': '.$motivo;
        $compra->forceFill([
            'compra_status' => CompraStatusEnum::SEM_EFEITO,
            'compra_observacao' => trim(($compra->compra_observacao ? $compra->compra_observacao."\n" : '').$nota),
        ])->save();

        return $relatorio;
    }
}
