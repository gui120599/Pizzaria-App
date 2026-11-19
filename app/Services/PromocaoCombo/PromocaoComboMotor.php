<?php

namespace App\Services\PromocaoCombo;

use App\Support\RateioCentavos;

/**
 * Identifica as promoções combo de um conjunto de linhas. Puro (sem banco):
 * a mesma entrada devolve sempre o mesmo plano, então cardápio, garçom e
 * Atender Pedido chegam no mesmo preço.
 *
 * Regras (docs/planos/promocoes-combo-inteligentes.md, seção 7):
 *  - cada linha vira tantas unidades quanto sua quantidade inteira;
 *  - promoções em ordem de prioridade (maior primeiro), depois id;
 *  - um combo tem grupos ("1 Pizza Grande" + "1 Pizza Brotinho") e fecha
 *    quando todos estão completos; as unidades entram na ordem de inclusão,
 *    cada uma no grupo em que cabe (um emparelhamento remaneja as já
 *    encaixadas quando o item serve para mais de um grupo);
 *  - o que sobra vira pendência, com o que falta em cada grupo;
 *  - um combo só vale se ficar mais barato (o combo nunca encarece);
 *  - uma unidade nunca entra em duas aplicações;
 *  - o desconto do combo é rateado em centavos, proporcional ao preço de cada unidade.
 */
class PromocaoComboMotor
{
    /**
     * @param  list<LinhaCombo>  $linhas
     * @param  list<RegraCombo>  $regras
     * @param  array<int, int>  $jaAplicadas  promoção => aplicações que já existem e não serão refeitas (contam no limite por pedido)
     */
    public function planejar(array $linhas, array $regras, array $jaAplicadas = []): PlanoCombo
    {
        usort($linhas, fn (LinhaCombo $a, LinhaCombo $b): int => [$a->ordem, $a->chave] <=> [$b->ordem, $b->chave]);
        usort($regras, fn (RegraCombo $a, RegraCombo $b): int => [$b->prioridade, $a->promocaoId] <=> [$a->prioridade, $b->promocaoId]);

        /** @var list<array{linha: LinhaCombo, usada: bool}> $unidades */
        $unidades = [];

        foreach ($linhas as $linha) {
            for ($i = 0; $i < $linha->unidades; $i++) {
                $unidades[] = ['linha' => $linha, 'usada' => false];
            }
        }

        $aplicacoes = [];
        $pendencias = [];

        foreach ($regras as $regra) {
            if ($regra->quantidade() < 1) {
                continue;
            }

            $gruposPorLinha = [];
            $aceitas = [];

            foreach ($unidades as $indice => $unidade) {
                $chave = $unidade['linha']->chave;
                $gruposPorLinha[$chave] ??= $regra->gruposQueAceitam($unidade['linha']);

                if (! $unidade['usada'] && $gruposPorLinha[$chave] !== []) {
                    $aceitas[$indice] = $gruposPorLinha[$chave];
                }
            }

            $ja = $jaAplicadas[$regra->promocaoId] ?? 0;
            $limite = $regra->limitePorPedido === null ? PHP_INT_MAX : max(0, $regra->limitePorPedido - $ja);
            $fechados = 0;
            $descartadas = [];
            $incompleto = null;

            while ($fechados < $limite) {
                [$escolha, $faltam] = $this->montarCombo($regra, $aceitas, $descartadas);

                if ($faltam !== []) {
                    $incompleto = $escolha !== [] ? $faltam : null;

                    break;
                }

                $grupo = array_keys($escolha);
                $bases = array_map(fn (int $indice): int => $unidades[$indice]['linha']->baseUnitariaCentavos, $grupo);
                $desconto = array_sum($bases) - $regra->precoCentavos;

                // Não compensa (combo encareceria): a primeira unidade fica de fora e tenta de novo.
                if ($desconto <= 0) {
                    $descartadas[$grupo[0]] = true;

                    continue;
                }

                foreach ($grupo as $indice) {
                    $unidades[$indice]['usada'] = true;
                    unset($aceitas[$indice]);
                }

                $aplicacoes[] = $this->aplicacao($regra, $grupo, $bases, $desconto, $unidades, $ja + $fechados);
                $fechados++;
            }

            if ($fechados < $limite && $incompleto !== null) {
                $porGrupo = [];

                foreach ($incompleto as $indiceGrupo => $quantos) {
                    $porGrupo[$regra->grupos[$indiceGrupo]->id] = $quantos;
                }

                $pendencias[] = new PendenciaCombo(
                    $regra->promocaoId,
                    $regra->nome,
                    $regra->quantidade(),
                    $regra->precoCentavos,
                    array_sum($porGrupo),
                    $regra->sugerir,
                    $porGrupo,
                );
            }
        }

        return new PlanoCombo($aplicacoes, $pendencias);
    }

    /**
     * Preenche as vagas de um combo com as unidades na ordem de inclusão.
     * Emparelhamento (caminho aumentante): uma unidade nova pode remanejar
     * outra já encaixada para um grupo em que ela também caiba.
     *
     * @param  array<int, list<int>>  $aceitas  unidade => grupos em que cabe
     * @param  array<int, true>  $descartadas
     * @return array{0: array<int, int>, 1: array<int, int>} [unidade => grupo, grupo => vagas que faltam]
     */
    private function montarCombo(RegraCombo $regra, array $aceitas, array $descartadas): array
    {
        $vagas = [];

        foreach ($regra->grupos as $indiceGrupo => $grupo) {
            for ($i = 0; $i < $grupo->quantidade; $i++) {
                $vagas[] = $indiceGrupo;
            }
        }

        $dono = array_fill(0, count($vagas), null);
        $preenchidas = 0;

        foreach (array_keys($aceitas) as $unidade) {
            if (isset($descartadas[$unidade])) {
                continue;
            }

            $visitadas = [];

            if ($this->encaixar($unidade, $aceitas, $vagas, $dono, $visitadas)) {
                $preenchidas++;
            }

            if ($preenchidas === count($vagas)) {
                break;
            }
        }

        $escolha = [];
        $faltam = [];

        foreach ($dono as $vaga => $unidade) {
            if ($unidade === null) {
                $faltam[$vagas[$vaga]] = ($faltam[$vagas[$vaga]] ?? 0) + 1;
            } else {
                $escolha[$unidade] = $vagas[$vaga];
            }
        }

        ksort($escolha);
        ksort($faltam);

        return [$escolha, $faltam];
    }

    /**
     * @param  array<int, list<int>>  $aceitas
     * @param  list<int>  $vagas  vaga => grupo
     * @param  array<int, int|null>  $dono  vaga => unidade
     * @param  array<int, true>  $visitadas
     */
    private function encaixar(int $unidade, array $aceitas, array $vagas, array &$dono, array &$visitadas): bool
    {
        foreach ($vagas as $vaga => $indiceGrupo) {
            if (isset($visitadas[$vaga]) || ! in_array($indiceGrupo, $aceitas[$unidade], true)) {
                continue;
            }

            $visitadas[$vaga] = true;

            if ($dono[$vaga] === null || $this->encaixar($dono[$vaga], $aceitas, $vagas, $dono, $visitadas)) {
                $dono[$vaga] = $unidade;

                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $grupo  índices das unidades, na ordem de inclusão
     * @param  list<int>  $bases  preço de cada unidade do grupo (centavos)
     * @param  list<array{linha: LinhaCombo, usada: bool}>  $unidades
     */
    private function aplicacao(RegraCombo $regra, array $grupo, array $bases, int $desconto, array $unidades, int $ordinal): AplicacaoCombo
    {
        $rateio = RateioCentavos::ratearProporcional($desconto, $bases);
        $partes = [];

        foreach ($grupo as $posicao => $indice) {
            $chave = $unidades[$indice]['linha']->chave;
            $partes[$chave] ??= ['unidades' => 0, 'cheio' => 0, 'desconto' => 0];
            $partes[$chave]['unidades']++;
            $partes[$chave]['cheio'] += $bases[$posicao];
            $partes[$chave]['desconto'] += $rateio[$posicao];
        }

        $linhas = array_map(fn (int $indice): string => $unidades[$indice]['linha']->chave, $grupo);

        return new AplicacaoCombo(
            $regra->promocaoId,
            $regra->nome,
            $regra->quantidade(),
            $regra->precoCentavos,
            $regra->promocaoId.':'.implode(',', $linhas).'#'.$ordinal,
            $partes,
        );
    }
}
