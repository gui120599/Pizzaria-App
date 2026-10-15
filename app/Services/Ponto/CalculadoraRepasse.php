<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\ModalidadeRemuneracao;
use App\Enums\Ponto\NaturezaItem;
use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\SituacaoDia;
use App\Models\Colaborador;
use App\Models\ColaboradorRemuneracao;
use App\Models\PontoDia;
use App\Models\PontoPeriodo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Itens automáticos de jornada do repasse (spec §5.4), a partir dos dias
 * apurados. Tempo em minutos inteiros; o arredondamento a 2 casas só no
 * valor final de cada item. Adiantamentos ficam com o RepasseService.
 */
class CalculadoraRepasse
{
    /** Divisor de horas do mês quando a remuneração mensal não tem carga semanal (44 h). */
    private const DIVISOR_MENSAL_PADRAO = 220;

    /** Mês comercial, para salário proporcional e desconto de falta. */
    private const DIAS_MES = 30;

    /**
     * @param  Collection<int, PontoDia>  $dias
     * @return list<array{natureza: NaturezaItem, descricao: string, quantidade: float, valor_unitario: float, valor: float}>
     */
    public function calcular(Colaborador $colaborador, ColaboradorRemuneracao $remuneracao, PontoPeriodo $periodo, Collection $dias): array
    {
        $resumo = $this->resumir($dias);
        $valorBase = (float) $remuneracao->valor_base;
        $valorHora = $this->valorHora($remuneracao);
        $itens = [];

        switch ($remuneracao->modalidade) {
            case ModalidadeRemuneracao::Mensal:
                [$diasAtivos, $diasPeriodo] = $this->diasAtivos($colaborador, $periodo);
                $fracao = $this->fracaoDoMes($periodo->periodicidade) * $diasAtivos / $diasPeriodo;
                $itens[] = self::item(NaturezaItem::SalarioBase, 'Salário base'.($diasAtivos < $diasPeriodo ? " ({$diasAtivos} de {$diasPeriodo} dias)" : ''), 1, $valorBase * $fracao);
                $itens = [...$itens, ...$this->extras($remuneracao, $valorHora, $resumo['extra_50'], $resumo['extra_100'])];
                $itens[] = $this->adicionalNoturno($remuneracao, $valorHora, $resumo['noturno_equivalente']);

                if ($resumo['faltas'] > 0) {
                    $itens[] = self::item(NaturezaItem::Falta, 'Faltas ('.self::dias($resumo['faltas']).')', $resumo['faltas'], $valorBase / self::DIAS_MES);
                }

                $itens[] = self::porHora(NaturezaItem::Atraso, 'Atrasos', $resumo['atraso'], $valorHora);
                break;

            case ModalidadeRemuneracao::PorHora:
                $itens[] = self::porHora(NaturezaItem::HorasNormais, 'Horas normais', $resumo['trabalhado'] - $resumo['extra_50'] - $resumo['extra_100'], $valorBase);
                $itens = [...$itens, ...$this->extras($remuneracao, $valorHora, $resumo['extra_50'], $resumo['extra_100'])];
                $itens[] = $this->adicionalNoturno($remuneracao, $valorHora, $resumo['noturno_equivalente']);
                break;

            case ModalidadeRemuneracao::PorDiaria:
                $itens[] = self::item(NaturezaItem::Diarias, 'Diárias ('.self::dias($resumo['dias_trabalhados']).')', $resumo['dias_trabalhados'], $valorBase);

                // Extra só se houver carga diária (é ela que dá o valor da hora). Os
                // minutos vêm da apuração, com tolerância e hora reduzida já aplicadas.
                if ($valorHora !== null) {
                    $itens = [...$itens, ...$this->extras($remuneracao, $valorHora, $resumo['extra_50'], $resumo['extra_100'])];
                }
                break;

            case ModalidadeRemuneracao::PorTurno:
                $itens[] = self::item(NaturezaItem::Turnos, 'Turnos ('.$resumo['dias_trabalhados'].')', $resumo['dias_trabalhados'], $valorBase);
                break;

            case ModalidadeRemuneracao::SomenteEntregas:
                break;
        }

        $valorPorEntrega = (float) $remuneracao->valor_por_entrega;

        if ($valorPorEntrega > 0 && $resumo['entregas'] > 0) {
            $itens[] = self::item(NaturezaItem::Entregas, "Entregas ({$resumo['entregas']})", $resumo['entregas'], $valorPorEntrega);
        }

        return array_values(array_filter($itens, fn (?array $item): bool => $item !== null && $item['valor'] > 0));
    }

    /**
     * Totais do período (também gravados em repasses.resumo_jornada).
     *
     * @param  Collection<int, PontoDia>  $dias
     * @return array{dias_trabalhados: int, trabalhado: int, extra_50: int, extra_100: int, noturno: int, noturno_equivalente: int, atraso: int, faltas: int, entregas: int}
     */
    public function resumir(Collection $dias): array
    {
        return [
            'dias_trabalhados' => $dias->filter(fn (PontoDia $dia): bool => $dia->minutos_trabalhados > 0)->count(),
            'trabalhado' => (int) $dias->sum('minutos_trabalhados'),
            'extra_50' => (int) $dias->sum('minutos_extra_50'),
            'extra_100' => (int) $dias->sum('minutos_extra_100'),
            'noturno' => (int) $dias->sum('minutos_noturnos'),
            // Com hora reduzida, o acréscimo já vem da apuração (zero quando a remuneração não considera).
            'noturno_equivalente' => (int) $dias->sum('minutos_noturnos') + (int) $dias->sum('minutos_acrescimo_noturno'),
            'atraso' => (int) $dias->sum('minutos_atraso'),
            'faltas' => $dias->filter(fn (PontoDia $dia): bool => $dia->situacao === SituacaoDia::Falta)->count(),
            'entregas' => (int) $dias->sum('entregas_qtd'),
        ];
    }

    /**
     * Mensal: valor_base / (carga semanal em horas × 5) — 220 para 44 h.
     * Por hora: o próprio valor. Por diária: valor / carga diária (se houver).
     */
    public function valorHora(ColaboradorRemuneracao $remuneracao): ?float
    {
        $valorBase = (float) $remuneracao->valor_base;

        return match ($remuneracao->modalidade) {
            ModalidadeRemuneracao::Mensal => $valorBase / ($remuneracao->carga_semanal_minutos > 0
                ? $remuneracao->carga_semanal_minutos / 60 * 5
                : self::DIVISOR_MENSAL_PADRAO),
            ModalidadeRemuneracao::PorHora => $valorBase,
            ModalidadeRemuneracao::PorDiaria => $remuneracao->carga_diaria_minutos > 0 ? $valorBase / ($remuneracao->carga_diaria_minutos / 60) : null,
            default => null,
        };
    }

    /** @return list<array{natureza: NaturezaItem, descricao: string, quantidade: float, valor_unitario: float, valor: float}|null> */
    private function extras(ColaboradorRemuneracao $remuneracao, float $valorHora, int $minutos50, int $minutos100): array
    {
        $percentual50 = (float) $remuneracao->percentual_extra_util;
        $percentual100 = (float) $remuneracao->percentual_extra_domingo_feriado;

        return [
            self::porHora(NaturezaItem::HoraExtra50, 'Hora extra '.self::percentual($percentual50), $minutos50, $valorHora * (1 + $percentual50 / 100)),
            self::porHora(NaturezaItem::HoraExtra100, 'Hora extra '.self::percentual($percentual100), $minutos100, $valorHora * (1 + $percentual100 / 100)),
        ];
    }

    /** @return array{natureza: NaturezaItem, descricao: string, quantidade: float, valor_unitario: float, valor: float}|null */
    private function adicionalNoturno(ColaboradorRemuneracao $remuneracao, float $valorHora, int $minutosEquivalentes): ?array
    {
        $percentual = (float) $remuneracao->percentual_adicional_noturno;

        return self::porHora(NaturezaItem::AdicionalNoturno, 'Adicional noturno '.self::percentual($percentual), $minutosEquivalentes, $valorHora * $percentual / 100);
    }

    /** Fração do salário mensal que cabe num período da periodicidade. */
    private function fracaoDoMes(PeriodicidadeRepasse $periodicidade): float
    {
        return match ($periodicidade) {
            PeriodicidadeRepasse::Mensal => 1,
            PeriodicidadeRepasse::Quinzenal => 0.5,
            PeriodicidadeRepasse::Semanal => 7 / self::DIAS_MES,
        };
    }

    /**
     * Dias do período em que o colaborador estava ativo (admissão/desligamento no meio).
     *
     * @return array{0: int, 1: int}
     */
    private function diasAtivos(Colaborador $colaborador, PontoPeriodo $periodo): array
    {
        $inicio = CarbonImmutable::instance($periodo->data_inicio);
        $fim = CarbonImmutable::instance($periodo->data_fim);
        $diasPeriodo = (int) $inicio->diffInDays($fim) + 1;

        $deAtivo = $colaborador->data_admissao->gt($inicio) ? CarbonImmutable::instance($colaborador->data_admissao) : $inicio;
        $ateAtivo = $colaborador->data_desligamento?->lt($fim) ? CarbonImmutable::instance($colaborador->data_desligamento) : $fim;

        return [max(0, (int) $deAtivo->diffInDays($ateAtivo) + 1), $diasPeriodo];
    }

    /** @return array{natureza: NaturezaItem, descricao: string, quantidade: float, valor_unitario: float, valor: float}|null */
    private static function porHora(NaturezaItem $natureza, string $descricao, int $minutos, ?float $valorHora): ?array
    {
        if ($minutos <= 0 || $valorHora === null) {
            return null;
        }

        $horas = $minutos / 60;

        return self::item($natureza, $descricao.' ('.number_format($horas, 2, ',', '.').' h)', $horas, $valorHora);
    }

    /** @return array{natureza: NaturezaItem, descricao: string, quantidade: float, valor_unitario: float, valor: float} */
    private static function item(NaturezaItem $natureza, string $descricao, float $quantidade, float $valorUnitario): array
    {
        return [
            'natureza' => $natureza,
            'descricao' => $descricao,
            'quantidade' => round($quantidade, 2),
            'valor_unitario' => round($valorUnitario, 2),
            'valor' => round($quantidade * $valorUnitario, 2),
        ];
    }

    private static function percentual(float $percentual): string
    {
        return rtrim(rtrim(number_format($percentual, 2, ',', ''), '0'), ',').'%';
    }

    private static function dias(int $quantidade): string
    {
        return $quantidade === 1 ? '1 dia' : "{$quantidade} dias";
    }
}
