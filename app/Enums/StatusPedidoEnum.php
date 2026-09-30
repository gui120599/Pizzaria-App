<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Vocabulário único do ciclo de vida do pedido.
 *
 * Os valores são os literais gravados em `pedidos.pedido_status` (um enum do
 * MySQL, ver 2024_04_12_094736_create_pedidos_table). Repare em EM_TRANSPORTE:
 * o valor tem ESPAÇO, não underscore — errar isso não gera erro, só faz a
 * coluna do painel vir vazia.
 *
 * Este enum NÃO é cast em App\Models\Pedido de propósito: há dezenas de
 * comparações `=== 'ABERTO'` espalhadas pelo projeto (PedidoObserver,
 * EntregaService, AtenderPedido, FinalizacaoVendaService, VendaObserver) que
 * quebrariam silenciosamente se a leitura passasse a devolver objeto. Ele
 * centraliza o vocabulário; migrar para cast é outra entrega.
 *
 * StatusPedidoParidadeTest garante que os casos aqui não divirjam do enum do
 * MySQL nem de PedidoObserver::STATUSES_COM_BAIXA.
 */
enum StatusPedidoEnum: string
{
    case INICIADO = 'INICIADO';
    case ABERTO = 'ABERTO';
    case PREPARANDO = 'PREPARANDO';
    case PRONTO = 'PRONTO';
    case EM_TRANSPORTE = 'EM TRANSPORTE';
    case ENTREGUE = 'ENTREGUE';
    case FINALIZADO = 'FINALIZADO';
    case CANCELADO = 'CANCELADO';

    public function label(): string
    {
        return match ($this) {
            self::INICIADO => 'Iniciado',
            self::ABERTO => 'Aberto',
            self::PREPARANDO => 'Preparando',
            self::PRONTO => 'Pronto',
            self::EM_TRANSPORTE => 'Em transporte',
            self::ENTREGUE => 'Entregue',
            self::FINALIZADO => 'Finalizado',
            self::CANCELADO => 'Cancelado',
        };
    }

    /** Rótulo da coluna no painel — mais explícito que o label curto do badge. */
    public function labelColuna(): string
    {
        return match ($this) {
            self::INICIADO => 'Aguardando confirmação',
            self::PRONTO => 'Pronto — aguardando saída',
            default => $this->label(),
        };
    }

    /** Paleta do Filament. Mantém o mapa que estava em PedidosTable::CORES_STATUS. */
    public function cor(): string
    {
        return match ($this) {
            self::INICIADO => 'gray',
            self::ABERTO => 'info',
            self::PREPARANDO, self::PRONTO => 'warning',
            self::EM_TRANSPORTE => 'info',
            self::ENTREGUE, self::FINALIZADO => 'success',
            self::CANCELADO => 'danger',
        };
    }

    public function icone(): Heroicon
    {
        return match ($this) {
            self::INICIADO => Heroicon::OutlinedClock,
            self::ABERTO => Heroicon::OutlinedClipboardDocumentCheck,
            self::PREPARANDO => Heroicon::OutlinedFire,
            self::PRONTO => Heroicon::OutlinedShoppingBag,
            self::EM_TRANSPORTE => Heroicon::OutlinedTruck,
            self::ENTREGUE => Heroicon::OutlinedHomeModern,
            self::FINALIZADO => Heroicon::OutlinedCheckBadge,
            self::CANCELADO => Heroicon::OutlinedXCircle,
        };
    }

    /**
     * Coluna de data/hora que registra a ENTRADA neste status.
     *
     * Cuidado: ENTREGUE grava em `pedido_datahora_entrega` (não `_entregue`) —
     * é o nome real no schema e o que a query da coluna Entregue filtra.
     */
    public function campoDataHora(): ?string
    {
        return match ($this) {
            self::INICIADO => null,
            self::ABERTO => 'pedido_datahora_abertura',
            self::PREPARANDO => 'pedido_datahora_preparo',
            self::PRONTO => 'pedido_datahora_pronto',
            self::EM_TRANSPORTE => 'pedido_datahora_transporte',
            self::ENTREGUE => 'pedido_datahora_entrega',
            self::FINALIZADO => 'pedido_datahora_finalizado',
            self::CANCELADO => 'pedido_datahora_cancelado',
        };
    }

    /**
     * Status em que a baixa de estoque já aconteceu.
     *
     * Espelha PedidoObserver::STATUSES_COM_BAIXA — a baixa ocorre na entrada em
     * PREPARANDO e só é estornada no cancelamento.
     */
    public function consomeEstoque(): bool
    {
        return match ($this) {
            self::PREPARANDO, self::PRONTO, self::EM_TRANSPORTE, self::ENTREGUE, self::FINALIZADO => true,
            default => false,
        };
    }

    public function ehTerminal(): bool
    {
        return match ($this) {
            self::ENTREGUE, self::FINALIZADO, self::CANCELADO => true,
            default => false,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Máquina de estados
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Próximo status do fluxo linear.
     *
     * O fluxo BIFURCA em PRONTO: pedido que exige endereço segue para
     * EM TRANSPORTE; pedido de mesa/balcão vai direto para ENTREGUE, porque
     * "em transporte" não existe na operação desses casos. A config
     * `pizzaria.pedidos.usa_estagio_transporte` desliga o estágio para todos.
     */
    public function proximo(bool $exigeEntrega): ?self
    {
        return match ($this) {
            self::INICIADO => self::ABERTO,
            self::ABERTO => self::PREPARANDO,
            self::PREPARANDO => self::PRONTO,
            self::PRONTO => $exigeEntrega && self::estagioTransporteAtivo()
                ? self::EM_TRANSPORTE
                : self::ENTREGUE,
            self::EM_TRANSPORTE => self::ENTREGUE,
            self::ENTREGUE, self::FINALIZADO, self::CANCELADO => null,
        };
    }

    /** Recusa pulos de etapa e retrocessos — só o degrau imediato passa. */
    public function podeAvancarPara(self $destino, bool $exigeEntrega): bool
    {
        return $this->proximo($exigeEntrega) === $destino;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Predicados nomeados (evitam ifs de status espalhados pelas views)
    // ─────────────────────────────────────────────────────────────────────────

    public function podeSerCancelado(): bool
    {
        return ! $this->ehTerminal();
    }

    public function podeAvancarGenericamente(): bool
    {
        return match ($this) {
            self::INICIADO, self::ABERTO, self::PREPARANDO, self::PRONTO, self::EM_TRANSPORTE => true,
            default => false,
        };
    }

    /** Conteúdo (itens, entrega, pagamento) só congela de vez no cancelamento. */
    public function ehEditavelQuantoAoConteudo(): bool
    {
        return $this !== self::CANCELADO;
    }

    /** De PRONTO em diante, mexer no conteúdo é excepcional e pede permissão extra. */
    public function exigePermissaoAvancadaParaEditar(): bool
    {
        return match ($this) {
            self::PRONTO, self::EM_TRANSPORTE, self::ENTREGUE, self::FINALIZADO => true,
            default => false,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Colunas do painel
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Colunas do Kanban, na ordem do fluxo. A coluna EM TRANSPORTE desaparece
     * quando o estágio está desligado na config.
     *
     * @return array<int, self>
     */
    public static function colunasKanban(): array
    {
        $colunas = [self::INICIADO, self::ABERTO, self::PREPARANDO, self::PRONTO];

        if (self::estagioTransporteAtivo()) {
            $colunas[] = self::EM_TRANSPORTE;
        }

        $colunas[] = self::ENTREGUE;

        return $colunas;
    }

    /**
     * Status das colunas de fluxo ativo — tudo menos ENTREGUE, que tem query
     * própria (janela por data escolhida + limite).
     *
     * @return array<int, string>
     */
    public static function valoresEmAndamento(): array
    {
        return collect(self::colunasKanban())
            ->reject(fn (self $s) => $s === self::ENTREGUE)
            ->map(fn (self $s) => $s->value)
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public static function valoresKanban(): array
    {
        return array_map(fn (self $s) => $s->value, self::colunasKanban());
    }

    /** @return array<string, string> */
    public static function paraSelect(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }

    private static function estagioTransporteAtivo(): bool
    {
        return (bool) config('pizzaria.pedidos.usa_estagio_transporte', true);
    }
}
