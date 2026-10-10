<?php

namespace App\Services\Sefaz;

use App\Exceptions\SefazConsultaEmEsperaException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Respeita as regras de uso indevido do NFeDistribuicaoDFe (NT 2014.002)
 * antes de chamar a SEFAZ, guardando o estado em cache:
 *
 * - Bloqueio do CNPJ (cStat 656): 1 hora sem NENHUMA consulta — tentar antes
 *   reinicia o relógio na SEFAZ, então nem chamamos.
 * - Sem novidades por NSU (ultNSU = maxNSU): nova consulta por NSU só depois
 *   de 1 hora.
 * - Consultas repetidas da mesma chave: a SEFAZ limita por chave/hora; aqui
 *   paramos um pouco antes do limite (config sefaz.limite_consultas_por_chave_hora).
 */
class SefazControleConsumo
{
    private const BLOQUEIO = 'sefaz:bloqueado_ate';

    private const NSU_SEM_NOVIDADES = 'sefaz:nsu_liberado_em';

    /** @throws SefazConsultaEmEsperaException se o CNPJ estiver bloqueado pela SEFAZ */
    public function garantirLiberado(): void
    {
        $ate = $this->bloqueadoAte();
        if ($ate !== null) {
            throw SefazConsultaEmEsperaException::bloqueioSefaz($ate);
        }
    }

    public function bloqueadoAte(): ?Carbon
    {
        return $this->momentoFuturo(self::BLOQUEIO);
    }

    public function registrarBloqueio(): Carbon
    {
        return $this->guardarAte(self::BLOQUEIO, now()->addMinutes($this->minutosEspera()));
    }

    /** Null quando a consulta por NSU está liberada. */
    public function nsuLiberadoEm(): ?Carbon
    {
        return $this->momentoFuturo(self::NSU_SEM_NOVIDADES);
    }

    public function registrarNsuSemNovidades(): Carbon
    {
        return $this->guardarAte(self::NSU_SEM_NOVIDADES, now()->addMinutes($this->minutosEspera()));
    }

    /** @throws SefazConsultaEmEsperaException se a chave já atingiu o limite de consultas da hora */
    public function garantirChaveLiberada(string $chave): void
    {
        $registro = Cache::get($this->chaveContador($chave));
        if ($registro === null) {
            return;
        }

        if ($registro['total'] >= (int) config('sefaz.limite_consultas_por_chave_hora', 15)) {
            throw SefazConsultaEmEsperaException::limiteDaChave(Carbon::parse($registro['inicio'])->addHour());
        }
    }

    public function registrarConsultaChave(string $chave): void
    {
        $registro = Cache::get($this->chaveContador($chave)) ?? ['inicio' => now()->toIso8601String(), 'total' => 0];
        $registro['total']++;

        Cache::put($this->chaveContador($chave), $registro, Carbon::parse($registro['inicio'])->addHour());
    }

    private function chaveContador(string $chave): string
    {
        return "sefaz:consultas_chave:{$chave}";
    }

    private function momentoFuturo(string $chaveCache): ?Carbon
    {
        $valor = Cache::get($chaveCache);
        if ($valor === null) {
            return null;
        }

        $momento = Carbon::parse($valor);

        return $momento->isFuture() ? $momento : null;
    }

    private function guardarAte(string $chaveCache, Carbon $ate): Carbon
    {
        Cache::put($chaveCache, $ate->toIso8601String(), $ate);

        return $ate;
    }

    /** 1 hora da regra da SEFAZ + 1 minuto de margem pra diferença de relógio. */
    private function minutosEspera(): int
    {
        return (int) config('sefaz.minutos_espera_consumo', 61);
    }
}
