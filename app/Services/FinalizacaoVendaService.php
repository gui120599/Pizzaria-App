<?php

namespace App\Services;

use App\Exceptions\VendaNaoFinalizavelException;
use App\Http\Requests\StoreClienteRequest;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\Venda;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Fonte única do fluxo de finalização/cancelamento de uma Venda, usada tanto
 * pelo VendaController legado quanto pela Filament Page OperarVenda, para os
 * dois não divergirem enquanto coexistirem.
 */
class FinalizacaoVendaService
{
    private const TOLERANCIA_CENTAVOS = 0.005;

    /**
     * @param  array{cpf?: ?string, cnpj?: ?string, telefone?: ?string, nome?: ?string, email?: ?string}|null  $clienteAdHoc
     * @param  array<int, int>  $idSessaoMesa
     * @param  array<int, int>  $idPedido
     *
     * @throws VendaNaoFinalizavelException
     * @throws ValidationException
     */
    public function finalizar(Venda $venda, ?array $clienteAdHoc = null, array $idSessaoMesa = [], array $idPedido = []): Venda
    {
        return DB::transaction(function () use ($venda, $clienteAdHoc, $idSessaoMesa, $idPedido) {
            $sessaoCaixa = SessaoCaixa::findOrFail($venda->venda_sessao_caixa_id);

            $this->validarPagamento($venda);

            if ($venda->venda_cliente_id === null && $clienteAdHoc) {
                $venda->venda_cliente_id = $this->resolverClienteAdHoc($clienteAdHoc)->id;
            }

            $venda->venda_status = 'FINALIZADA';
            $venda->venda_datahora_finalizada = Carbon::now();
            $venda->save();

            MovimentacoesSessaoCaixa::create([
                'mov_sessaocaixa_id' => $sessaoCaixa->id,
                'mov_venda_id' => $venda->id,
                'mov_descricao' => 'VENDA: '.$venda->id,
                'mov_tipo' => 'ENTRADA',
                'mov_valor' => $venda->venda_valor_total,
            ]);

            $valorTotalVendas = Venda::where('venda_sessao_caixa_id', $sessaoCaixa->id)
                ->where('venda_status', 'FINALIZADA')
                ->sum('venda_valor_total');

            $sessaoCaixa->update([
                'sessaocaixa_saldo_final' => $sessaoCaixa->sessaocaixa_saldo_inicial + $valorTotalVendas,
            ]);

            $this->finalizarSessoesEMesas($idSessaoMesa, $venda->id);
            $this->finalizarPedidosIndividuais($idPedido, $venda->id);

            return $venda->fresh();
        });
    }

    public function cancelar(Venda $venda, ?string $motivo, ?int $usuarioId = null): Venda
    {
        $venda->update([
            'venda_status' => 'CANCELADA',
            'venda_motivo_cancelamento' => $motivo,
            'venda_usuario_cancelou_id' => $usuarioId ?? Auth::id(),
            'venda_datahora_cancelada' => Carbon::now(),
        ]);

        ItensPedido::where('item_pedido_venda_id', $venda->id)
            ->update(['item_pedido_venda_id' => null]);

        return $venda->fresh();
    }

    private function validarPagamento(Venda $venda): void
    {
        $total = round((float) $venda->venda_valor_total, 2);
        $pago = round((float) $venda->venda_valor_pago, 2);
        $troco = round((float) $venda->venda_valor_troco, 2);

        if ($total <= 0) {
            return;
        }

        if ($pago + self::TOLERANCIA_CENTAVOS < $total) {
            throw new VendaNaoFinalizavelException('Valor pago insuficiente para finalizar a venda.');
        }

        if (($pago - $total) > self::TOLERANCIA_CENTAVOS && $troco <= self::TOLERANCIA_CENTAVOS) {
            throw new VendaNaoFinalizavelException('O valor recebido excede o total da venda, mas nenhum troco foi informado. Ajuste o valor recebido para o total ou informe o valor pago pelo cliente para gerar o troco.');
        }
    }

    /**
     * Resolve um cliente por CPF/CNPJ (reaproveitando um já cadastrado) ou
     * cria um novo cadastro mínimo. Público para ser reaproveitado pela
     * Page OperarVenda ao vincular um cliente à venda antes da finalização
     * (não só no momento de finalizar).
     *
     * @param  array{cpf?: ?string, cnpj?: ?string, telefone?: ?string, nome?: ?string, email?: ?string}  $dados
     *
     * @throws ValidationException
     */
    public function resolverClienteAdHoc(array $dados): Cliente
    {
        $cpf = ! empty($dados['cpf']) ? str_replace(['.', '-', ' '], '', $dados['cpf']) : null;
        $cnpj = ! empty($dados['cnpj']) ? str_replace(['.', '-', '/', ' '], '', $dados['cnpj']) : null;
        $celular = ! empty($dados['telefone']) ? str_replace(['(', ')', '-', ' '], '', $dados['telefone']) : null;

        $cliente = null;
        if ($cpf) {
            $cliente = Cliente::where('cliente_cpf', $cpf)->first();
        } elseif ($cnpj) {
            $cliente = Cliente::where('cliente_cnpj', $cnpj)->first();
        }

        if ($cliente) {
            return $cliente;
        }

        $clienteData = [
            'cliente_nome' => $dados['nome'] ?? 'Cliente não identificado',
            'cliente_cpf' => $cpf,
            'cliente_cnpj' => $cnpj,
            'cliente_celular' => $celular,
            'cliente_email' => $dados['email'] ?? null,
            'cliente_tipo' => $cpf ? 'Física' : 'Jurídica',
        ];

        $rules = (new StoreClienteRequest)->rules();
        unset($rules['cliente_cpf'], $rules['cliente_cnpj']);

        $validator = Validator::make($clienteData, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return Cliente::create($clienteData);
    }

    /**
     * @param  array<int, int>  $idSessaoMesa
     */
    private function finalizarSessoesEMesas(array $idSessaoMesa, int $vendaId): void
    {
        foreach ($idSessaoMesa as $sessaoId) {
            $sessaoMesa = SessaoMesa::find($sessaoId);
            if (! $sessaoMesa) {
                continue;
            }

            $sessaoMesa->update(['sessao_mesa_status' => 'FINALIZADA']);

            $mesa = Mesa::find($sessaoMesa->sessao_mesa_mesa_id);
            // Libera a mesa apenas se ela ainda estiver ocupada por ESTA sessão.
            // Se uma nova sessão já ocupa a mesa, ela permanece OCUPADA.
            if ($mesa
                && $mesa->mesa_status === 'OCUPADA'
                && (int) $mesa->mesa_sessao_atual_id === (int) $sessaoMesa->id) {
                $mesa->update([
                    'mesa_status' => 'LIBERADA',
                    'mesa_sessao_atual_id' => null,
                ]);
            }

            $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoId)
                ->where('pedido_status', '<>', 'CANCELADO')
                ->get();

            foreach ($pedidos as $pedido) {
                $dados = [
                    'pedido_venda_id' => $vendaId,
                    'pedido_datahora_finalizado' => Carbon::now(),
                ];

                if ($pedido->pedido_status === 'ENTREGUE') {
                    $dados['pedido_status'] = 'FINALIZADO';
                }

                $pedido->update($dados);
            }
        }
    }

    /**
     * @param  array<int, int>  $idPedido
     */
    private function finalizarPedidosIndividuais(array $idPedido, int $vendaId): void
    {
        foreach ($idPedido as $pedidoId) {
            $pedido = Pedido::where('id', $pedidoId)->where('pedido_status', '<>', 'CANCELADO')->first();
            if (! $pedido) {
                continue;
            }

            $dados = [
                'pedido_venda_id' => $vendaId,
                'pedido_datahora_finalizado' => Carbon::now(),
            ];

            if (in_array($pedido->pedido_status, ['ENTREGUE', 'EM TRANSPORTE'])) {
                $dados['pedido_status'] = 'FINALIZADO';
            }

            $pedido->update($dados);
        }
    }
}
