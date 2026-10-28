<?php

namespace App\Services;

use App\Enums\TipoMovimentoCarteiraEnum;
use App\Exceptions\CarteiraInfluenciadorException;
use App\Models\CarteiraMovimento;
use App\Models\Influenciador;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Carteira do influenciador: extrato append-only (saldo = soma dos movimentos).
 * Todo débito trava a linha do influenciador e confere o saldo; crédito de
 * estorno pode deixar o saldo negativo, e saldo negativo bloqueia débitos.
 */
class CarteiraInfluenciadorService
{
    public function saldo(Influenciador|int $influenciador): float
    {
        $id = $influenciador instanceof Influenciador ? $influenciador->getKey() : $influenciador;

        return round((float) CarteiraMovimento::where('cm_influenciador_id', $id)->sum('cm_valor'), 2);
    }

    /**
     * Lança um valor positivo (crédito) ou negativo (estorno de crédito, que
     * pode deixar o saldo negativo). Sem conferência de saldo.
     *
     * @param  array{comissao_id?: ?int, resgate_id?: ?int, pagamento_venda_id?: ?int, user_id?: ?int, observacao?: ?string}  $vinculos
     */
    public function lancar(Influenciador $influenciador, TipoMovimentoCarteiraEnum $tipo, float $valor, array $vinculos = []): CarteiraMovimento
    {
        return $this->gravar($influenciador, $tipo, round($valor, 2), $vinculos);
    }

    /**
     * Debita o saldo (resgate ou consumo): trava o influenciador, confere o
     * saldo e grava o movimento negativo na mesma transação.
     *
     * @param  array{comissao_id?: ?int, resgate_id?: ?int, pagamento_venda_id?: ?int, user_id?: ?int, observacao?: ?string}  $vinculos
     *
     * @throws CarteiraInfluenciadorException
     */
    public function debitar(Influenciador $influenciador, TipoMovimentoCarteiraEnum $tipo, float $valor, array $vinculos = []): CarteiraMovimento
    {
        $valor = round($valor, 2);

        if ($valor <= 0) {
            throw new CarteiraInfluenciadorException('O valor do débito precisa ser maior que zero.');
        }

        return DB::transaction(function () use ($influenciador, $tipo, $valor, $vinculos): CarteiraMovimento {
            Influenciador::whereKey($influenciador->getKey())->lockForUpdate()->firstOrFail();

            $saldo = $this->saldo($influenciador);

            if ($saldo < 0) {
                throw new CarteiraInfluenciadorException('A carteira está com saldo negativo: não é possível debitar.');
            }

            if ($valor > $saldo + 0.001) {
                throw new CarteiraInfluenciadorException('Saldo insuficiente (disponível: R$ '.number_format($saldo, 2, ',', '.').').');
            }

            return $this->gravar($influenciador, $tipo, -$valor, $vinculos);
        });
    }

    /**
     * Ajuste manual (positivo ou negativo) com observação obrigatória. Exige a
     * permissão `ajustar:carteira_influenciador` (Admin passa pelo Gate).
     *
     * @throws CarteiraInfluenciadorException
     */
    public function ajustar(Influenciador $influenciador, float $valor, string $observacao, User $usuario): CarteiraMovimento
    {
        if (! $usuario->can('ajustar:carteira_influenciador')) {
            throw new CarteiraInfluenciadorException('Você não tem permissão para ajustar a carteira.');
        }

        if (mb_strlen(trim($observacao)) < 5) {
            throw new CarteiraInfluenciadorException('Informe o motivo do ajuste (mínimo de 5 caracteres).');
        }

        $valor = round($valor, 2);

        if (abs($valor) < 0.01) {
            throw new CarteiraInfluenciadorException('O ajuste precisa ter valor diferente de zero.');
        }

        return DB::transaction(function () use ($influenciador, $valor, $observacao, $usuario): CarteiraMovimento {
            Influenciador::whereKey($influenciador->getKey())->lockForUpdate()->firstOrFail();

            if ($valor < 0 && $this->saldo($influenciador) + $valor < -0.001) {
                throw new CarteiraInfluenciadorException('O ajuste deixaria a carteira negativa.');
            }

            return $this->gravar($influenciador, TipoMovimentoCarteiraEnum::AJUSTE, $valor, [
                'user_id' => $usuario->id,
                'observacao' => trim($observacao),
            ]);
        });
    }

    /** @param  array<string, mixed>  $vinculos */
    private function gravar(Influenciador $influenciador, TipoMovimentoCarteiraEnum $tipo, float $valor, array $vinculos): CarteiraMovimento
    {
        return CarteiraMovimento::create([
            'cm_influenciador_id' => $influenciador->getKey(),
            'cm_tipo' => $tipo,
            'cm_valor' => $valor,
            'cm_comissao_id' => $vinculos['comissao_id'] ?? null,
            'cm_resgate_id' => $vinculos['resgate_id'] ?? null,
            'cm_pagamento_venda_id' => $vinculos['pagamento_venda_id'] ?? null,
            'cm_user_id' => $vinculos['user_id'] ?? auth()->id(),
            'cm_observacao' => $vinculos['observacao'] ?? null,
        ]);
    }
}
