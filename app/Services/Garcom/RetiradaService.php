<?php

namespace App\Services\Garcom;

use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\User;
use App\Services\ClienteResolverService;
use App\Services\PagamentosCombinadosService;
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
     * Pagamento opcional: sem nenhuma forma escolhida o cliente paga no
     * caixa; com forma, a soma precisa bater com o total.
     *
     * @param  ?int  $opcaoEntregaId  opção sem endereço escolhida pelo garçom (null = padrão de retirada)
     * @param  array<int, array{opcaoPagamentoId?: ?int, valor?: ?string, trocoPara?: ?string, pago?: bool}>  $pagamentos
     * @return bool false quando a retirada já tinha sido enviada (reenvio)
     *
     * @throws RuntimeException quando faltam itens, nome/celular ou opção de retirada, ou o pagamento não bate
     */
    public function enviar(Pedido $rascunho, string $nome, string $celular, ?int $opcaoEntregaId = null, array $pagamentos = []): bool
    {
        $nome = trim($nome);
        $digitosCelular = preg_replace('/\D/', '', $celular);

        if ($nome === '') {
            throw new RuntimeException('Informe o nome do cliente.');
        }

        if (strlen($digitosCelular) < 10) {
            throw new RuntimeException('Informe o celular do cliente com DDD.');
        }

        $pagamentos = array_values($pagamentos);
        $informouPagamento = collect($pagamentos)->contains(fn (array $p): bool => filled($p['opcaoPagamentoId'] ?? null));

        if ($informouPagamento && collect($pagamentos)->contains(fn (array $p): bool => blank($p['opcaoPagamentoId'] ?? null) || blank($p['valor'] ?? null))) {
            throw new RuntimeException('Escolha a forma e informe o valor de cada pagamento (ou deixe sem forma para pagar no caixa).');
        }

        return DB::transaction(function () use ($rascunho, $nome, $digitosCelular, $opcaoEntregaId, $pagamentos, $informouPagamento) {
            $pedido = Pedido::whereKey($rascunho->getKey())->lockForUpdate()->firstOrFail();

            if ($pedido->pedido_status !== StatusPedidoEnum::INICIADO->value) {
                return false;
            }

            $itens = $pedido->item_pedido_pedido_id()->where('item_pedido_status', 'INSERIDO')->get();

            if ($itens->isEmpty()) {
                throw new RuntimeException('Adicione pelo menos um item antes de enviar.');
            }

            $opcao = $this->opcaoEntregaRetirada($opcaoEntregaId);
            $cliente = $this->clientes->resolverOuCriar(['nome' => $nome, 'celular' => $digitosCelular]);
            $totais = TotaisPedido::paraItens($itens, $opcao);

            if ($informouPagamento) {
                $this->exigirSomaDoTotal($pagamentos, $totais['total']);
            }

            $pedido->fill([
                'pedido_cliente_id' => $cliente->id,
                'pedido_opcaoentrega_id' => $opcao->id,
                'pedido_valor_itens' => $totais['itens'],
                'pedido_valor_desconto' => $totais['desconto'],
                'pedido_valor_frete' => $totais['frete'],
                'pedido_valor_total' => $totais['total'],
                'pedido_datahora_abertura' => Carbon::now(),
            ])->save();

            if ($informouPagamento) {
                app(PagamentosCombinadosService::class)->sincronizar($pedido, $pagamentos);
            }

            $this->status->confirmar($pedido);

            return true;
        });
    }

    /**
     * @param  array<int, array{valor?: ?string}>  $pagamentos
     *
     * @throws RuntimeException
     */
    private function exigirSomaDoTotal(array $pagamentos, float $total): void
    {
        $somaPagamentos = collect($pagamentos)->sum(fn (array $p): float => PagamentosCombinadosService::valorDigitado($p['valor']));

        if (abs($somaPagamentos - $total) > 0.01) {
            throw new RuntimeException(sprintf(
                'A soma das formas de pagamento (R$ %s) precisa bater com o total do pedido (R$ %s).',
                number_format($somaPagamentos, 2, ',', '.'),
                number_format($total, 2, ',', '.'),
            ));
        }
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
            $somaPagamentos = collect($pagamentos)->sum(fn (array $p): float => PagamentosCombinadosService::valorDigitado($p['valor']));

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

            // Preserva "pagamento já efetuado" (ex.: PIX CNPJ conferido pelo garçom).
            app(PagamentosCombinadosService::class)->sincronizar($pedido, $pagamentos);

            $this->status->confirmar($pedido);

            return true;
        });
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

    /**
     * Opções sem endereço que o garçom pode escolher na retirada, com a
     * padrão (opcaoEntregaRetirada) primeiro.
     *
     * @return Collection<int, OpcoesEntregas>
     */
    public function opcoesRetirada(): Collection
    {
        $opcoes = OpcoesEntregas::where('opcaoentrega_requer_endereco', false)->orderBy('id')->get();
        $padraoId = $opcoes->isEmpty() ? null : $this->opcaoEntregaRetirada()->id;

        return $opcoes->sortBy(fn (OpcoesEntregas $o) => $o->id === $padraoId ? 0 : 1)->values();
    }

    /**
     * Opção gravada na retirada: a escolhida pelo garçom (precisa ser sem
     * endereço) ou a padrão — pizzaria.salao.opcao_entrega_retirada_id; sem
     * config, a primeira sem endereço com "retir" no nome; senão a primeira
     * que não seja de consumo no local.
     *
     * @throws RuntimeException quando não há opção de entrega sem endereço
     */
    public function opcaoEntregaRetirada(?int $escolhidaId = null): OpcoesEntregas
    {
        if ($escolhidaId) {
            $escolhida = OpcoesEntregas::whereKey($escolhidaId)->where('opcaoentrega_requer_endereco', false)->first();

            return $escolhida ?? throw new RuntimeException('Escolha uma opção de retirada (sem endereço).');
        }

        $configurada = config('pizzaria.salao.opcao_entrega_retirada_id');

        if ($configurada && $opcao = OpcoesEntregas::find($configurada)) {
            return $opcao;
        }

        $semEndereco = OpcoesEntregas::where('opcaoentrega_requer_endereco', false)->orderBy('id')->get();
        $nome = fn (OpcoesEntregas $o): string => mb_strtolower((string) $o->opcaoentrega_nome);

        return $semEndereco->first(fn (OpcoesEntregas $o) => str_contains($nome($o), 'retir'))
            ?? $semEndereco->first(fn (OpcoesEntregas $o) => ! str_contains($nome($o), 'local'))
            ?? $semEndereco->first()
            ?? throw new RuntimeException('Cadastre uma opção de entrega de retirada (sem endereço).');
    }
}
