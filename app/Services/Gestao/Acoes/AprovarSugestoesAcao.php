<?php

namespace App\Services\Gestao\Acoes;

use App\Enums\Compras\StatusPendencia;
use App\Filament\Resources\CompraPendencias\Tables\CompraPendenciasTable;
use App\Models\CompraPendencia;
use App\Models\User;
use App\Services\Compras\PendenciaService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Aprovar pelo chat as sugestões do agente de compras (vincular produto,
 * cadastrar produto, escolher lançamento) — o mesmo "Aprovar" da tela
 * Pendências de compras. Permissão: resolver pendência de compra.
 */
class AprovarSugestoesAcao extends AcaoBase
{
    public function __construct(private readonly PendenciaService $pendencias) {}

    public function permitido(User $usuario): bool
    {
        return $this->pode($usuario, 'resolver:pendencia_compra');
    }

    /** Sugestões aguardando aprovação, no formato da prévia. */
    public function sugestoes(?array $ids = null, int $limite = 50): array
    {
        return CompraPendencia::query()
            ->where('status', StatusPendencia::Sugerida)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->with(['compra.prestador', 'item'])
            ->orderBy('id')
            ->limit($limite)
            ->get()
            ->map(fn (CompraPendencia $p): array => [
                'pendencia_id' => $p->id,
                'tipo' => $p->tipo->getLabel(),
                'compra_id' => $p->compra_id,
                'nota' => $p->compra?->compra_numero,
                'fornecedor' => $p->compra?->prestador?->nome_exibicao,
                'item_na_nota' => $p->item ? trim("{$p->item->ci_descricao_fornecedor} ({$p->item->ci_unidade_compra})") : null,
                'sugestao' => CompraPendenciasTable::sugestao($p),
                'confianca' => $p->confianca !== null ? (float) $p->confianca : null,
                'justificativa' => $p->justificativa,
            ])
            ->all();
    }

    public function preparar(array $entrada, User $usuario): array
    {
        $dados = Validator::make($entrada, [
            'pendencia_ids' => ['nullable', 'array', 'max:100', 'required_without:todas'],
            'pendencia_ids.*' => ['integer'],
            'todas' => ['nullable', 'boolean'],
        ], ['pendencia_ids.required_without' => 'Informe pendencia_ids ou todas = true.'])->validate();

        $ids = filled($dados['pendencia_ids'] ?? null) ? array_values(array_unique($dados['pendencia_ids'])) : null;
        $sugestoes = $this->sugestoes($ids, 100);
        $alertas = [];

        if ($ids !== null) {
            $fora = array_diff($ids, array_column($sugestoes, 'pendencia_id'));
            if ($fora !== []) {
                $alertas[] = $this->alerta('nao_sugeridas', 'Ficam de fora (não estão aguardando aprovação): #'.implode(', #', $fora).'.');
            }
        }

        if ($sugestoes === []) {
            throw ValidationException::withMessages(['pendencia_ids' => 'Nenhuma sugestão do agente aguardando aprovação'.($ids ? ' entre as informadas' : '').'.']);
        }

        foreach ($sugestoes as $sugestao) {
            if ($sugestao['confianca'] !== null && $sugestao['confianca'] < 0.6) {
                $alertas[] = $this->alerta('baixa_confianca', "#{$sugestao['pendencia_id']}: confiança baixa ({$this->numero($sugestao['confianca'] * 100)}%) — confira antes.");
            }
        }

        $previa = ['sugestoes' => $sugestoes, 'alertas' => $alertas];
        $previa['resumo_texto'] = $this->resumo($previa);

        return ['entrada' => ['pendencia_ids' => array_column($sugestoes, 'pendencia_id')], 'previa' => $previa];
    }

    public function executar(array $entrada, array $previa, User $usuario): array
    {
        [$aprovadas, $erros] = [[], []];

        foreach (CompraPendencia::query()->whereIn('id', $entrada['pendencia_ids'])->get() as $pendencia) {
            if ($pendencia->status !== StatusPendencia::Sugerida) {
                $erros[] = "#{$pendencia->id}: não está mais aguardando aprovação.";

                continue;
            }

            try {
                $this->pendencias->aprovarSugestao($pendencia, $usuario);
                $aprovadas[] = $pendencia->id;
            } catch (ValidationException $e) {
                $erros[] = "#{$pendencia->id}: ".collect($e->errors())->flatten()->first();
            }
        }

        return ['aprovadas' => $aprovadas, 'erros' => $erros];
    }

    /** @param  array<string, mixed>  $previa */
    private function resumo(array $previa): string
    {
        $linhas = ['Aprovar '.count($previa['sugestoes']).' sugestão(ões) do agente:'];

        foreach ($previa['sugestoes'] as $s) {
            $linhas[] = "- #{$s['pendencia_id']} ".($s['fornecedor'] ?? '').($s['item_na_nota'] ? " · {$s['item_na_nota']}" : '').": {$s['sugestao']}";
        }

        foreach ($previa['alertas'] as $alerta) {
            $linhas[] = ($alerta['bloqueia'] ? '⛔ ' : '⚠️ ').$alerta['mensagem'];
        }

        return implode("\n", $linhas);
    }
}
