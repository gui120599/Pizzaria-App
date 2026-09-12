<?php

namespace App\Services;

use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\VendaNaoFinalizavelException;
use App\Http\Requests\StoreClienteRequest;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Lancamento;
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

    public function __construct(private readonly MovimentacaoCaixaService $movimentacaoCaixa) {}

    /**
     * @param  array{cpf?: ?string, cnpj?: ?string, telefone?: ?string, nome?: ?string, email?: ?string}|null  $clienteAdHoc
     * @param  array<int, int>  $idSessaoMesa
     * @param  array<int, int>  $idPedido
     * @param  bool  $permitirSaldoAberto  Libera finalizar com pago < total (venda fiado/parcial); o saldo vira um Lancamento a receber.
     * @param  ?Carbon  $fiadoVencimento  Vencimento do título gerado quando $permitirSaldoAberto e há saldo restante. Default: hoje + 7 dias.
     *
     * @throws VendaNaoFinalizavelException
     * @throws ValidationException
     */
    public function finalizar(
        Venda $venda,
        ?array $clienteAdHoc = null,
        array $idSessaoMesa = [],
        array $idPedido = [],
        bool $permitirSaldoAberto = false,
        ?Carbon $fiadoVencimento = null,
    ): Venda {
        return DB::transaction(function () use ($venda, $clienteAdHoc, $idSessaoMesa, $idPedido, $permitirSaldoAberto, $fiadoVencimento) {
            $sessaoCaixa = SessaoCaixa::findOrFail($venda->venda_sessao_caixa_id);

            if ($venda->venda_cliente_id === null && $clienteAdHoc) {
                $venda->venda_cliente_id = $this->resolverClienteAdHoc($clienteAdHoc)->id;
            }

            $saldoRestante = max(0.0, round((float) $venda->venda_valor_total - (float) $venda->venda_valor_pago, 2));
            $gerarFiado = $permitirSaldoAberto && $saldoRestante > self::TOLERANCIA_CENTAVOS;

            $this->validarPagamento($venda, $permitirSaldoAberto);

            if ($gerarFiado) {
                $this->validarLimiteCredito($venda, $saldoRestante);
            }

            $venda->venda_status = 'FINALIZADA';
            $venda->venda_datahora_finalizada = Carbon::now();
            $venda->save();

            if ($gerarFiado) {
                Lancamento::create([
                    'tipo' => TipoLancamento::Receber,
                    'venda_id' => $venda->id,
                    'cliente_id' => $venda->venda_cliente_id,
                    'descricao' => "Venda #{$venda->id} - saldo fiado",
                    'valor' => $saldoRestante,
                    'vencimento' => $fiadoVencimento ?? Carbon::now()->addDays(7),
                    'status' => StatusLancamento::Pendente,
                ]);
            }

            // mov_valor usa o valor efetivamente PAGO (não o total): numa venda
            // fiado/parcial, o saldo em aberto vira Lancamento a receber acima,
            // não dinheiro que entrou de fato no caixa desta sessão.
            MovimentacoesSessaoCaixa::create([
                'mov_sessaocaixa_id' => $sessaoCaixa->id,
                'mov_venda_id' => $venda->id,
                'mov_descricao' => 'VENDA: '.$venda->id,
                'mov_tipo' => 'ENTRADA',
                'mov_valor' => $venda->venda_valor_pago,
            ]);

            $this->movimentacaoCaixa->recalcularSaldoFinal($sessaoCaixa);

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

    private function validarPagamento(Venda $venda, bool $permitirSaldoAberto = false): void
    {
        $total = round((float) $venda->venda_valor_total, 2);
        $pago = round((float) $venda->venda_valor_pago, 2);
        $troco = round((float) $venda->venda_valor_troco, 2);

        if ($total <= 0) {
            return;
        }

        if (! $permitirSaldoAberto && $pago + self::TOLERANCIA_CENTAVOS < $total) {
            throw new VendaNaoFinalizavelException('Valor pago insuficiente para finalizar a venda.');
        }

        if (($pago - $total) > self::TOLERANCIA_CENTAVOS && $troco <= self::TOLERANCIA_CENTAVOS) {
            throw new VendaNaoFinalizavelException('O valor recebido excede o total da venda, mas nenhum troco foi informado. Ajuste o valor recebido para o total ou informe o valor pago pelo cliente para gerar o troco.');
        }
    }

    /**
     * Só chamada quando há saldo restante e o caixa optou por vender fiado.
     * Exige cliente vinculado (o título a receber precisa de um responsável)
     * e limite de crédito configurado e suficiente para o saldo desta venda.
     */
    private function validarLimiteCredito(Venda $venda, float $saldoRestante): void
    {
        if ($venda->venda_cliente_id === null) {
            throw new VendaNaoFinalizavelException('Venda fiado exige um cliente vinculado à venda.');
        }

        $cliente = Cliente::find($venda->venda_cliente_id);
        $disponivel = $cliente?->limiteCreditoDisponivel();

        if ($disponivel === null) {
            throw new VendaNaoFinalizavelException('Cliente sem limite de crédito configurado — não é possível vender fiado para ele.');
        }

        if ($saldoRestante > $disponivel + self::TOLERANCIA_CENTAVOS) {
            throw new VendaNaoFinalizavelException(sprintf(
                'Saldo em aberto (R$ %s) excede o limite de crédito disponível do cliente (R$ %s).',
                number_format($saldoRestante, 2, ',', '.'),
                number_format($disponivel, 2, ',', '.'),
            ));
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
