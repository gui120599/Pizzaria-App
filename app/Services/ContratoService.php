<?php

namespace App\Services;

use App\Enums\StatusContrato;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Contrato;
use App\Models\Lancamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContratoService
{
    /**
     * Gera o lançamento (título a pagar) da competência informada para o contrato,
     * se ele ainda estiver ativo/vigente naquela competência e ainda não tiver
     * lançamento gerado para ela (idempotência via unique ['contrato_id','competencia']).
     */
    public function gerarLancamentoMensal(Contrato $contrato, Carbon $competencia): ?Lancamento
    {
        return DB::transaction(function () use ($contrato, $competencia): ?Lancamento {
            $inicioCompetencia = $competencia->copy()->startOfMonth();

            if ($contrato->status !== StatusContrato::Ativo) {
                return null;
            }

            if ($contrato->data_inicio->gt($inicioCompetencia->copy()->endOfMonth())
                || ($contrato->data_fim !== null && $contrato->data_fim->lt($inicioCompetencia))) {
                return null;
            }

            $jaGerado = Lancamento::where('contrato_id', $contrato->id)
                ->whereDate('competencia', $inicioCompetencia)
                ->exists();

            if ($jaGerado) {
                return null;
            }

            return Lancamento::create([
                'tipo' => TipoLancamento::Pagar,
                'contrato_id' => $contrato->id,
                'competencia' => $inicioCompetencia,
                'plano_despesa_id' => $contrato->plano_despesa_id,
                'favorecido_id' => $contrato->favorecido_id,
                'descricao' => $this->descricaoLancamento($contrato, $inicioCompetencia),
                'numero_documento' => $contrato->numero_documento,
                'valor' => $contrato->valor,
                'vencimento' => $contrato->vencimentoParaCompetencia($inicioCompetencia),
                'status' => StatusLancamento::Pendente,
                'forma_pagamento' => $contrato->forma_pagamento,
            ]);
        });
    }

    /** Gera o lançamento da competência para todo contrato ativo e vigente nela. */
    public function gerarLancamentosDoMes(Carbon $competencia): array
    {
        $gerados = [];

        Contrato::ativos()->vigentes($competencia)->get()->each(function (Contrato $contrato) use ($competencia, &$gerados): void {
            $lancamento = $this->gerarLancamentoMensal($contrato, $competencia);

            if ($lancamento !== null) {
                $gerados[] = $lancamento;
            }
        });

        return $gerados;
    }

    /** Encerra (persistindo o status) todo contrato ativo cuja vigência já passou. */
    public function encerrarVencidos(): int
    {
        return Contrato::ativos()
            ->whereNotNull('data_fim')
            ->whereDate('data_fim', '<', now()->startOfDay())
            ->update(['status' => StatusContrato::Encerrado]);
    }

    private function descricaoLancamento(Contrato $contrato, Carbon $competencia): string
    {
        return sprintf('%s - %s/%s', $contrato->descricao, $competencia->format('m'), $competencia->format('Y'));
    }
}
