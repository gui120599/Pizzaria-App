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
 *  - as unidades elegíveis formam grupos na ordem de inclusão: a 1ª e a 2ª
 *    fecham o combo, a 3ª sai cheia e vira pendência ("mais 1 fecha outro");
 *  - um grupo só vale se ficar mais barato (o combo nunca encarece);
 *  - uma unidade nunca entra em duas aplicações;
 *  - o desconto do grupo é rateado em centavos, proporcional ao preço de cada unidade.
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
            if ($regra->quantidade < 1) {
                continue;
            }

            $elegiveis = array_keys(array_filter(
                $unidades,
                fn (array $unidade): bool => ! $unidade['usada'] && $regra->aceita($unidade['linha']),
            ));

            $ja = $jaAplicadas[$regra->promocaoId] ?? 0;
            $limite = $regra->limitePorPedido === null ? PHP_INT_MAX : max(0, $regra->limitePorPedido - $ja);
            $fechados = 0;
            $posicao = 0;

            while ($fechados < $limite && $posicao + $regra->quantidade <= count($elegiveis)) {
                $grupo = array_slice($elegiveis, $posicao, $regra->quantidade);
                $posicao += $regra->quantidade;

                $bases = array_map(fn (int $indice): int => $unidades[$indice]['linha']->baseUnitariaCentavos, $grupo);
                $desconto = array_sum($bases) - $regra->precoCentavos;

                if ($desconto <= 0) {
                    continue;
                }

                foreach ($grupo as $indice) {
                    $unidades[$indice]['usada'] = true;
                }

                $aplicacoes[] = $this->aplicacao($regra, $grupo, $bases, $desconto, $unidades, $ja + $fechados);
                $fechados++;
            }

            $sobra = count($elegiveis) - $posicao;

            if ($fechados < $limite && $sobra > 0) {
                $pendencias[] = new PendenciaCombo(
                    $regra->promocaoId,
                    $regra->nome,
                    $regra->quantidade,
                    $regra->precoCentavos,
                    $regra->quantidade - $sobra,
                    $regra->sugerir,
                );
            }
        }

        return new PlanoCombo($aplicacoes, $pendencias);
    }

    /**
     * @param  list<int>  $grupo  índices das unidades
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
            $regra->quantidade,
            $regra->precoCentavos,
            $regra->promocaoId.':'.implode(',', $linhas).'#'.$ordinal,
            $partes,
        );
    }
}
