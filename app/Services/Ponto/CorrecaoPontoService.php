<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\MotivoAjuste;
use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\TipoAjuste;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\PontoAjuste;
use App\Models\PontoMarcacao;
use App\Models\User;
use App\Notifications\Ponto\PontoCorrigidoNotification;
use App\Support\DiaOperacional;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Correção do ponto por admin/gerente direto no espelho (spec §5.2.1). Para
 * quem usa é "editar o ponto"; por baixo, cada correção vira ajuste(s)
 * aprovados com origem gestor, agrupados por lote_uuid. ponto_marcacoes
 * nunca é alterada.
 *
 * Um horário que já veio de correção não é corrigido de novo por cima:
 * desfaz-se a correção (o lote inteiro) e lança-se outra.
 */
class CorrecaoPontoService
{
    public const JUSTIFICATIVA_MINIMA = 10;

    public function __construct(
        private readonly PontoService $ponto,
        private readonly ApuracaoService $apuracao,
        private readonly MarcacoesDoDia $marcacoes,
        private readonly PeriodosPonto $periodos,
        private readonly PermissoesPonto $permissoes,
    ) {}

    /** Esqueceu de bater: 1 inclusão. */
    public function incluirMarcacao(Colaborador $colaborador, CarbonInterface $dataHora, MotivoAjuste $motivo, string $justificativa, User $gestor): PontoAjuste
    {
        $dia = DiaOperacional::de($dataHora);

        return $this->corrigir($colaborador, $dia, $justificativa, $gestor, 'incluiu uma marcação', function (string $lote) use ($colaborador, $dia, $dataHora, $motivo, $justificativa, $gestor): Collection {
            $momento = $this->ponto->validarHorario($colaborador, $dia, $dataHora);
            $this->ponto->garantirSemMarcacaoProxima($colaborador, $dia, $momento);

            return collect([$this->novoAjuste($colaborador, $dia, TipoAjuste::Inclusao, $motivo, $justificativa, $gestor, $lote, ['data_hora' => $momento])]);
        })->first();
    }

    /**
     * Bateu com hora errada: desconsideração da original + inclusão do horário certo, no mesmo lote.
     *
     * @return Collection<int, PontoAjuste>
     */
    public function corrigirHorario(PontoMarcacao $marcacao, CarbonInterface $novoHorario, MotivoAjuste $motivo, string $justificativa, User $gestor): Collection
    {
        $colaborador = $marcacao->colaborador;
        $dia = CarbonImmutable::instance($marcacao->data_referencia);

        return $this->corrigir($colaborador, $dia, $justificativa, $gestor, 'corrigiu o horário de uma marcação', function (string $lote) use ($marcacao, $colaborador, $dia, $novoHorario, $motivo, $justificativa, $gestor): Collection {
            $this->garantirMarcacaoEfetiva($marcacao);
            $momento = $this->ponto->validarHorario($colaborador, $dia, $novoHorario);
            $this->ponto->garantirSemMarcacaoProxima($colaborador, $dia, $momento, ignorarMarcacaoId: $marcacao->id);

            return collect([
                $this->novoAjuste($colaborador, $dia, TipoAjuste::Desconsideracao, $motivo, $justificativa, $gestor, $lote, ['ponto_marcacao_id' => $marcacao->id]),
                $this->novoAjuste($colaborador, $dia, TipoAjuste::Inclusao, $motivo, $justificativa, $gestor, $lote, ['data_hora' => $momento]),
            ]);
        });
    }

    /** Duplicada, ou batida por outra pessoa: 1 desconsideração. */
    public function desconsiderar(PontoMarcacao $marcacao, MotivoAjuste $motivo, string $justificativa, User $gestor): PontoAjuste
    {
        $colaborador = $marcacao->colaborador;
        $dia = CarbonImmutable::instance($marcacao->data_referencia);

        return $this->corrigir($colaborador, $dia, $justificativa, $gestor, 'desconsiderou uma marcação', function (string $lote) use ($marcacao, $colaborador, $dia, $motivo, $justificativa, $gestor): Collection {
            $this->garantirMarcacaoEfetiva($marcacao);

            return collect([$this->novoAjuste($colaborador, $dia, TipoAjuste::Desconsideracao, $motivo, $justificativa, $gestor, $lote, ['ponto_marcacao_id' => $marcacao->id])]);
        })->first();
    }

    /**
     * Sem acesso ao app: N inclusões no mesmo lote. Com $desconsiderarExistentes,
     * as batidas originais do dia são desconsideradas no mesmo lote.
     *
     * @param  array<int, string>  $horarios  "H:i" dentro do dia operacional (antes da virada = dia seguinte)
     * @return Collection<int, PontoAjuste>
     */
    public function lancarDia(Colaborador $colaborador, CarbonInterface $dia, array $horarios, MotivoAjuste $motivo, string $justificativa, User $gestor, bool $desconsiderarExistentes = false): Collection
    {
        $dia = CarbonImmutable::instance($dia)->startOfDay();

        if ($horarios === [] || count($horarios) % 2 !== 0) {
            throw new PontoException('Informe os horários em pares (entrada e saída), por exemplo 4 horários com intervalo.');
        }

        return $this->corrigir($colaborador, $dia, $justificativa, $gestor, 'lançou o dia completo', function (string $lote) use ($colaborador, $dia, $horarios, $motivo, $justificativa, $gestor, $desconsiderarExistentes): Collection {
            $momentos = collect($horarios)
                ->map(fn (string $hora): CarbonImmutable => $this->ponto->validarHorario($colaborador, $dia, DiaOperacional::momentoNoDia($dia, $hora)))
                ->sortBy(fn (CarbonImmutable $momento): int => $momento->getTimestamp())
                ->values();

            for ($i = 1; $i < $momentos->count(); $i++) {
                if ($momentos[$i]->getTimestamp() - $momentos[$i - 1]->getTimestamp() < 60) {
                    throw new PontoException("Os horários {$momentos[$i - 1]->format('H:i')} e {$momentos[$i]->format('H:i')} têm menos de 1 minuto de diferença.");
                }
            }

            $ajustes = collect();

            if ($desconsiderarExistentes) {
                foreach ($this->marcacoes->efetivas($colaborador, $dia)->whereNotNull('marcacao') as $efetiva) {
                    $ajustes->push($this->novoAjuste($colaborador, $dia, TipoAjuste::Desconsideracao, $motivo, $justificativa, $gestor, $lote, ['ponto_marcacao_id' => $efetiva->marcacao->id]));
                }
            }

            foreach ($momentos as $momento) {
                $this->ponto->garantirSemMarcacaoProxima($colaborador, $dia, $momento);
                $ajustes->push($this->novoAjuste($colaborador, $dia, TipoAjuste::Inclusao, $motivo, $justificativa, $gestor, $lote, ['data_hora' => $momento]));
            }

            return $ajustes;
        });
    }

    /** Declaração, atestado, liberação: 1 abono de N minutos (nulo = dia inteiro). */
    public function abonar(Colaborador $colaborador, CarbonInterface $dia, ?int $minutos, MotivoAjuste $motivo, string $justificativa, User $gestor): PontoAjuste
    {
        $dia = CarbonImmutable::instance($dia)->startOfDay();

        return $this->corrigir($colaborador, $dia, $justificativa, $gestor, $minutos === null ? 'abonou o dia' : "abonou {$minutos} minutos", function (string $lote) use ($colaborador, $dia, $minutos, $motivo, $justificativa, $gestor): Collection {
            $atributos = $this->ponto->validarDadosDoTipo($colaborador, TipoAjuste::Abono, $dia, ['minutos' => $minutos]);

            return collect([$this->novoAjuste($colaborador, $dia, TipoAjuste::Abono, $motivo, $justificativa, $gestor, $lote, $atributos)]);
        })->first();
    }

    /**
     * Os ajustes do lote vão para "desfeito" (quem, quando, por quê); nada é apagado.
     *
     * @return Collection<int, PontoAjuste>
     */
    public function desfazerCorrecao(string $loteUuid, string $justificativa, User $gestor): Collection
    {
        $primeiro = PontoAjuste::query()->doLote($loteUuid)->aprovados()->first();

        if ($primeiro === null) {
            throw new PontoException('Correção não encontrada ou já desfeita.');
        }

        $colaborador = $primeiro->colaborador;
        $dia = CarbonImmutable::instance($primeiro->data_referencia);

        return $this->corrigir($colaborador, $dia, $justificativa, $gestor, 'desfez uma correção', function () use ($loteUuid, $justificativa, $gestor): Collection {
            $ajustes = PontoAjuste::query()->doLote($loteUuid)->aprovados()->lockForUpdate()->get();

            if ($ajustes->isEmpty()) {
                throw new PontoException('Correção não encontrada ou já desfeita.');
            }

            $ajustes->each(fn (PontoAjuste $ajuste) => $ajuste->update([
                'status' => StatusAjuste::Desfeito,
                'desfeito_por' => $gestor->id,
                'desfeito_em' => now(),
                'justificativa_desfazer' => trim($justificativa),
            ]));

            return $ajustes;
        });
    }

    /**
     * Ciência do colaborador sobre as correções do gestor num dia (aprovadas
     * ou desfeitas, ainda sem ciência). Devolve quantas foram marcadas.
     *
     * @throws PontoException
     */
    public function registrarCiencia(Colaborador $colaborador, CarbonInterface $dia, User $usuario): int
    {
        if ($colaborador->user_id !== $usuario->id) {
            throw new PontoException('Só o próprio colaborador dá ciência das correções do seu ponto.');
        }

        $marcados = PontoAjuste::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('data_referencia', CarbonImmutable::instance($dia)->toDateString())
            ->where('origem', OrigemAjuste::Gestor)
            ->whereIn('status', [StatusAjuste::Aprovado, StatusAjuste::Desfeito])
            ->whereNull('colaborador_ciente_em')
            ->update(['colaborador_ciente_em' => now()]);

        if ($marcados === 0) {
            throw new PontoException('Não há correção pendente de ciência neste dia.');
        }

        return $marcados;
    }

    /** @return Collection<int, MarcacaoEfetiva> */
    public function marcacoesEfetivas(Colaborador $colaborador, CarbonInterface $dia): Collection
    {
        return $this->marcacoes->efetivas($colaborador, $dia);
    }

    /**
     * Pipeline comum: autoriza, confere período e justificativa, grava os
     * ajustes com o colaborador travado, reapura o dia e avisa o colaborador.
     *
     * @param  Closure(string): Collection<int, PontoAjuste>  $criarAjustes
     * @return Collection<int, PontoAjuste>
     *
     * @throws PontoException
     */
    private function corrigir(Colaborador $colaborador, CarbonInterface $dia, string $justificativa, User $gestor, string $acao, Closure $criarAjustes): Collection
    {
        $dia = CarbonImmutable::instance($dia)->startOfDay();

        $this->permissoes->garantirCorrecao($gestor, $colaborador);
        $this->periodos->garantirAberto($colaborador, $dia);

        if (mb_strlen(trim($justificativa)) < self::JUSTIFICATIVA_MINIMA) {
            throw new PontoException('A justificativa precisa ter pelo menos '.self::JUSTIFICATIVA_MINIMA.' caracteres.');
        }

        $antes = $this->horarios($colaborador, $dia);

        $ajustes = DB::transaction(function () use ($colaborador, $criarAjustes): Collection {
            Colaborador::query()->whereKey($colaborador->id)->lockForUpdate()->first();

            return $criarAjustes((string) Str::uuid());
        });

        $this->apuracao->apurarDia($colaborador, $dia);

        $colaborador->user?->notify(new PontoCorrigidoNotification(
            $colaborador,
            $dia,
            $gestor,
            $acao,
            $ajustes->first()->motivo,
            $antes,
            $this->horarios($colaborador, $dia),
        ));

        return $ajustes;
    }

    /** @param  array<string, mixed>  $atributos */
    private function novoAjuste(Colaborador $colaborador, CarbonImmutable $dia, TipoAjuste $tipo, MotivoAjuste $motivo, string $justificativa, User $gestor, string $lote, array $atributos): PontoAjuste
    {
        return PontoAjuste::create([
            ...$atributos,
            'colaborador_id' => $colaborador->id,
            'data_referencia' => $dia->toDateString(),
            'tipo' => $tipo,
            'motivo' => $motivo,
            'justificativa' => trim($justificativa),
            'status' => StatusAjuste::Aprovado,
            'origem' => OrigemAjuste::Gestor,
            'solicitado_por' => $gestor->id,
            'aprovado_por' => $gestor->id,
            'decidido_em' => now(),
            'lote_uuid' => $lote,
        ]);
    }

    /** @throws PontoException */
    private function garantirMarcacaoEfetiva(PontoMarcacao $marcacao): void
    {
        $jaDesconsiderada = PontoAjuste::query()
            ->aprovados()
            ->where('tipo', TipoAjuste::Desconsideracao)
            ->where('ponto_marcacao_id', $marcacao->id)
            ->exists();

        if ($jaDesconsiderada) {
            throw new PontoException('Esta marcação já foi desconsiderada. Desfaça a correção anterior para mexer nela de novo.');
        }
    }

    /** @return array<int, string> horários efetivos "H:i" do dia */
    private function horarios(Colaborador $colaborador, CarbonImmutable $dia): array
    {
        return $this->marcacoes->efetivas($colaborador, $dia)
            ->map(fn (MarcacaoEfetiva $efetiva): string => $efetiva->momento->format('H:i'))
            ->all();
    }
}
