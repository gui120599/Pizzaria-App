<?php

namespace App\Services\Garcom;

use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\User;
use App\Services\ClienteResolverService;
use App\Services\PedidoStatusService;
use App\Support\JanelaOperacional;
use App\Support\TotaisPedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Retirada atendida pelo garçom fora de sessão de mesa (pedido para levar).
 *
 * Mesmo desenho da rodada de mesa (ver AtendimentoMesaService): o garçom
 * monta um rascunho INICIADO só dele, com os itens gravados pelo
 * PedidoProdutoSelector, e o envio o confirma para ABERTO — reenviar depois de
 * uma queda de rede vira no-op. Sem taxa de serviço.
 */
class RetiradaService
{
    public function __construct(
        private PedidoStatusService $status,
        private ClienteResolverService $clientes,
    ) {}

    /** Rascunho da próxima retirada deste garçom (cria se não houver). */
    public function rascunho(User $garcom): Pedido
    {
        return Pedido::query()
            ->whereNull('pedido_sessao_mesa_id')
            ->where('pedido_origem', PedidoOrigemEnum::GARCOM->value)
            ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
            ->where('pedido_usuario_garcom_id', $garcom->id)
            ->latest('id')
            ->first()
            ?? Pedido::create([
                'pedido_status' => StatusPedidoEnum::INICIADO->value,
                'pedido_origem' => PedidoOrigemEnum::GARCOM,
                'pedido_usuario_garcom_id' => $garcom->id,
            ]);
    }

    /**
     * Envia a retirada para a cozinha (INICIADO → ABERTO), vinculando o cliente.
     *
     * @return bool false quando a retirada já tinha sido enviada (reenvio)
     *
     * @throws RuntimeException quando faltam itens, nome/celular ou opção de retirada
     */
    public function enviar(Pedido $rascunho, string $nome, string $celular): bool
    {
        $nome = trim($nome);
        $digitosCelular = preg_replace('/\D/', '', $celular);

        if ($nome === '') {
            throw new RuntimeException('Informe o nome do cliente.');
        }

        if (strlen($digitosCelular) < 10) {
            throw new RuntimeException('Informe o celular do cliente com DDD.');
        }

        return DB::transaction(function () use ($rascunho, $nome, $digitosCelular) {
            $pedido = Pedido::whereKey($rascunho->getKey())->lockForUpdate()->firstOrFail();

            if ($pedido->pedido_status !== StatusPedidoEnum::INICIADO->value) {
                return false;
            }

            $itens = $pedido->item_pedido_pedido_id()->where('item_pedido_status', 'INSERIDO')->get();

            if ($itens->isEmpty()) {
                throw new RuntimeException('Adicione pelo menos um item antes de enviar.');
            }

            $opcao = $this->opcaoEntregaRetirada();
            $cliente = $this->clientes->resolverOuCriar(['nome' => $nome, 'celular' => $digitosCelular]);
            $totais = TotaisPedido::paraItens($itens, $opcao);

            $pedido->fill([
                'pedido_cliente_id' => $cliente->id,
                'pedido_opcaoentrega_id' => $opcao->id,
                'pedido_valor_itens' => $totais['itens'],
                'pedido_valor_desconto' => $totais['desconto'],
                'pedido_valor_frete' => $totais['frete'],
                'pedido_valor_total' => $totais['total'],
                'pedido_datahora_abertura' => Carbon::now(),
            ])->save();

            $this->status->confirmar($pedido);

            return true;
        });
    }

    /**
     * Envia uma ENTREGA para a cozinha: mesma validação do AtenderPedido —
     * cliente com nome e celular, endereço (rua e bairro) para opção que exige
     * endereço e formas de pagamento combinadas somando o total com frete. O
     * entregador cobra no Painel do Entregador.
     *
     * @param  array{clienteId?: ?int, nome?: string, celular?: string, enderecoRua?: string, enderecoNumero?: string, enderecoBairro?: string, enderecoCidade?: string, enderecoUf?: string, enderecoCep?: string}  $cliente
     * @param  array{opcaoEntregaId?: ?int, pagamentos?: array<int, array{opcaoPagamentoId?: ?int, valor?: ?string, trocoPara?: ?string}>}  $entregaPagamento
     * @return bool false quando a entrega já tinha sido enviada (reenvio)
     *
     * @throws RuntimeException
     */
    public function enviarEntrega(Pedido $rascunho, array $cliente, array $entregaPagamento): bool
    {
        $nome = trim((string) ($cliente['nome'] ?? ''));
        $digitosCelular = preg_replace('/\D/', '', (string) ($cliente['celular'] ?? ''));

        if ($nome === '') {
            throw new RuntimeException('Informe o nome do cliente.');
        }

        if (blank($cliente['clienteId'] ?? null) && strlen($digitosCelular) < 10) {
            throw new RuntimeException('Informe o celular do cliente com DDD.');
        }

        $opcao = ($entregaPagamento['opcaoEntregaId'] ?? null) ? OpcoesEntregas::find($entregaPagamento['opcaoEntregaId']) : null;

        if (! $opcao?->opcaoentrega_requer_endereco) {
            throw new RuntimeException('Escolha uma opção de entrega (com endereço).');
        }

        if (blank($cliente['enderecoRua'] ?? null) || blank($cliente['enderecoBairro'] ?? null)) {
            throw new RuntimeException('Informe rua e bairro da entrega.');
        }

        $pagamentos = array_values($entregaPagamento['pagamentos'] ?? []);

        if ($pagamentos === [] || collect($pagamentos)->contains(fn (array $p): bool => blank($p['opcaoPagamentoId'] ?? null) || blank($p['valor'] ?? null))) {
            throw new RuntimeException('Escolha a forma de pagamento e o valor de cada uma.');
        }

        return DB::transaction(function () use ($rascunho, $cliente, $nome, $digitosCelular, $opcao, $pagamentos) {
            $pedido = Pedido::whereKey($rascunho->getKey())->lockForUpdate()->firstOrFail();

            if ($pedido->pedido_status !== StatusPedidoEnum::INICIADO->value) {
                return false;
            }

            $itens = $pedido->item_pedido_pedido_id()->where('item_pedido_status', 'INSERIDO')->get();

            if ($itens->isEmpty()) {
                throw new RuntimeException('Adicione pelo menos um item antes de enviar.');
            }

            $totais = TotaisPedido::paraItens($itens, $opcao);
            $somaPagamentos = collect($pagamentos)->sum(fn (array $p): float => self::valorDigitado($p['valor']));

            if (abs($somaPagamentos - $totais['total']) > 0.01) {
                throw new RuntimeException(sprintf(
                    'A soma das formas de pagamento (R$ %s) precisa bater com o total com frete (R$ %s).',
                    number_format($somaPagamentos, 2, ',', '.'),
                    number_format($totais['total'], 2, ',', '.'),
                ));
            }

            $clienteModel = $this->clientes->resolverOuCriar([
                'nome' => $nome,
                'celular' => $digitosCelular !== '' ? $digitosCelular : null,
                'endereco' => $cliente['enderecoRua'] ?? null,
                'numero_endereco' => $cliente['enderecoNumero'] ?? null,
                'bairro' => $cliente['enderecoBairro'] ?? null,
                'cidade' => $cliente['enderecoCidade'] ?? null,
                'uf_estado' => $cliente['enderecoUf'] ?? null,
                'cep' => $cliente['enderecoCep'] ?? null,
            ]);

            $pedido->fill([
                'pedido_cliente_id' => $clienteModel->id,
                'pedido_opcaoentrega_id' => $opcao->id,
                'pedido_endereco_entrega' => collect([
                    $cliente['enderecoRua'] ?? null,
                    $cliente['enderecoNumero'] ?? null,
                    $cliente['enderecoBairro'] ?? null,
                    $cliente['enderecoCidade'] ?? null,
                ])->filter(fn ($v) => filled($v))->implode(', '),
                'pedido_valor_itens' => $totais['itens'],
                'pedido_valor_desconto' => $totais['desconto'],
                'pedido_valor_frete' => $totais['frete'],
                'pedido_valor_total' => $totais['total'],
                'pedido_datahora_abertura' => Carbon::now(),
            ])->save();

            $pedido->pagamentosCombinados()->delete();

            foreach ($pagamentos as $ordem => $linha) {
                $forma = OpcoesPagamento::find($linha['opcaoPagamentoId']);

                PagamentosPedido::create([
                    'pg_pedido_pedido_id' => $pedido->id,
                    'pg_pedido_opcaopagamento_id' => $forma?->id,
                    'pg_pedido_opcaopagamento_nome' => $forma?->opcaopag_nome,
                    'pg_pedido_valor' => self::valorDigitado($linha['valor']),
                    'pg_pedido_valor_troco_para' => filled($linha['trocoPara'] ?? null) ? self::valorDigitado($linha['trocoPara']) : null,
                    'pg_pedido_ordem' => $ordem,
                ]);
            }

            $this->status->confirmar($pedido);

            return true;
        });
    }

    /** Valor mascarado do EntregaPagamentoPicker ("1.234,50") em reais — mesma regra do AtenderPedido. */
    private static function valorDigitado(mixed $valor): float
    {
        return ((int) str_replace(['.', ','], '', (string) ($valor ?? '0'))) / 100;
    }

    /** Retirada pronta entregue ao cliente (PRONTO → ENTREGUE, ou FINALIZADO se já paga). */
    public function marcarEntregue(Pedido $pedido, User $garcom): Pedido
    {
        return $this->status->marcarEntregue($pedido, $garcom);
    }

    /**
     * Retiradas e entregas do turno lançadas pelo garçom ainda em andamento —
     * inclui EM TRANSPORTE e ENTREGUE sem venda (vai pagar no caixa).
     *
     * @return Collection<int, Pedido>
     */
    public function pedidosViagemDoTurno(): Collection
    {
        [$inicio] = JanelaOperacional::atual();

        return Pedido::query()
            ->whereNull('pedido_sessao_mesa_id')
            ->where('pedido_origem', PedidoOrigemEnum::GARCOM->value)
            ->whereNotIn('pedido_status', [
                StatusPedidoEnum::INICIADO->value,
                StatusPedidoEnum::CANCELADO->value,
                StatusPedidoEnum::FINALIZADO->value,
            ])
            ->where('pedido_datahora_abertura', '>=', $inicio)
            ->with(['cliente:id,cliente_nome', 'garcom:id,name,name_first', 'opcaoEntrega:id,opcaoentrega_nome,opcaoentrega_requer_endereco'])
            ->orderBy('pedido_datahora_abertura')
            ->get();
    }

    /** @throws RuntimeException quando não há opção de entrega sem endereço */
    public function opcaoEntregaRetirada(): OpcoesEntregas
    {
        $configurada = config('pizzaria.salao.opcao_entrega_retirada_id');

        $opcao = $configurada
            ? OpcoesEntregas::find($configurada)
            : OpcoesEntregas::where('opcaoentrega_requer_endereco', false)->orderBy('id')->first();

        return $opcao ?? throw new RuntimeException('Cadastre uma opção de entrega de retirada (sem endereço).');
    }
}
