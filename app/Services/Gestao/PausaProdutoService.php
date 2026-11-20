<?php

namespace App\Services\Gestao;

use App\Models\Produto;
use App\Models\ProdutoPausa;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pausa temporária de produto (acabou o insumo): desliga o produto do
 * cardápio online e/ou do atendimento/garçom e religa sozinho no horário
 * combinado (comando produtos:retomar-pausas) ou na retomada manual.
 */
class PausaProdutoService
{
    /**
     * Pausa (ou estende a pausa em aberto) nos canais pedidos. Só desliga o
     * que estava ligado; canal já desligado fora de pausa continua como está.
     *
     * @param  list<string>  $canais
     */
    public function pausar(Produto $produto, array $canais, ?CarbonInterface $ate, ?string $motivo, ?User $usuario): ProdutoPausa
    {
        return DB::transaction(function () use ($produto, $canais, $ate, $motivo, $usuario): ProdutoPausa {
            $produto = Produto::query()->lockForUpdate()->findOrFail($produto->id);
            $pausa = ProdutoPausa::query()->emAberto()->where('produto_id', $produto->id)->lockForUpdate()->first();

            $desligar = collect($canais)
                ->filter(fn (string $canal): bool => (bool) $produto->{ProdutoPausa::CAMPOS[$canal]})
                ->values();

            foreach ($desligar as $canal) {
                $produto->{ProdutoPausa::CAMPOS[$canal]} = false;
            }
            $produto->save();

            if ($pausa !== null) {
                $pausa->update([
                    'canais' => collect($pausa->canais)->merge($desligar)->unique()->values()->all(),
                    'pausado_ate' => $ate,
                    'motivo' => $motivo ?? $pausa->motivo,
                ]);

                return $pausa;
            }

            return ProdutoPausa::create([
                'produto_id' => $produto->id,
                'user_id' => $usuario?->id,
                'canais' => $desligar->all(),
                'motivo' => $motivo,
                'pausado_ate' => $ate,
            ]);
        });
    }

    /** Religa os canais que a pausa desligou e encerra a pausa. */
    public function retomar(ProdutoPausa $pausa, ?User $usuario = null): ProdutoPausa
    {
        return DB::transaction(function () use ($pausa, $usuario): ProdutoPausa {
            $pausa = ProdutoPausa::query()->lockForUpdate()->findOrFail($pausa->id);
            if ($pausa->retomada_em !== null) {
                return $pausa;
            }

            $produto = Produto::query()->withTrashed()->lockForUpdate()->find($pausa->produto_id);
            if ($produto !== null) {
                foreach ($pausa->canais as $canal) {
                    $produto->{ProdutoPausa::CAMPOS[$canal]} = true;
                }
                $produto->save();
            }

            $pausa->update(['retomada_em' => now(), 'retomada_por' => $usuario?->id]);

            return $pausa;
        });
    }

    /** Retoma as pausas cujo horário já passou. Devolve quantas. */
    public function retomarVencidas(?CarbonInterface $momento = null): int
    {
        $momento ??= now();

        return ProdutoPausa::query()->emAberto()
            ->whereNotNull('pausado_ate')
            ->where('pausado_ate', '<=', $momento)
            ->get()
            ->each(fn (ProdutoPausa $pausa) => $this->retomar($pausa))
            ->count();
    }
}
