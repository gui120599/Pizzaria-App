<?php

namespace App\Filament\Exports;

use App\Enums\MotivoCancelamentoEnum;
use App\Models\Pedido;
use Carbon\CarbonInterface;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Exportação da lista de pedidos (a aba e os filtros que estiverem na tela).
 *
 * O arquivo leva nome e celular do cliente: fica no disco privado, e o
 * download exige o usuário que pediu. A fila é só da exportação (ver
 * config pizzaria.pedidos.exportacao). Valores em reais saem com vírgula e
 * ponto e vírgula separa as colunas, para abrir direto no Excel em português.
 *
 * Texto digitado por gente (nome, observação) passa por texto(): célula que
 * começa com =, +, - ou @ vira fórmula na planilha.
 */
class PedidoExporter extends Exporter
{
    protected static ?string $model = Pedido::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('Nº'),
            ExportColumn::make('abertura')
                ->label('Abertura')
                ->state(fn (Pedido $record): ?string => self::data($record->pedido_datahora_abertura ?? $record->created_at)),
            ExportColumn::make('pedido_status')
                ->label('Status')
                ->state(fn (Pedido $record): string => $record->status()?->label() ?? (string) $record->pedido_status),
            ExportColumn::make('tipo')
                ->label('Tipo')
                ->state(fn (Pedido $record): string => $record->tipoAtendimento()->label()),
            ExportColumn::make('pedido_origem')
                ->label('Origem')
                ->state(fn (Pedido $record): ?string => $record->pedido_origem?->label()),
            ExportColumn::make('cliente')
                ->label('Cliente')
                ->state(fn (Pedido $record): ?string => self::texto($record->pedido_cliente_id ? $record->cliente->cliente_nome : null)),
            ExportColumn::make('celular')
                ->label('Celular')
                ->state(fn (Pedido $record): ?string => self::texto($record->pedido_cliente_id ? $record->cliente->cliente_celular : null)),
            ExportColumn::make('atendente')
                ->label('Atendente')
                ->state(fn (Pedido $record): ?string => self::texto($record->pedido_usuario_garcom_id ? $record->garcom->name_first : null)),
            ExportColumn::make('entregador')
                ->label('Entregador')
                ->state(fn (Pedido $record): ?string => self::texto($record->pedido_usuario_entrega_id ? $record->entregador->name_first : null)),
            ExportColumn::make('opcao_entrega')
                ->label('Opção de entrega')
                ->state(fn (Pedido $record): ?string => self::texto($record->pedido_opcaoentrega_id ? $record->opcaoEntrega->opcaoentrega_nome : null)),
            ExportColumn::make('pedido_valor_itens')->label('Itens (bruto)')->formatStateUsing(fn ($state): string => self::reais($state)),
            ExportColumn::make('pedido_valor_desconto')->label('Desconto')->formatStateUsing(fn ($state): string => self::reais($state)),
            ExportColumn::make('pedido_valor_frete')->label('Frete')->formatStateUsing(fn ($state): string => self::reais($state)),
            ExportColumn::make('pedido_valor_total')->label('Valor do pedido')->formatStateUsing(fn ($state): string => self::reais($state)),
            ExportColumn::make('pagamento')
                ->label('Pagamento')
                ->state(fn (Pedido $record): ?string => $record->situacaoPagamento()?->label()),
            ExportColumn::make('pedido_venda_id')->label('Nº da venda'),
            ExportColumn::make('motivo_cancelamento')
                ->label('Motivo do cancelamento')
                ->state(fn (Pedido $record): ?string => $record->pedido_motivo_cancelamento instanceof MotivoCancelamentoEnum ? $record->pedido_motivo_cancelamento->label() : null),
            ExportColumn::make('observacao_cancelamento')
                ->label('Observação do cancelamento')
                ->state(fn (Pedido $record): ?string => self::texto($record->pedido_motivo_cancelamento_observacao)),
            ExportColumn::make('cancelado_por')
                ->label('Cancelado por')
                ->state(fn (Pedido $record): ?string => self::texto($record->usuarioCancelou?->name_first)),
            ExportColumn::make('preparo')->label('Preparo')->state(fn (Pedido $record): ?string => self::data($record->pedido_datahora_preparo)),
            ExportColumn::make('pronto')->label('Pronto')->state(fn (Pedido $record): ?string => self::data($record->pedido_datahora_pronto)),
            ExportColumn::make('transporte')->label('Saída para entrega')->state(fn (Pedido $record): ?string => self::data($record->pedido_datahora_transporte)),
            ExportColumn::make('entrega')->label('Entrega')->state(fn (Pedido $record): ?string => self::data($record->pedido_datahora_entrega)),
            ExportColumn::make('finalizado')->label('Pagamento concluído em')->state(fn (Pedido $record): ?string => self::data($record->pedido_datahora_finalizado)),
            ExportColumn::make('cancelado_em')->label('Cancelado em')->state(fn (Pedido $record): ?string => self::data($record->pedido_datahora_cancelado)),
        ];
    }

    /** Relações e a marca de "tem item vendido" que as colunas usam, sem consulta por linha. */
    public static function modifyQuery(Builder $query): Builder
    {
        return $query
            ->with(['cliente', 'garcom:id,name_first', 'entregador:id,name_first', 'opcaoEntrega', 'usuarioCancelou:id,name_first'])
            ->withExists(['item_pedido_pedido_id as tem_item_vendido' => fn (Builder $item) => $item->whereNotNull('item_pedido_venda_id')]);
    }

    public static function getCompletedNotificationTitle(Export $export): string
    {
        return 'Exportação de pedidos concluída';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = Number::format($export->successful_rows).' '.str('pedido')->plural($export->successful_rows).' exportado(s).';

        if ($falhas = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($falhas).' linha(s) com falha.';
        }

        return $body;
    }

    public static function getCsvDelimiter(): string
    {
        return ';';
    }

    /** Arquivo com dados pessoais: nunca no disco público. */
    public function getFileDisk(): string
    {
        return 'local';
    }

    public function getJobConnection(): ?string
    {
        return config('pizzaria.pedidos.exportacao.conexao');
    }

    public function getJobQueue(): ?string
    {
        return config('pizzaria.pedidos.exportacao.fila');
    }

    public function getFileName(Export $export): string
    {
        return "pedidos-{$export->getKey()}";
    }

    private static function data(?CarbonInterface $data): ?string
    {
        return $data?->format('d/m/Y H:i');
    }

    private static function reais(mixed $valor): string
    {
        return number_format((float) $valor, 2, ',', '');
    }

    /** Neutraliza texto que a planilha leria como fórmula (começa com =, +, -, @, tab ou retorno de carro). */
    private static function texto(?string $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return $valor;
        }

        return in_array($valor[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$valor : $valor;
    }
}
