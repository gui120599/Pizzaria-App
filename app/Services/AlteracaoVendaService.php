<?php

namespace App\Services;

use App\Enums\TipoCorrecaoPagamentoEnum;
use App\Exceptions\CorrecaoPagamentoException;
use App\Models\Cliente;
use App\Models\CorrecaoPagamento;
use App\Models\Venda;
use Illuminate\Support\Facades\DB;

/**
 * Alterações de venda finalizada que ainda não tem NFC-e autorizada. Hoje: trocar
 * ou remover o cliente (a forma de pagamento segue em CorrecaoPagamentoService).
 * A troca fica registrada em correcoes_pagamento (tipo ClienteVenda).
 */
class AlteracaoVendaService
{
    public function __construct(private readonly CorrecaoPagamentoService $correcoes) {}

    public function motivoBloqueioCliente(Venda $venda): ?string
    {
        if ($venda->venda_status !== 'FINALIZADA') {
            return 'Só vendas finalizadas são alteradas por aqui.';
        }

        if ($motivo = $this->correcoes->motivoBloqueioNfe($venda)) {
            return $motivo;
        }

        if ($venda->lancamentos()->exists()) {
            return 'Venda a prazo (fiado): trocar o cliente mudaria de quem é a dívida.';
        }

        return null;
    }

    /**
     * @throws CorrecaoPagamentoException
     */
    public function trocarCliente(Venda $venda, ?int $clienteId, ?string $justificativa = null): void
    {
        DB::transaction(function () use ($venda, $clienteId, $justificativa): void {
            $venda = Venda::whereKey($venda->id)->lockForUpdate()->firstOrFail();

            if ($motivo = $this->motivoBloqueioCliente($venda)) {
                throw new CorrecaoPagamentoException($motivo);
            }

            if ((int) $venda->venda_cliente_id === (int) $clienteId) {
                throw new CorrecaoPagamentoException('A venda já está com esse cliente.');
            }

            $novo = $clienteId ? Cliente::find($clienteId) : null;

            if ($clienteId && ! $novo) {
                throw new CorrecaoPagamentoException('Cliente não encontrado.');
            }

            $antes = $this->retrato($venda->cliente);

            $venda->update(['venda_cliente_id' => $novo?->id]);

            CorrecaoPagamento::create([
                'tipo' => TipoCorrecaoPagamentoEnum::ClienteVenda,
                'sessao_caixa_id' => $venda->venda_sessao_caixa_id,
                'venda_id' => $venda->id,
                'antes' => $antes,
                'depois' => $this->retrato($novo),
                'justificativa' => $justificativa,
                'user_id' => auth()->id(),
            ]);
        });
    }

    /** @return array{cliente_id: int|null, cliente: string|null} */
    private function retrato(?Cliente $cliente): array
    {
        return ['cliente_id' => $cliente?->id, 'cliente' => $cliente?->cliente_nome];
    }
}
