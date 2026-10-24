<?php

namespace App\Services;

use App\Enums\OrigemCupomEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Exceptions\CupomInvalidoException;
use App\Models\Cliente;
use App\Models\Cupom;
use App\Models\Empresa;

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
    public function validar(string $codigo, float $valorPedido, ?Cliente $cliente, ?int $ignorarPedidoId = null): array
    {
        $cupom = Cupom::where('cupom_codigo', Cupom::normalizarCodigo($codigo))->first();

        if (! $cupom) {
            throw new CupomInvalidoException('Cupom não encontrado.');
        }

        $this->checarRegras($cupom, $valorPedido, $cliente, $ignorarPedidoId);

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
    public function consumir(Cupom $cupom, float $valorPedido, ?Cliente $cliente, ?int $ignorarPedidoId = null): void
    {
        $cupomTravado = Cupom::whereKey($cupom->id)->lockForUpdate()->firstOrFail();

        $this->checarRegras($cupomTravado, $valorPedido, $cliente, $ignorarPedidoId);
    }

    /**
     * Teto do cupom criado pelo influenciador (configuração da Empresa). Cupom
     * interno, criado pela equipe, não tem teto. Repetido aqui, além do
     * formulário, para valer em qualquer caminho de gravação.
     *
     * @throws CupomInvalidoException
     */
    public function validarTeto(Cupom $cupom): void
    {
        if ($cupom->cupom_origem !== OrigemCupomEnum::INFLUENCIADOR) {
            return;
        }

        $empresa = Empresa::first();

        if (! $empresa) {
            return;
        }

        $valor = (float) $cupom->cupom_valor_desconto;

        if ($cupom->cupom_tipo_desconto === TipoDescontoEnum::PERCENTUAL) {
            $teto = (float) $empresa->empresa_influ_teto_percentual;

            if ($valor > $teto) {
                throw new CupomInvalidoException("O desconto máximo permitido é {$teto}%.");
            }
        } else {
            $teto = (float) $empresa->empresa_influ_teto_valor_fixo;

            if ($valor > $teto) {
                throw new CupomInvalidoException('O desconto máximo permitido é R$ '.number_format($teto, 2, ',', '.').'.');
            }
        }

        $tetoUsos = $empresa->empresa_influ_teto_usos_total;

        if ($tetoUsos !== null && ($cupom->cupom_limite_uso_total === null || $cupom->cupom_limite_uso_total > $tetoUsos)) {
            throw new CupomInvalidoException("O limite total de usos não pode passar de {$tetoUsos}.");
        }
    }

    /**
     * Depois do primeiro uso válido, tipo e valor do desconto não mudam: o que
     * os clientes e as comissões já viram continua valendo.
     *
     * @throws CupomInvalidoException
     */
    public function validarImutabilidade(Cupom $cupom): void
    {
        if (! $cupom->exists || ! $cupom->isDirty(['cupom_tipo_desconto', 'cupom_valor_desconto'])) {
            return;
        }

        if ($cupom->jaFoiUsado()) {
            throw new CupomInvalidoException('Este cupom já foi usado: o tipo e o valor do desconto não podem mais ser alterados.');
        }
    }

    /**
     * @throws CupomInvalidoException
     */
    private function checarRegras(Cupom $cupom, float $valorPedido, ?Cliente $cliente, ?int $ignorarPedidoId = null): void
    {
        if (! $cupom->cupom_ativo) {
            throw new CupomInvalidoException('Este cupom está inativo.');
        }

        // Cupom de influenciador só vale com o dono ativo (inativado ou excluído = recusado).
        if ($cupom->cupom_influenciador_id !== null
            && $cupom->influenciador?->influenciador_status !== StatusInfluenciadorEnum::ATIVO) {
            throw new CupomInvalidoException('Este cupom está indisponível.');
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

        // Reaplicar no próprio pedido (ou trocar de cupom) não pode contar o pedido contra o limite.
        $usos = fn () => $cupom->pedidosValidos()->when($ignorarPedidoId, fn ($query) => $query->where('pedidos.id', '!=', $ignorarPedidoId));

        if ($cupom->cupom_limite_uso_total !== null && $usos()->count() >= $cupom->cupom_limite_uso_total) {
            throw new CupomInvalidoException('Este cupom atingiu o limite de usos.');
        }

        if ($cliente && $usos()->where('pedido_cliente_id', $cliente->id)->count() >= $cupom->cupom_limite_uso_por_cliente) {
            throw new CupomInvalidoException('Você já usou este cupom o número máximo de vezes permitido.');
        }
    }

    /** Desconto do cupom sobre um valor líquido (limitado a ele). */
    public function calcularDesconto(Cupom $cupom, float $valorPedido): float
    {
        $desconto = $cupom->cupom_tipo_desconto === TipoDescontoEnum::PERCENTUAL
            ? $valorPedido * ((float) $cupom->cupom_valor_desconto / 100)
            : (float) $cupom->cupom_valor_desconto;

        return round(min($desconto, $valorPedido), 2);
    }
}
