<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\TipoMarcacao;
use App\Models\PontoMarcacao;

/**
 * Quem está "em turno": a última marcação das últimas 16 h é entrada ou volta
 * do intervalo. Compartilhado entre a escolha de operador da cozinha e o push
 * do salão.
 */
class PontoAbertoService
{
    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function usuariosComPontoAberto(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $ultimas = PontoMarcacao::query()
            ->join('colaboradores', 'colaboradores.id', '=', 'ponto_marcacoes.colaborador_id')
            ->whereIn('colaboradores.user_id', $userIds)
            ->where('ponto_marcacoes.registrada_em', '>=', now()->subHours(16))
            ->orderBy('ponto_marcacoes.registrada_em')
            ->get(['colaboradores.user_id', 'ponto_marcacoes.tipo_sugerido'])
            ->keyBy('user_id');

        return $ultimas
            ->filter(fn (PontoMarcacao $m) => in_array($m->tipo_sugerido, [TipoMarcacao::Entrada, TipoMarcacao::RetornoIntervalo], true))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
