<?php

namespace App\Services\MesaCliente;

use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Exceptions\AcessoMesaNegadoException;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\MesaChamado;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use App\Support\ContaMesa;

/**
 * O que a página da mesa mostra a cada consulta: em que situação o celular
 * está (pedindo, identificar, mesa fechada...), as rodadas DESTE celular, os
 * chamados abertos e a conta da mesa com a parte dele. Tudo parte do
 * participante resolvido pelo token — nenhum id vem do navegador.
 */
class EstadoMesaCliente
{
    public const PEDINDO = 'pedindo';

    public const IDENTIFICAR = 'identificar';

    public const FECHADA = 'fechada';

    public const ENCERRADA = 'encerrada';

    public const BLOQUEADO = 'bloqueado';

    public const DESLIGADO = 'desligado';

    public const TROCADA = 'trocada';

    public function __construct(private ParticipanteMesaService $participantes) {}

    /**
     * Situação do celular nesta mesa, com ou sem token válido.
     *
     * @return array{estado: string, participante: ?MesaParticipante, mensagem: ?string, codigo_mesa_atual: ?string}
     */
    public function situacao(Mesa $mesa, ?string $token): array
    {
        $situacao = fn (string $estado, ?MesaParticipante $participante = null, ?string $mensagem = null, ?string $codigo = null) => [
            'estado' => $estado,
            'participante' => $participante,
            'mensagem' => $mensagem,
            'codigo_mesa_atual' => $codigo,
        ];

        if (! $this->participantes->pedidoPeloCelularLigado($mesa)) {
            return $situacao(self::DESLIGADO, mensagem: 'O pedido pelo celular não está disponível nesta mesa. Chame o garçom.');
        }

        try {
            return $situacao(self::PEDINDO, $this->participantes->resolver($mesa, $token));
        } catch (AcessoMesaNegadoException $e) {
            return match ($e->motivo) {
                AcessoMesaNegadoException::MESA_TROCADA => $situacao(self::TROCADA, mensagem: $e->getMessage(), codigo: $e->codigoMesaAtual),
                AcessoMesaNegadoException::BLOQUEADO => $situacao(self::BLOQUEADO, mensagem: $e->getMessage()),
                // Conta antiga encerrada: com outra conta aberta na mesa, é só se identificar de novo.
                AcessoMesaNegadoException::CONTA_ENCERRADA => $this->participantes->sessaoAberta($mesa)
                    ? $situacao(self::IDENTIFICAR)
                    : $situacao(self::ENCERRADA, mensagem: $e->getMessage()),
                default => $this->participantes->sessaoAberta($mesa)
                    ? $situacao(self::IDENTIFICAR)
                    : $situacao(self::FECHADA, mensagem: 'Esta mesa ainda não está aberta.'),
            };
        }
    }

    /**
     * JSON da página: situação e, para quem está pedindo, rodadas, chamados e conta.
     *
     * @param  array{estado: string, participante: ?MesaParticipante, mensagem: ?string, codigo_mesa_atual: ?string}  $situacao
     * @return array<string, mixed>
     */
    public function paraTela(Mesa $mesa, array $situacao): array
    {
        $participante = $situacao['participante'];

        return [
            'estado' => $situacao['estado'],
            'mensagem' => $situacao['mensagem'],
            'redirecionar' => $situacao['codigo_mesa_atual'] ? route('mesa-cliente.show', $situacao['codigo_mesa_atual']) : null,
            'mesa' => ['nome' => $mesa->mesa_nome],
            'abertura_solicitada' => $situacao['estado'] === self::FECHADA && MesaChamado::pendentes()
                ->where('mc_mesa_id', $mesa->id)
                ->where('mc_tipo', TipoChamadoMesaEnum::ABRIR_MESA->value)
                ->exists(),
            'participante' => $participante ? ['nome' => $participante->mp_nome] : null,
            'rodadas' => $participante ? $this->rodadas($participante) : [],
            'chamados' => $participante ? $this->chamadosPendentes($participante) : [],
            'conta' => $participante ? $this->conta($participante) : null,
            'polling_segundos' => (int) config('pizzaria.mesa_cliente.polling_segundos'),
        ];
    }

    /** @return list<array<string, mixed>> as rodadas deste celular, da mais nova para a mais antiga */
    private function rodadas(MesaParticipante $participante): array
    {
        return Pedido::query()
            ->where('pedido_mesa_participante_id', $participante->id)
            ->with(['item_pedido_pedido_id' => fn ($q) => $q
                ->where('item_pedido_status', 'INSERIDO')
                ->with(['produto', 'adicionaisItemPedido.adicional'])])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Pedido $pedido) => [
                'id' => $pedido->id,
                'hora' => $pedido->created_at?->format('H:i'),
                'situacao' => $this->situacaoDaRodada($pedido),
                'aguardando' => $pedido->aguardandoAprovacao(),
                'recusada' => $pedido->pedido_aprovacao_status === StatusAprovacaoPedidoEnum::RECUSADO
                    || $pedido->pedido_status === StatusPedidoEnum::CANCELADO->value,
                'motivo_recusa' => $pedido->pedido_recusa_motivo,
                'total' => (float) $pedido->pedido_valor_total,
                'itens' => $pedido->item_pedido_pedido_id
                    ->map(fn (ItensPedido $item) => [
                        'quantidade' => (float) $item->item_pedido_quantidade,
                        'nome' => $item->nomeProduto(),
                        'detalhes' => array_values(array_filter([
                            ...$item->linhasRespostas(),
                            $item->adicionaisItemPedido->isNotEmpty()
                                ? '+ '.$item->adicionaisItemPedido->map(fn ($a) => $a->adicional?->adicional_nome)->filter()->implode(', ')
                                : null,
                            $item->item_pedido_observacao ?: null,
                        ])),
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    private function situacaoDaRodada(Pedido $pedido): string
    {
        if ($pedido->aguardandoAprovacao()) {
            return 'Aguardando o garçom';
        }

        if ($pedido->pedido_aprovacao_status === StatusAprovacaoPedidoEnum::RECUSADO) {
            return 'Recusado';
        }

        return match ($pedido->pedido_status) {
            StatusPedidoEnum::ABERTO->value => 'Enviado para a cozinha',
            StatusPedidoEnum::PREPARANDO->value => 'Em preparo',
            StatusPedidoEnum::PRONTO->value => 'Pronto',
            StatusPedidoEnum::ENTREGUE->value, StatusPedidoEnum::FINALIZADO->value => 'Entregue',
            StatusPedidoEnum::CANCELADO->value => 'Cancelado',
            default => 'Enviado',
        };
    }

    /** @return list<string> tipos de chamado em aberto nesta conta */
    private function chamadosPendentes(MesaParticipante $participante): array
    {
        return MesaChamado::pendentes()
            ->where('mc_sessao_mesa_id', $participante->mp_sessao_mesa_id)
            ->pluck('mc_tipo')
            ->map(fn (TipoChamadoMesaEnum $tipo) => $tipo->value)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{subtotal: float, percentual: float, taxa: float, total: float, minha: array{subtotal: float, taxa: float, total: float}} */
    private function conta(MesaParticipante $participante): array
    {
        $sessao = $participante->sessaoMesa;
        $minha = ContaMesa::porPessoa($sessao)[(int) $participante->mp_cliente_id] ?? ['subtotal' => 0.0, 'taxa' => 0.0, 'total' => 0.0];

        return ContaMesa::para($sessao) + [
            'minha' => ['subtotal' => $minha['subtotal'], 'taxa' => $minha['taxa'], 'total' => $minha['total']],
        ];
    }
}
