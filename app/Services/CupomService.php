<?php

namespace App\Services;

use App\Enums\TipoDescontoEnum;
use App\Exceptions\CupomInvalidoException;
use App\Models\Cliente;
use App\Models\Cupom;

/**
 * Motor de validação/consumo de cupom de desconto — genérico de propósito:
 * usado hoje pelo checkout público do cardápio, mas não depende de nada do
 * Livewire/HTTP para poder ser chamado depois por um agente de WhatsApp.
 */
class CupomService
{
    /**
     * Validação "otimista": roda antes de abrir a transação de gravação do
     * pedido, só para dar feedback rápido ao cliente. A garantia real contra
     * corrida em cupom de uso único vem de consumir(), chamada dentro da
     * transação com lock de linha.
     *
     * @return array{cupom: Cupom, valor_desconto: float}
     *
     * @throws CupomInvalidoException
     */
    public function validar(string $codigo, float $valorPedido, ?Cliente $cliente): array
    {
        $cupom = Cupom::where('cupom_codigo', $codigo)->first();

        if (! $cupom) {
            throw new CupomInvalidoException('Cupom não encontrado.');
        }

        $this->checarRegras($cupom, $valorPedido, $cliente);

        return [
            'cupom' => $cupom,
            'valor_desconto' => $this->calcularDesconto($cupom, $valorPedido),
        ];
    }

    /**
     * Revalidação atômica dentro da transação que vai gravar o pedido — deve
     * ser chamada ANTES do Pedido::create(), com lock de linha no cupom, para
     * fechar a janela de corrida entre duas requisições simultâneas no mesmo
     * cupom de uso único (não há coluna contadora dedicada: o uso é sempre um
     * COUNT() sobre pedidos, então o lock de linha do cupom é o que serializa
     * o acesso concorrente).
     *
     * @throws CupomInvalidoException
     */
    public function consumir(Cupom $cupom, float $valorPedido, ?Cliente $cliente): void
    {
        $cupomTravado = Cupom::whereKey($cupom->id)->lockForUpdate()->firstOrFail();

        $this->checarRegras($cupomTravado, $valorPedido, $cliente);
    }

    /**
     * @throws CupomInvalidoException
     */
    private function checarRegras(Cupom $cupom, float $valorPedido, ?Cliente $cliente): void
    {
        if (! $cupom->cupom_ativo) {
            throw new CupomInvalidoException('Este cupom está inativo.');
        }

        $hoje = now()->toDateString();

        if ($cupom->cupom_data_inicio && $hoje < $cupom->cupom_data_inicio->toDateString()) {
            throw new CupomInvalidoException('Este cupom ainda não está válido.');
        }

        if ($cupom->cupom_data_expiracao && $hoje > $cupom->cupom_data_expiracao->toDateString()) {
            throw new CupomInvalidoException('Este cupom expirou.');
        }

        if ($cupom->cupom_valor_minimo_pedido && $valorPedido < (float) $cupom->cupom_valor_minimo_pedido) {
            $minimo = number_format((float) $cupom->cupom_valor_minimo_pedido, 2, ',', '.');
            throw new CupomInvalidoException("O pedido mínimo para usar este cupom é R$ {$minimo}.");
        }

        if ($cupom->cupom_limite_uso_total !== null && $cupom->pedidosValidos()->count() >= $cupom->cupom_limite_uso_total) {
            throw new CupomInvalidoException('Este cupom atingiu o limite de usos.');
        }

        if ($cliente && $cupom->pedidosValidos()->where('pedido_cliente_id', $cliente->id)->count() >= $cupom->cupom_limite_uso_por_cliente) {
            throw new CupomInvalidoException('Você já usou este cupom o número máximo de vezes permitido.');
        }
    }

    private function calcularDesconto(Cupom $cupom, float $valorPedido): float
    {
        $desconto = $cupom->cupom_tipo_desconto === TipoDescontoEnum::PERCENTUAL
            ? $valorPedido * ((float) $cupom->cupom_valor_desconto / 100)
            : (float) $cupom->cupom_valor_desconto;

        return round(min($desconto, $valorPedido), 2);
    }
}
