<?php

namespace App\Services\Checklist;

use App\Enums\Checklist\ExecucaoStatus;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use Illuminate\Support\Facades\DB;

/**
 * Versionamento do modelo: as execuções guardam snapshot, então editar um
 * modelo que já tem execuções gera uma nova versão. O formulário do Filament
 * salva o modelo e os Repeaters de seções/itens em passos separados — por isso
 * a página de edição captura assinatura() antes de salvar e chama
 * aplicarNovaVersao() depois, em vez de um observer por linha.
 */
class ChecklistModeloService
{
    public function __construct(private ChecklistExecucaoService $execucoes) {}

    /** Hash do conteúdo que vai para o snapshot das execuções (cabeçalho + itens). */
    public function assinatura(ChecklistModelo $modelo): string
    {
        $modelo = $modelo->fresh() ?? $modelo;

        return md5(json_encode([
            $modelo->nome,
            $modelo->tipo->value,
            $modelo->setor->value,
            $modelo->itensOrdenados()->map(fn (ChecklistModeloItem $item): array => $item->toSnapshot())->all(),
        ]));
    }

    /**
     * Se o conteúdo mudou e o modelo já tem execuções: incrementa a versão e
     * refaz o snapshot das execuções pendentes que ainda não têm respostas
     * (as em andamento mantêm o snapshot original).
     *
     * @return bool true se gerou nova versão
     */
    public function aplicarNovaVersao(ChecklistModelo $modelo, string $assinaturaAnterior): bool
    {
        if ($this->assinatura($modelo) === $assinaturaAnterior || ! $modelo->execucoes()->withTrashed()->exists()) {
            return false;
        }

        DB::transaction(function () use ($modelo): void {
            $modelo->increment('versao');

            $modelo->execucoes()
                ->where('status', ExecucaoStatus::Pendente)
                ->get()
                ->each(fn ($execucao) => $this->execucoes->regerarSnapshot($execucao));
        });

        return true;
    }
}
