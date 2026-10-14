<?php

namespace App\Services\Cozinha;

use App\Events\Cozinha\ProducaoAtualizada;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Avisa as telas (tablets dos setores, Painel de Pedidos, garçom) de que a
 * produção mudou. Driver "polling": um contador por setor no cache, que o
 * componente compara a cada poll sem tocar nas tabelas de produção. Driver
 * "reverb": também dispara ProducaoAtualizada. Sempre depois do commit, para
 * a tela não recarregar antes de o dado existir.
 */
class CozinhaTempoReal
{
    public const CHAVE_PEDIDOS = 'cozinha:v:pedidos';

    /** @param  list<int>  $setorIds */
    public function tocar(array $setorIds, ?int $pedidoId = null): void
    {
        $setorIds = array_values(array_unique(array_map('intval', $setorIds)));

        DB::afterCommit(function () use ($setorIds, $pedidoId): void {
            foreach ($setorIds as $setorId) {
                $this->incrementar(self::chaveSetor($setorId));
            }

            $this->incrementar(self::CHAVE_PEDIDOS);

            if (config('cozinha.tempo_real.driver') === 'reverb') {
                ProducaoAtualizada::dispatch($setorIds, $pedidoId);
            }
        });
    }

    public function versaoSetor(int $setorId): int
    {
        return (int) Cache::get(self::chaveSetor($setorId), 0);
    }

    /** @param  list<int>  $setorIds */
    public function versaoSetores(array $setorIds): string
    {
        return implode('.', array_map(fn (int $id): int => $this->versaoSetor($id), $setorIds));
    }

    public function versaoPedidos(): int
    {
        return (int) Cache::get(self::CHAVE_PEDIDOS, 0);
    }

    public static function chaveSetor(int $setorId): string
    {
        return "cozinha:v:setor:{$setorId}";
    }

    private function incrementar(string $chave): void
    {
        // O store database só incrementa chave existente.
        Cache::add($chave, 0);
        Cache::increment($chave);
    }
}
