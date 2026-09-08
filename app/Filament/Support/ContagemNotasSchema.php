<?php

namespace App\Filament\Support;

use App\Models\NotaMoeda;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Icetalker\FilamentTableRepeater\Forms\Components\TableRepeater;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Repeater de contagem de cédulas/moedas, compartilhado pela Abertura
 * (SessaoCaixaForm) e pelo Fechamento (FechamentoCaixaForm) de caixa — antes
 * duplicado idêntico nos dois forms. Catálogo fixo (uma linha por NotaMoeda),
 * o operador escolhe por linha se prefere digitar a Quantidade contada ou o
 * Valor Total que já sabe que tem daquela cédula/moeda.
 *
 * Layout em tabela (icetalker/filament-table-repeater) em vez do Repeater
 * padrão (cards empilhados) — mais compacto pra uma lista de 12+ linhas.
 *
 * Quantidade e Valor total ficam SEMPRE visíveis como colunas (não dá pra
 * esconder uma com ->visible() baseado em Closure aqui: o childComponents()
 * do TableRepeater chama isHidden() em cada campo pra montar o cabeçalho da
 * tabela ANTES do componente estar anexado ao container do Livewire, e um
 * ->visible(fn (Get $get) => ...) precisa desse container pra resolver o
 * Get — quebra com "Typed property ...Component::$container must not be
 * accessed before initialization". O Radio "modo" continua controlando qual
 * dos dois valores o servidor usa (ver resolverDadosNota()), só não esconde
 * mais o outro campo.
 *
 * O modelo (SessaoCaixaNota/FechamentoCaixaNota) já recalcula valor_total =
 * quantidade × notaMoeda.valor num hook saving() — por isso aqui só
 * precisamos resolver a "quantidade" implícita quando o operador digita o
 * valor total; o hook garante que o valor final gravado é sempre o múltiplo
 * exato da cédula, mesmo que o valor digitado não seja.
 */
final class ContagemNotasSchema
{
    public static function make(bool|Closure $disabled = false): TableRepeater
    {
        return TableRepeater::make('notas')
            ->relationship()
            ->label('')
            ->schema([
                Hidden::make('nota_moeda_id'),

                TextEntry::make('nota_moeda_label')
                    ->label('Cédula/Moeda')
                    ->state(fn (Get $get): string => NotaMoeda::find($get('nota_moeda_id'))?->descricao ?? '—'),

                Radio::make('modo')
                    ->label('Como informar')
                    ->options([
                        'quantidade' => 'Quantidade',
                        'valor_total' => 'Valor total',
                    ])
                    ->default('quantidade')
                    ->live()
                    ->inline(),

                TextInput::make('quantidade')
                    ->label('Quantidade')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->live(onBlur: true)
                    ->required()
                    // Sem isto, o campo "Valor total" ao lado (sempre visível, ver nota
                    // da classe) ficava travado em R$ 0,00 sempre que o operador contava
                    // pela Quantidade (o modo padrão) — só o Subtotal (somente leitura)
                    // refletia o valor real. A sincronização inversa (Valor total ->
                    // Quantidade) já existia no campo Money abaixo.
                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                        self::sincronizarValorTotal($set, $get, (int) ($state ?? 0));
                    })
                    // +/- via alpineClickHandler (JS puro, sem wire:click/mountAction) —
                    // de propósito: o Action::action() normal, dentro do TableRepeater,
                    // reusa o mesmo componente Action entre as linhas e o "schemaComponent"
                    // (qual linha o clique deveria afetar) ficava desatualizado a cada
                    // clique — o 2º clique numa cédula já acertava a linha ACIMA dela, e
                    // assim sucessivamente. $wire.set() aqui usa o wire:model.blur do
                    // próprio input (lido do DOM na hora do clique, sempre correto) e cai
                    // no mesmo fluxo normal de "digitar e sair do campo" — o
                    // afterStateUpdated acima já sincroniza o Valor total sozinho.
                    ->suffixAction(
                        Action::make('incrementarQuantidade')
                            ->icon('heroicon-m-plus')
                            ->alpineClickHandler(<<<'JS'
                                (() => {
                                    const input = $el.closest('.fi-input-wrp').querySelector('input[wire\\:model\\.blur]');
                                    const novo = Math.max(0, (parseInt(input.value || '0', 10) || 0) + 1);
                                    input.value = novo;
                                    $wire.set(input.getAttribute('wire:model.blur'), novo);
                                })()
                            JS),
                    )
                    ->prefixAction(
                        Action::make('decrementarQuantidade')
                            ->icon('heroicon-m-minus')
                            ->alpineClickHandler(<<<'JS'
                                (() => {
                                    const input = $el.closest('.fi-input-wrp').querySelector('input[wire\\:model\\.blur]');
                                    const novo = Math.max(0, (parseInt(input.value || '0', 10) || 0) - 1);
                                    input.value = novo;
                                    $wire.set(input.getAttribute('wire:model.blur'), novo);
                                })()
                            JS),
                    ),

                Money::make('valor_total')
                    ->label('Valor total')
                    ->minValue(0)
                    ->default(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                        // Preview ao vivo — resolverDadosNota() confirma/recalcula isso de
                        // novo no servidor ao salvar, como segunda camada de garantia
                        // (cobre o caso do operador nunca disparar o blur deste campo).
                        $valorNota = self::valorNota($get('nota_moeda_id'));

                        if ($valorNota <= 0) {
                            return;
                        }

                        $set('quantidade', (int) round(self::parseMoneyState($state) / $valorNota));
                    }),

                TextEntry::make('subtotal')
                    ->label('Subtotal')
                    ->state(fn (Get $get): string => 'R$ '.number_format(self::valorNota($get('nota_moeda_id')) * (int) ($get('quantidade') ?? 0), 2, ',', '.')),
            ])
            // Catálogo fixo: uma linha por NotaMoeda, já pré-carregada na criação
            // (o operador não escolhe/adiciona/remove linhas).
            ->default(fn (): array => NotaMoeda::ordenadas()->get()->map(fn (NotaMoeda $n): array => [
                'nota_moeda_id' => $n->id,
                'quantidade' => 0,
            ])->toArray())
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->disabled($disabled)
            // ->disabled() amarra isSaved() ao inverso da própria condição (proteção
            // padrão do Filament: campo desabilitado não deve ser salvo). Aqui a
            // condição vira true assim que o registro é criado — no meio do próprio
            // fluxo de criação (saveRelationships() roda depois de handleRecordCreation())
            // — e sem isto as linhas da contagem nunca eram persistidas.
            ->saveRelationshipsWhenDisabled()
            ->columnSpanFull()
            // Segunda camada de garantia (além do afterStateUpdated client-side) de que
            // quantidade reflete o valor total digitado no modo "Valor total" — roda no
            // servidor, pros dois caminhos (criar linha nova / atualizar existente).
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::resolverDadosNota($data))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::resolverDadosNota($data));
    }

    /**
     * Roda no servidor com o item já dehydratado — $data['valor_total'] aqui é
     * o float limpo que Money::dehydrateStateUsing() já converteu (não a
     * string mascarada da UI, que é o que parseMoneyState() trata).
     *
     * @param  array<string, mixed>  $data
     */
    private static function resolverDadosNota(array $data): array
    {
        if (($data['modo'] ?? 'quantidade') === 'valor_total') {
            $valorNota = self::valorNota($data['nota_moeda_id'] ?? null);

            if ($valorNota > 0) {
                $data['quantidade'] = (int) round((float) ($data['valor_total'] ?? 0) / $valorNota);
            }
        }

        unset($data['modo'], $data['valor_total']);

        return $data;
    }

    /** Mantém o campo "Valor total" (Money, mascarado) mostrando o mesmo valor do Subtotal ao editar a Quantidade. */
    private static function sincronizarValorTotal(Set $set, Get $get, int $quantidade): void
    {
        $valorNota = self::valorNota($get('nota_moeda_id'));

        $set('valor_total', number_format($valorNota * $quantidade, 2, ',', '.'));
    }

    private static function valorNota(mixed $notaMoedaId): float
    {
        if (! $notaMoedaId) {
            return 0.0;
        }

        return (float) (NotaMoeda::find($notaMoedaId)?->valor ?? 0);
    }

    /** Mesmo algoritmo de Money::sanitizeState() — só leitura, nunca escreve de volta no campo mascarado. */
    private static function parseMoneyState(mixed $state): float
    {
        $digits = (int) str_replace(['.', ','], '', (string) ($state ?? '0'));

        return $digits / 100;
    }
}
