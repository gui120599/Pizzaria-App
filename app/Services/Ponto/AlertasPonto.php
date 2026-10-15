<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\TipoAjuste;
use App\Models\Colaborador;
use App\Models\Feriado;
use App\Models\User;
use App\Notifications\Ponto\MarcacaoFaltanteNotification;
use App\Support\DiaOperacional;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Avisos de marcação faltante (agendado a cada 30 min):
 * - entrada prevista há mais de 30 min sem nenhuma marcação → gestores;
 * - marcação ímpar mais de 1h depois do fim do turno → o colaborador.
 * Cada aviso sai uma vez por colaborador/dia/motivo.
 */
class AlertasPonto
{
    private const ATRASO_ENTRADA_MIN = 30;

    private const ATRASO_SAIDA_MIN = 60;

    public function __construct(private readonly MarcacoesDoDia $marcacoes) {}

    /** @return int avisos enviados */
    public function alertarFaltantes(CarbonInterface $agora): int
    {
        $agora = CarbonImmutable::instance($agora);
        $hoje = DiaOperacional::de($agora);
        $gestores = GestoresPonto::destinatarios();
        $enviados = 0;

        foreach (Colaborador::ativos($hoje)->with('user')->get() as $colaborador) {
            $enviados += $this->entradaFaltante($colaborador, $hoje, $agora, $gestores);

            // O turno de ontem pode ter terminado depois da meia-noite.
            foreach ([$hoje->subDay(), $hoje] as $dia) {
                $enviados += $this->saidaFaltante($colaborador, $dia, $agora);
            }
        }

        return $enviados;
    }

    /** @param  Collection<int, User>  $gestores */
    private function entradaFaltante(Colaborador $colaborador, CarbonImmutable $dia, CarbonImmutable $agora, Collection $gestores): int
    {
        $escalaDia = $colaborador->escalaVigente($dia)?->diaDe($dia);

        if ($escalaDia === null || ! $escalaDia->trabalha() || $colaborador->escalaVigente($dia)->tipo->semPrevisto() || Feriado::ehFeriado($dia)) {
            return 0;
        }

        $entrada = $escalaDia->entradaEm($dia);

        if ($agora->lessThan($entrada->addMinutes(self::ATRASO_ENTRADA_MIN)) || $agora->greaterThanOrEqualTo($escalaDia->saidaEm($dia))) {
            return 0;
        }

        $dispensado = $this->marcacoes->ajustesAprovados($colaborador, $dia)->contains(fn ($ajuste): bool => in_array($ajuste->tipo, [TipoAjuste::FolgaCompensatoria, TipoAjuste::FaltaJustificada], true)
            || ($ajuste->tipo === TipoAjuste::Abono && $ajuste->minutos === null));

        if ($dispensado || $this->marcacoes->efetivas($colaborador, $dia)->isNotEmpty()) {
            return 0;
        }

        $enviados = 0;

        foreach ($gestores as $gestor) {
            if ($gestor->id === $colaborador->user_id || $this->jaAvisado($gestor, $colaborador, $dia, MarcacaoFaltanteNotification::ENTRADA)) {
                continue;
            }

            $gestor->notify(new MarcacaoFaltanteNotification($colaborador, $dia, MarcacaoFaltanteNotification::ENTRADA, $entrada));
            $enviados++;
        }

        return $enviados;
    }

    private function saidaFaltante(Colaborador $colaborador, CarbonImmutable $dia, CarbonImmutable $agora): int
    {
        $usuario = $colaborador->user;
        $escalaDia = $colaborador->escalaVigente($dia)?->diaDe($dia);

        if ($usuario === null || $escalaDia === null || ! $escalaDia->trabalha()) {
            return 0;
        }

        $saida = $escalaDia->saidaEm($dia);

        if ($agora->lessThan($saida->addMinutes(self::ATRASO_SAIDA_MIN))
            || $this->marcacoes->efetivas($colaborador, $dia)->count() % 2 === 0
            || $this->jaAvisado($usuario, $colaborador, $dia, MarcacaoFaltanteNotification::SAIDA)) {
            return 0;
        }

        $usuario->notify(new MarcacaoFaltanteNotification($colaborador, $dia, MarcacaoFaltanteNotification::SAIDA, $saida));

        return 1;
    }

    private function jaAvisado(User $destinatario, Colaborador $colaborador, CarbonImmutable $dia, string $motivo): bool
    {
        return $destinatario->notifications()
            ->where('type', MarcacaoFaltanteNotification::class)
            ->where('data->colaborador_id', $colaborador->id)
            ->where('data->dia', $dia->toDateString())
            ->where('data->motivo', $motivo)
            ->exists();
    }
}
