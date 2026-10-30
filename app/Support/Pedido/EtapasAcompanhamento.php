<?php

namespace App\Support\Pedido;

use App\Enums\StatusPedidoEnum;
use App\Enums\TipoAtendimentoEnum;
use App\Models\Pedido;
use Carbon\CarbonInterface;

/**
 * Linha do tempo do pedido para o cliente (acompanhamento) e para o garçom
 * (retirada). Só LÊ o fluxo existente: tipo de atendimento, estágio de
 * transporte da config e as colunas de data/hora de StatusPedidoEnum.
 *
 * Os status que o tipo não usa (ABERTO na mesa, EM TRANSPORTE na retirada)
 * não viram etapa; o pedido cai na última etapa que já passou.
 */
class EtapasAcompanhamento
{
    /** Ordem canônica do fluxo, para comparar status que o tipo não exibe. */
    private const ORDEM = [
        'INICIADO', 'ABERTO', 'PREPARANDO', 'PRONTO', 'EM TRANSPORTE', 'ENTREGUE', 'FINALIZADO',
    ];

    /** Visão do salão (garçom): a rodada da mesa mostra os passos da operação, não os do cliente. */
    private bool $visaoSalao = false;

    public function __construct(private readonly Pedido $pedido) {}

    /** Rodada da mesa como o garçom a vê: Enviada → Na cozinha → Pronta → Servida. */
    public static function paraSalao(Pedido $pedido): self
    {
        $etapas = new self($pedido);
        $etapas->visaoSalao = true;

        return $etapas;
    }

    public function tipo(): TipoAtendimentoEnum
    {
        return $this->pedido->tipoAtendimento();
    }

    public function cancelado(): bool
    {
        return $this->pedido->pedido_status === StatusPedidoEnum::CANCELADO->value;
    }

    /** Pedido já entregue/retirado/servido (ENTREGUE ou FINALIZADO). */
    public function concluido(): bool
    {
        return in_array($this->pedido->pedido_status, [StatusPedidoEnum::ENTREGUE->value, StatusPedidoEnum::FINALIZADO->value], true);
    }

    /**
     * @return array<int, array{status: string, rotulo: string, icone: string, estado: string, hora: ?CarbonInterface}>
     *                                                                                                                  `estado`: concluida | atual | pendente
     */
    public function etapas(): array
    {
        $posicaoAtual = $this->posicaoAtual();
        $etapas = [];

        foreach ($this->definicoes() as $status => [$rotulo, $icone]) {
            $posicao = array_search($status, self::ORDEM, true);

            $estado = match (true) {
                $this->concluido() => 'concluida',
                $posicao < $posicaoAtual => 'concluida',
                $status === $this->statusAtualExibido() => 'atual',
                default => 'pendente',
            };

            $etapas[] = [
                'status' => $status,
                'rotulo' => $rotulo,
                'icone' => $icone,
                'estado' => $estado,
                'hora' => $estado === 'pendente' ? null : $this->horaDa($status),
            ];
        }

        return $etapas;
    }

    /** Etapa em andamento (ou a última, se o pedido já foi concluído). */
    public function etapaAtual(): ?array
    {
        $etapas = $this->etapas();

        foreach ($etapas as $etapa) {
            if ($etapa['estado'] === 'atual') {
                return $etapa;
            }
        }

        return $this->concluido() ? end($etapas) ?: null : null;
    }

    /**
     * Destaque do estado atual: título curto, frase e ícone. Sem previsão de
     * tempo, porque o sistema não tem esse dado.
     *
     * @return array{titulo: string, descricao: string, icone: string}
     */
    public function destaque(): array
    {
        $etapa = $this->etapaAtual();
        $status = $etapa['status'] ?? 'INICIADO';
        $hora = $etapa['hora']?->format('H:i') ?? null;

        return $this->comoDestaque(match ($this->tipo()) {
            TipoAtendimentoEnum::RETIRADA => match ($status) {
                'INICIADO' => ['Pedido recebido', 'Aguardando a loja confirmar.', 'bx-receipt'],
                'ABERTO' => ['Pedido confirmado', 'A loja já confirmou. Em breve começa o preparo.', 'bx-check-double'],
                'PREPARANDO' => ['Em preparo', 'Sua comida está sendo preparada.', 'bxs-bowl-hot'],
                'PRONTO' => ['Pode vir buscar!', 'Seu pedido está pronto'.($hora ? " desde as {$hora}" : '').'.', 'bx-package'],
                default => ['Pedido retirado', 'Obrigado pela preferência! Bom apetite!', 'bxs-home-check'],
            },
            TipoAtendimentoEnum::MESA => match ($status) {
                'INICIADO' => ['Pedido recebido', 'Seu pedido foi enviado à cozinha.', 'bx-receipt'],
                'PREPARANDO' => ['Em preparo', 'Sua comida está sendo preparada.', 'bxs-bowl-hot'],
                'PRONTO' => ['Pronto', 'Seu pedido está pronto e já vai para a mesa.', 'bx-package'],
                default => ['Servido', 'Bom apetite!', 'bxs-home-check'],
            },
            TipoAtendimentoEnum::DELIVERY => match ($status) {
                'INICIADO' => ['Pedido recebido', 'Aguardando a loja confirmar.', 'bx-receipt'],
                'ABERTO' => ['Pedido confirmado', 'A loja já confirmou. Em breve começa o preparo.', 'bx-check-double'],
                'PREPARANDO' => ['Em preparo', 'Sua comida está sendo preparada.', 'bxs-bowl-hot'],
                'PRONTO' => ['Pedido pronto', 'Está pronto e aguardando sair para a entrega.', 'bx-package'],
                'EM TRANSPORTE' => ['A caminho', 'Saiu para entrega'.($hora ? " às {$hora}" : '').'.', 'bx-cycling'],
                default => ['Pedido entregue', 'Bom apetite!', 'bxs-home-check'],
            },
        });
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $dados
     * @return array{titulo: string, descricao: string, icone: string}
     */
    private function comoDestaque(array $dados): array
    {
        return ['titulo' => $dados[0], 'descricao' => $dados[1], 'icone' => $dados[2]];
    }

    /** @return array<string, array{0: string, 1: string}> status => [rótulo, ícone] */
    private function definicoes(): array
    {
        return match ($this->tipo()) {
            TipoAtendimentoEnum::RETIRADA => [
                'INICIADO' => ['Pedido recebido', 'bx-receipt'],
                'ABERTO' => ['Confirmado', 'bx-check-double'],
                'PREPARANDO' => ['Em preparo', 'bxs-bowl-hot'],
                'PRONTO' => ['Pronto para retirar', 'bx-package'],
                'ENTREGUE' => ['Retirado', 'bxs-home-check'],
            ],
            TipoAtendimentoEnum::MESA => $this->visaoSalao
                ? [
                    'ABERTO' => ['Enviada', 'bx-send'],
                    'PREPARANDO' => ['Na cozinha', 'bxs-bowl-hot'],
                    'PRONTO' => ['Pronta', 'bx-package'],
                    'ENTREGUE' => ['Servida', 'bxs-home-check'],
                ]
                : [
                    'INICIADO' => ['Recebido', 'bx-receipt'],
                    'PREPARANDO' => ['Em preparo', 'bxs-bowl-hot'],
                    'ENTREGUE' => ['Servido', 'bxs-home-check'],
                ],
            TipoAtendimentoEnum::DELIVERY => array_filter([
                'INICIADO' => ['Pedido recebido', 'bx-receipt'],
                'ABERTO' => ['Confirmado', 'bx-check-double'],
                'PREPARANDO' => ['Em preparo', 'bxs-bowl-hot'],
                'PRONTO' => ['Pronto', 'bx-package'],
                'EM TRANSPORTE' => $this->mostraTransporte() ? ['Saiu para entrega', 'bx-cycling'] : null,
                'ENTREGUE' => ['Entregue', 'bxs-home-check'],
            ]),
        };
    }

    /** O estágio some quando desligado na config, mas aparece se o pedido já passou por ele. */
    private function mostraTransporte(): bool
    {
        return config('pizzaria.pedidos.usa_estagio_transporte', true)
            || $this->pedido->pedido_status === StatusPedidoEnum::EM_TRANSPORTE->value
            || $this->pedido->pedido_datahora_transporte !== null;
    }

    private function posicaoAtual(): int
    {
        $posicao = array_search($this->pedido->pedido_status, self::ORDEM, true);

        return $posicao === false ? 0 : $posicao;
    }

    /** Etapa exibida que corresponde ao status atual (a última que não passa dele). */
    private function statusAtualExibido(): string
    {
        $posicaoAtual = $this->posicaoAtual();
        $escolhido = 'INICIADO';

        foreach (array_keys($this->definicoes()) as $status) {
            if (array_search($status, self::ORDEM, true) <= $posicaoAtual) {
                $escolhido = $status;
            }
        }

        return $escolhido;
    }

    private function horaDa(string $status): ?CarbonInterface
    {
        if ($status === 'INICIADO') {
            return $this->pedido->created_at;
        }

        $campo = StatusPedidoEnum::from($status)->campoDataHora();
        $hora = $campo ? $this->pedido->{$campo} : null;

        if ($hora === null && $status === 'ENTREGUE') {
            return $this->pedido->pedido_datahora_finalizado;
        }

        return $hora;
    }
}
