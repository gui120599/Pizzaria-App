<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\SituacaoDia;
use App\Enums\Ponto\StatusPeriodo;
use App\Enums\Ponto\StatusRepasse;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\PontoDia;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use App\Models\User;
use App\Support\DiaOperacional;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fechamento do período (spec §3.5): apura tudo, bloqueia se houver dia
 * incompleto, trava o período (em conferência) e gera os repasses em
 * rascunho. As demais inconsistências (intervalo, jornada > 10 h, fora da
 * cerca) não bloqueiam: voltam como avisos para o gestor conferir.
 */
class FechamentoPeriodoService
{
    /** Quantos dias listar na mensagem de bloqueio. */
    private const LIMITE_LISTA = 10;

    public function __construct(
        private readonly ApuracaoService $apuracao,
        private readonly RepasseService $repasses,
    ) {}

    /**
     * @return array{repasses: Collection<int, Repasse>, avisos: list<string>}
     *
     * @throws PontoException
     */
    public function iniciarFechamento(PontoPeriodo $periodo, User $usuario): array
    {
        $this->garantirPermissao($usuario);

        return DB::transaction(function () use ($periodo): array {
            $periodo = PontoPeriodo::query()->lockForUpdate()->findOrFail($periodo->id);

            if ($periodo->status !== StatusPeriodo::Aberto) {
                throw new PontoException("O período {$periodo->rotulo()} não está aberto.");
            }

            if (DiaOperacional::de(now())->lte($periodo->data_fim)) {
                throw new PontoException("O período só pode ser fechado depois do último dia ({$periodo->data_fim->format('d/m/Y')}).");
            }

            $this->apuracao->apurarPeriodo($periodo);

            $dias = PontoDia::query()->with('colaborador')->where('ponto_periodo_id', $periodo->id)->orderBy('data_referencia')->get();
            $incompletos = $dias->where('situacao', SituacaoDia::Incompleto);

            if ($incompletos->isNotEmpty()) {
                throw new PontoException('Há dias com marcação faltante. Corrija antes de fechar: '.self::listar($incompletos).'.');
            }

            $semRemuneracao = Colaborador::query()->doPeriodo($periodo)->orderBy('nome')->get()
                ->reject(fn (Colaborador $colaborador): bool => $this->temRemuneracao($colaborador, $periodo));

            if ($semRemuneracao->isNotEmpty()) {
                throw new PontoException('Sem remuneração vigente no período: '.$semRemuneracao->pluck('nome')->join(', ').'.');
            }

            $periodo->forceFill(['status' => StatusPeriodo::EmConferencia])->save();

            return [
                'repasses' => $this->repasses->gerarRepasses($periodo),
                'avisos' => $dias->where('situacao', SituacaoDia::Inconsistente)
                    ->map(fn (PontoDia $dia): string => "{$dia->colaborador->nome} em {$dia->data_referencia->format('d/m')}: ".implode(', ', $dia->rotulosInconsistencias()))
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * Em conferência → aberto, se nenhum repasse foi aprovado. Os rascunhos
     * ficam e são recalculados no próximo fechamento.
     *
     * @throws PontoException
     */
    public function reabrir(PontoPeriodo $periodo, User $usuario): void
    {
        $this->garantirPermissao($usuario);

        DB::transaction(function () use ($periodo): void {
            $periodo = PontoPeriodo::query()->lockForUpdate()->findOrFail($periodo->id);

            if ($periodo->status !== StatusPeriodo::EmConferencia) {
                throw new PontoException('Só um período em conferência pode ser reaberto.');
            }

            if ($periodo->repasses()->whereIn('status', [StatusRepasse::Aprovado, StatusRepasse::Pago])->exists()) {
                throw new PontoException('Há repasse aprovado neste período. Cancele as aprovações antes de reabrir.');
            }

            $periodo->forceFill(['status' => StatusPeriodo::Aberto])->save();
        });
    }

    /**
     * Aprova de uma vez os rascunhos prontos (CLT só com holerite conferido;
     * líquido negativo fica). Os recusados voltam com o motivo.
     *
     * @return array{aprovados: int, recusados: list<string>}
     */
    public function aprovarConferidos(PontoPeriodo $periodo, User $usuario): array
    {
        $aprovados = 0;
        $recusados = [];

        foreach ($periodo->repasses()->with('colaborador')->where('status', StatusRepasse::Rascunho)->get() as $repasse) {
            try {
                $this->repasses->aprovar($repasse, $usuario);
                $aprovados++;
            } catch (PontoException $e) {
                $recusados[] = "{$repasse->colaborador->nome}: {$e->getMessage()}";
            }
        }

        return ['aprovados' => $aprovados, 'recusados' => $recusados];
    }

    private function temRemuneracao(Colaborador $colaborador, PontoPeriodo $periodo): bool
    {
        try {
            $this->repasses->remuneracaoDoPeriodo($colaborador, $periodo);

            return true;
        } catch (PontoException) {
            return false;
        }
    }

    /** @throws PontoException */
    private function garantirPermissao(User $usuario): void
    {
        if (! $usuario->can('fechar:ponto_periodo')) {
            throw new PontoException('Você não tem permissão para fechar períodos de ponto.');
        }
    }

    /** @param  Collection<int, PontoDia>  $dias */
    private static function listar(Collection $dias): string
    {
        $lista = $dias->take(self::LIMITE_LISTA)
            ->map(fn (PontoDia $dia): string => "{$dia->colaborador->nome} em {$dia->data_referencia->format('d/m')}")
            ->join('; ');

        return $dias->count() > self::LIMITE_LISTA ? $lista.' e mais '.($dias->count() - self::LIMITE_LISTA) : $lista;
    }
}
