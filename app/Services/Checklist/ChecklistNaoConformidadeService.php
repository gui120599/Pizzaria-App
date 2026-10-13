<?php

namespace App\Services\Checklist;

use App\Enums\Checklist\NaoConformidadeStatus;
use App\Models\ChecklistNaoConformidade;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Tratamento das não conformidades (plano de ação) pela gestão. */
class ChecklistNaoConformidadeService
{
    /**
     * Grava responsável, prazo, ação corretiva e status. Ao resolver/cancelar
     * registra quem e quando; ao reabrir, limpa esse registro.
     *
     * @param  array{responsavel_id?: ?int, prazo?: mixed, acao_corretiva?: ?string, status?: NaoConformidadeStatus|string, observacao_resolucao?: ?string}  $dados
     */
    public function atualizar(ChecklistNaoConformidade $naoConformidade, array $dados, User $user): ChecklistNaoConformidade
    {
        $naoConformidade->fill(array_intersect_key($dados, array_flip([
            'responsavel_id', 'prazo', 'acao_corretiva', 'status', 'observacao_resolucao',
        ])));

        $encerrada = ! in_array($naoConformidade->status, NaoConformidadeStatus::pendentes(), true);

        if ($naoConformidade->status === NaoConformidadeStatus::Resolvida && blank($naoConformidade->observacao_resolucao)) {
            throw ValidationException::withMessages(['observacao_resolucao' => 'Descreva como a não conformidade foi resolvida.']);
        }

        if ($encerrada && $naoConformidade->isDirty('status')) {
            $naoConformidade->resolvida_por = $user->id;
            $naoConformidade->resolvida_em = now();
        }

        if (! $encerrada) {
            $naoConformidade->resolvida_por = null;
            $naoConformidade->resolvida_em = null;
        }

        $naoConformidade->save();

        return $naoConformidade;
    }
}
