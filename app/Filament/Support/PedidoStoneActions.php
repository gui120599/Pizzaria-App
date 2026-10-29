<?php

namespace App\Filament\Support;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Exceptions\StoneResultadoDesconhecidoException;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\StonePedido;
use App\Services\Stone\StoneRecebimentoService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Cobrança na maquininha Stone a partir do painel de pedidos (lista e página
 * de visualização): enviar (um ou em lote), cancelar, ver a cobrança e
 * reconsultar. Toda a regra mora em StoneRecebimentoService (elegibilidade,
 * valor devido, vagas do POS, lock); aqui só há formulário, visibilidade e
 * notificação.
 *
 * As ações leem $record->stonePedidos (carregado pela lista) para não consultar
 * linha a linha; $haMaquininhaConnect e $semVagas deixam a lista consultar as
 * maquininhas uma vez só por renderização.
 */
class PedidoStoneActions
{
    /** Cobrança "antiga" o bastante para valer a reconsulta manual (o webhook costuma chegar em segundos). */
    private const MINUTOS_PARA_RECONSULTAR = 10;

    /**
     * Estado do botão "Enviar": a matriz da seção 7.1 do plano — oculto nos
     * estados em que reenviar não faz sentido (cobrança Direto em andamento,
     * resultado desconhecido, nada a cobrar, pedido encerrado) e desabilitado,
     * com o motivo no tooltip, quando falta só integração ou vaga.
     *
     * @return array{elegivel: bool, motivo: ?string, oculto: bool}
     */
    public static function estadoDoEnvio(Pedido $pedido, ?Closure $haMaquininhaConnect = null, ?Closure $semVagas = null): array
    {
        $estado = app(StoneRecebimentoService::class)->elegibilidade(
            $pedido,
            haMaquininhaConnect: $haMaquininhaConnect ? (bool) $haMaquininhaConnect() : null,
        );

        if ($estado['elegivel'] && self::todasAsMaquininhasSemVaga($semVagas)) {
            $limite = app(StoneRecebimentoService::class)->limiteDePedidosAbertos();

            return ['elegivel' => false, 'motivo' => "Maquininha com {$limite} pedidos abertos", 'oculto' => false];
        }

        return $estado;
    }

    public static function todasAsMaquininhasSemVaga(?Closure $semVagas = null): bool
    {
        if ($semVagas) {
            return (bool) $semVagas();
        }

        $servico = app(StoneRecebimentoService::class);
        $maquininhas = Maquininha::stoneDisponivel()->get();

        return $maquininhas->isNotEmpty() && $maquininhas->every(fn (Maquininha $maquininha): bool => $servico->vagasAbertas($maquininha) <= 0);
    }

    public static function enviar(?Closure $haMaquininhaConnect = null, ?Closure $semVagas = null): Action
    {
        return Action::make('enviarStone')
            ->label('Enviar para a maquininha')
            ->icon('heroicon-o-credit-card')
            ->color('primary')
            ->visible(fn (Pedido $record): bool => ! self::estadoDoEnvio($record, $haMaquininhaConnect, $semVagas)['oculto'])
            ->disabled(fn (Pedido $record): bool => ! self::estadoDoEnvio($record, $haMaquininhaConnect, $semVagas)['elegivel'])
            ->tooltip(fn (Pedido $record): ?string => self::estadoDoEnvio($record, $haMaquininhaConnect, $semVagas)['motivo'])
            ->schema(fn (): array => self::campos())
            ->action(function (Pedido $record, array $data): void {
                [$opcao, $maquininha, $modo] = self::escolhas($data);

                try {
                    app(StoneRecebimentoService::class)->iniciarCobrancaDePedido($record, $opcao, $maquininha, modo: $modo);
                    Notification::make()->success()->title('Pedido enviado para a maquininha.')->send();
                } catch (StoneResultadoDesconhecidoException $e) {
                    Notification::make()->warning()->title('Resultado desconhecido')->body($e->getMessage())->persistent()->send();
                } catch (StoneConnectException $e) {
                    Notification::make()->danger()->title('Falha ao enviar')->body($e->getMessage())->send();
                }
            });
    }

    public static function enviarEmLote(): BulkAction
    {
        return BulkAction::make('enviarStoneBulk')
            ->label('Enviar para a maquininha')
            ->icon('heroicon-o-credit-card')
            ->accessSelectedRecords()
            ->schema(fn (BulkAction $action): array => self::campos($action))
            ->modalDescription('Se os pedidos elegíveis não couberem nas vagas da maquininha, nenhum é enviado.')
            ->action(function (Collection $records, array $data): void {
                [$opcao, $maquininha, $modo] = self::escolhas($data);

                try {
                    $resultado = app(StoneRecebimentoService::class)->iniciarCobrancasEmLote($records, $opcao, $maquininha, $modo);
                } catch (StoneConnectException $e) {
                    Notification::make()->danger()->title('Falha ao enviar')->body($e->getMessage())->send();

                    return;
                }

                if ($resultado['recusado'] !== null) {
                    Notification::make()->danger()->title('Nenhum pedido foi enviado')->body($resultado['recusado'])->persistent()->send();

                    return;
                }

                self::notificarResultadoDoLote($resultado);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @param  array{enviados: list<StonePedido>, indeterminados: list<StonePedido>, falhas: array<int, string>, ignorados: array<int, string>}  $resultado
     */
    private static function notificarResultadoDoLote(array $resultado): void
    {
        $enviados = count($resultado['enviados']);
        $linhas = [];

        foreach ($resultado['ignorados'] as $pedidoId => $motivo) {
            $linhas[] = "#{$pedidoId} ignorado: {$motivo}";
        }
        foreach ($resultado['falhas'] as $pedidoId => $motivo) {
            $linhas[] = "#{$pedidoId} recusado pela Stone: {$motivo}";
        }
        foreach ($resultado['indeterminados'] as $stonePedido) {
            $linhas[] = "#{$stonePedido->stp_pedido_id} com resultado desconhecido: use \"Reconsultar na Stone\" antes de enviar de novo";
        }

        $problemas = count($resultado['ignorados']) + count($resultado['falhas']) + count($resultado['indeterminados']);

        Notification::make()
            ->title($problemas > 0
                ? "{$enviados} pedido(s) enviado(s), {$problemas} com problema."
                : "{$enviados} pedido(s) enviado(s) para a maquininha.")
            ->body($linhas === [] ? null : implode("\n", $linhas))
            ->color($problemas > 0 ? 'warning' : 'success')
            ->icon($problemas > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
            ->persistent($problemas > 0)
            ->send();
    }

    public static function cancelar(): Action
    {
        return Action::make('cancelarStone')
            ->label('Cancelar cobrança na maquininha')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Pedido $record): bool => self::pendente($record) !== null)
            ->action(function (Pedido $record): void {
                $stonePedido = self::pendente($record);
                if (! $stonePedido) {
                    return;
                }

                try {
                    app(StoneRecebimentoService::class)->cancelarCobranca($stonePedido);
                    Notification::make()->success()->title('Cobrança cancelada na maquininha.')->send();
                } catch (StoneConnectException $e) {
                    Notification::make()->danger()->title('Não foi possível cancelar')->body($e->getMessage())->send();
                }
            });
    }

    public static function cancelarEmLote(): BulkAction
    {
        return BulkAction::make('cancelarStoneBulk')
            ->label('Cancelar cobrança na maquininha')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (Collection $records): void {
                $cancelados = 0;
                $recusados = [];

                foreach ($records as $pedido) {
                    $stonePedido = self::pendente($pedido);
                    if (! $stonePedido) {
                        continue;
                    }

                    try {
                        app(StoneRecebimentoService::class)->cancelarCobranca($stonePedido);
                        $cancelados++;
                    } catch (StoneConnectException $e) {
                        $recusados[] = "#{$pedido->id}: {$e->getMessage()}";
                    }
                }

                Notification::make()
                    ->title("{$cancelados} cobrança(s) cancelada(s) na maquininha.")
                    ->body($recusados === [] ? null : implode("\n", $recusados))
                    ->color($recusados === [] ? 'success' : 'warning')
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Detalhe só de leitura das cobranças do pedido: status, ids na Stone, valores e histórico de webhooks. */
    public static function verCobranca(): Action
    {
        return Action::make('verCobrancaStone')
            ->label('Ver cobrança')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (Pedido $record): bool => self::cobrancas($record)->isNotEmpty())
            ->slideOver()
            ->modalHeading(fn (Pedido $record): string => "Cobrança na maquininha — pedido #{$record->id}")
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (Pedido $record) => view('filament.resources.pedidos.cobrancas-stone', [
                'cobrancas' => self::cobrancas($record)->load(['maquininha', 'opcaoPagamento', 'webhooks']),
            ]));
    }

    /** Resultado desconhecido (ou cobrança aguardando há tempo): pergunta à Stone, ou repete o envio com a mesma chave. */
    public static function reconsultar(): Action
    {
        return Action::make('reconsultarStone')
            ->label('Reconsultar na Stone')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (Pedido $record): bool => self::paraReconsultar($record)->isNotEmpty())
            ->action(function (Pedido $record): void {
                $servico = app(StoneRecebimentoService::class);
                $resolvidos = 0;
                $pendencias = [];

                foreach (self::paraReconsultar($record) as $stonePedido) {
                    try {
                        $atual = $servico->reconsultar($stonePedido);

                        if ($atual->stp_status !== StonePedidoStatus::Indeterminado) {
                            $resolvidos++;
                        }
                    } catch (StoneConnectException $e) {
                        $pendencias[] = $e->getMessage();
                    }
                }

                Notification::make()
                    ->title($pendencias === [] ? "Cobrança reconsultada na Stone ({$resolvidos} atualizada(s))." : 'A Stone ainda não confirmou a cobrança.')
                    ->body($pendencias === [] ? null : implode("\n", array_unique($pendencias)))
                    ->color($pendencias === [] ? 'success' : 'warning')
                    ->send();
            });
    }

    /** Último status Stone do pedido, para a coluna da lista (relação já carregada: sem N+1). */
    public static function coluna(): TextColumn
    {
        return TextColumn::make('stone')
            ->label('Stone')
            ->badge()
            ->state(fn (Pedido $record): ?string => self::cobrancas($record)->first()?->stp_status->value)
            ->formatStateUsing(fn (?string $state): ?string => StonePedidoStatus::tryFrom((string) $state)?->getLabel())
            ->color(fn (?string $state): string => StonePedidoStatus::tryFrom((string) $state)?->getColor() ?? 'gray')
            ->placeholder('—')
            ->toggleable();
    }

    /**
     * @return array<int, Select>
     */
    private static function campos(?BulkAction $lote = null): array
    {
        return [
            Select::make('maquininha_id')
                ->label('Maquininha')
                ->options(fn () => Maquininha::stoneDisponivel()->orderBy('nome')->pluck('nome', 'id'))
                ->required()
                ->live()
                ->helperText(fn (Get $get): ?string => self::textoDasVagas($get('maquininha_id'), $lote)),

            Select::make('opcaopagamento_id')
                ->label('Forma de pagamento (opcional)')
                ->options(fn () => OpcoesPagamento::where('opcaopag_stone_integrada', true)->orderBy('opcaopag_nome')->pluck('opcaopag_nome', 'id'))
                ->helperText('Sem escolher, o tipo (crédito/débito/PIX) é selecionado na própria maquininha.'),
        ];
    }

    /** "Vagas livres: N de 30" da maquininha escolhida e, no lote, quantos pedidos elegíveis a seleção tem. */
    private static function textoDasVagas(mixed $maquininhaId, ?BulkAction $lote): ?string
    {
        $maquininha = filled($maquininhaId) ? Maquininha::find($maquininhaId) : null;
        if (! $maquininha) {
            return null;
        }

        $servico = app(StoneRecebimentoService::class);
        $texto = "Vagas livres na maquininha: {$servico->vagasAbertas($maquininha)} de {$servico->limiteDePedidosAbertos()}.";

        if ($lote) {
            $elegiveis = $lote->getSelectedRecords()->filter(
                fn (Pedido $pedido): bool => $servico->elegibilidade($pedido, haMaquininhaConnect: true, verificarVagas: false)['elegivel']
            )->count();

            $texto .= " Pedidos elegíveis na seleção: {$elegiveis}.";
        }

        return $texto;
    }

    /**
     * @param  array{maquininha_id: int, opcaopagamento_id?: ?int}  $data
     * @return array{0: ?OpcoesPagamento, 1: Maquininha, 2: StonePedidoModo}
     */
    private static function escolhas(array $data): array
    {
        $maquininha = Maquininha::findOrFail($data['maquininha_id']);
        $opcao = filled($data['opcaopagamento_id'] ?? null) ? OpcoesPagamento::find($data['opcaopagamento_id']) : null;

        return [$opcao, $maquininha, $opcao ? StonePedidoModo::Direto : StonePedidoModo::Listado];
    }

    /**
     * Cobranças do pedido, da mais recente para a mais antiga. A lista já traz a
     * relação carregada; fora dela, carrega sob demanda.
     *
     * @return Collection<int, StonePedido>
     */
    private static function cobrancas(Pedido $pedido): Collection
    {
        return $pedido->relationLoaded('stonePedidos') ? $pedido->stonePedidos : $pedido->stonePedidos()->get();
    }

    private static function pendente(Pedido $pedido): ?StonePedido
    {
        // Resultado desconhecido sem order_id não se cancela (o pedido pode existir na Stone): reconsulta-se antes.
        return self::cobrancas($pedido)->first(fn (StonePedido $stonePedido): bool => $stonePedido->stp_status->pendente()
            && ! ($stonePedido->stp_status === StonePedidoStatus::Indeterminado && blank($stonePedido->stp_order_id)));
    }

    /**
     * @return SupportCollection<int, StonePedido>
     */
    private static function paraReconsultar(Pedido $pedido): SupportCollection
    {
        return self::cobrancas($pedido)->filter(fn (StonePedido $stonePedido): bool => $stonePedido->stp_status === StonePedidoStatus::Indeterminado
            || ($stonePedido->stp_status === StonePedidoStatus::Aguardando
                && $stonePedido->created_at->lt(now()->subMinutes(self::MINUTOS_PARA_RECONSULTAR))));
    }
}
