<?php

namespace App\Filament\Resources\FechamentosCaixa\Schemas;

use App\Filament\Components\ContaFinanceiraSelect;
use App\Filament\Support\ContagemNotasSchema;
use App\Filament\Support\MaquininhaQuickCreateForm;
use App\Models\FechamentoCaixa;
use App\Models\NotaMoeda;
use App\Models\SessaoCaixa;
use App\Models\SessaoCaixaMaquininha;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Leandrocfe\FilamentPtbrFormFields\Money;

class FechamentoCaixaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sessão de caixa')
                ->columnSpanFull()
                ->schema([
                    Select::make('sessao_caixa_id')
                        ->label('Sessão de caixa')
                        ->options(fn (?FechamentoCaixa $record): array => self::opcoesSessoes($record))
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->live()
                        // Pré-preenche as maquininhas com as que foram usadas na abertura
                        // (ver SessaoCaixaMaquininha) — o carryover por categoria fica só
                        // consultado (ver totalPorCategoria em FechamentoCaixa), não é
                        // mais copiado/editável aqui.
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $set('maquininhas', self::maquininhasDaAbertura($state));
                            $set('valor_troco_gaveta', self::trocoSugerido($state));
                        })
                        ->disabled(fn (?FechamentoCaixa $record): bool => $record?->exists ?? false)
                        ->dehydrated(),

                    Text::make(fn (Get $get): string => self::resumoCarryover($get('sessao_caixa_id')))
                        ->visible(fn (Get $get): bool => (bool) $get('sessao_caixa_id')),
                ]),

            Section::make('Contagem de dinheiro')
                ->columnSpanFull()
                ->collapsed()
                ->description('Todas as cédulas/moedas do catálogo já vêm listadas — escolha, em cada linha, se prefere informar a quantidade contada ou o valor total.')
                ->schema([
                    ContagemNotasSchema::make(),
                ]),

            Section::make('Maquininhas')
                ->columnSpanFull()
                ->collapsed()
                ->description('Valores dos relatórios de fim de expediente de cada maquininha usada no turno — o carryover da abertura é abatido automaticamente, por categoria.')
                ->schema([
                    Repeater::make('maquininhas')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Select::make('maquininha_id')
                                ->columnSpanFull()
                                ->label('Maquininha')
                                ->relationship('maquininha', 'nome')
                                ->searchable()
                                ->preload()
                                ->createOptionForm(MaquininhaQuickCreateForm::schema())
                                ->required(),
                            Money::make('valor_debito')->label('Débito')->minValue(0)->default(0),
                            Money::make('valor_credito')->label('Crédito')->minValue(0)->default(0),
                            Money::make('valor_pix')->label('Pix')->minValue(0)->default(0),
                        ])
                        ->columns(3)
                        ->addActionLabel('Adicionar maquininha')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ]),

            Section::make('PIX CNPJ')
                ->columnSpanFull()
                ->collapsed()
                ->description('Pix recebido direto na chave CNPJ da empresa durante o turno — confira pelo extrato do banco. Não lance como maquininha.')
                ->schema([
                    Money::make('valor_pix_cnpj')
                        ->label('Total recebido no extrato')
                        ->minValue(0)
                        ->default(0),
                ]),

            Section::make('Troco e recolhimento')
                ->columnSpanFull()
                ->description('O troco sempre fica na gaveta para o próximo turno; o resto do dinheiro contado é recolhido para uma conta na confirmação.')
                ->columns(3)
                ->schema([
                    Money::make('valor_troco_gaveta')
                        ->label('Troco que fica no caixa')
                        ->minValue(0)
                        ->required()
                        ->suffixAction(self::acaoContarTroco()),
                    Text::make(fn (?FechamentoCaixa $record): string => self::resumoRecolhimento($record))
                        ->visible(fn (?FechamentoCaixa $record): bool => $record?->exists ?? false),
                    ContaFinanceiraSelect::make('recolhimento_conta_id')
                        ->label('Recolher para')
                        ->semCaixaPdv()
                        ->placeholder('Selecione a conta')
                        ->helperText('Obrigatório quando sobra dinheiro além do troco.'),
                ]),

            Section::make('Conferência')
                ->columnSpanFull()
                ->description('Apurado (dinheiro contado + maquininhas já líquidas do carryover + extrato do PIX CNPJ) × esperado (vendas da sessão) × diferença.')
                ->schema([
                    self::cardConferencia('Dinheiro', fn (FechamentoCaixa $r) => $r->totalDinheiroContado, fn (FechamentoCaixa $r) => (float) $r->total_esperado_dinheiro, fn (FechamentoCaixa $r) => $r->diferencaDinheiro),
                    self::cardConferencia('Débito', fn (FechamentoCaixa $r) => $r->totalDebito, fn (FechamentoCaixa $r) => (float) $r->total_esperado_debito, fn (FechamentoCaixa $r) => $r->diferencaDebito),
                    self::cardConferencia('Crédito', fn (FechamentoCaixa $r) => $r->totalCredito, fn (FechamentoCaixa $r) => (float) $r->total_esperado_credito, fn (FechamentoCaixa $r) => $r->diferencaCredito),
                    self::cardConferencia('Pix (maquininhas)', fn (FechamentoCaixa $r) => $r->totalPix, fn (FechamentoCaixa $r) => (float) $r->total_esperado_pix, fn (FechamentoCaixa $r) => $r->diferencaPix),
                    self::cardConferencia('PIX CNPJ', fn (FechamentoCaixa $r) => $r->totalPixCnpj, fn (FechamentoCaixa $r) => (float) $r->total_esperado_pix_cnpj, fn (FechamentoCaixa $r) => $r->diferencaPixCnpj),
                    self::cardConferencia('Total geral', fn (FechamentoCaixa $r) => $r->totalApurado, fn (FechamentoCaixa $r) => $r->totalEsperadoGeral, fn (FechamentoCaixa $r) => $r->diferencaGeral, destaque: true),
                ])
                ->columns(5)
                ->visible(fn (?FechamentoCaixa $record): bool => $record?->exists ?? false),

            Section::make('Observações')
                ->columnSpanFull()
                ->schema([
                    Textarea::make('observacoes')
                        ->label('Observações')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Card de conferência de uma forma de pagamento (ou total geral): apurado, esperado e diferença. */
    private static function cardConferencia(string $label, Closure $apurado, Closure $esperado, Closure $diferenca, bool $destaque = false): Section
    {
        $slug = Str::slug($label);

        return Section::make($label)
            ->compact()
            ->icon($destaque ? Heroicon::OutlinedCalculator : null)
            ->iconColor($destaque ? 'primary' : null)
            ->columnSpan($destaque ? 'full' : 1)
            ->extraAttributes($destaque ? [
                'class' => 'ring-2 ring-primary-600 dark:ring-primary-400 bg-primary-50/60 dark:bg-primary-500/10',
            ] : [])
            ->schema([
                TextEntry::make("conferencia_{$slug}_apurado")
                    ->label('Apurado')
                    ->state(fn (FechamentoCaixa $record): string => 'R$ '.number_format($apurado($record), 2, ',', '.')),
                TextEntry::make("conferencia_{$slug}_esperado")
                    ->label('Esperado')
                    ->state(fn (FechamentoCaixa $record): string => 'R$ '.number_format($esperado($record), 2, ',', '.')),
                TextEntry::make("conferencia_{$slug}_diferenca")
                    ->label('Diferença')
                    ->weight(FontWeight::Bold)
                    ->badge()
                    ->color(fn (FechamentoCaixa $record): string => $diferenca($record) == 0.0 ? 'success' : 'danger')
                    ->state(function (FechamentoCaixa $record) use ($diferenca): string {
                        $valor = $diferenca($record);
                        $sinal = $valor > 0 ? 'Sobra' : ($valor < 0 ? 'Falta' : 'OK');

                        return $valor == 0.0
                            ? $sinal
                            : sprintf('%s R$ %s', $sinal, number_format(abs($valor), 2, ',', '.'));
                    }),
            ]);
    }

    /**
     * Conta as cédulas/moedas que ficam na gaveta e usa o total como troco:
     * a abertura seguinte conta as mesmas notas. A contagem só define o valor
     * (não é persistida): o troco gravado é sempre o campo ao lado.
     */
    private static function acaoContarTroco(): Action
    {
        return Action::make('contarTroco')
            ->label('Contar notas')
            ->icon('heroicon-m-calculator')
            ->modalHeading('Contar o troco que fica no caixa')
            ->modalSubmitActionLabel('Usar este valor')
            ->fillForm(fn (): array => ['notas' => NotaMoeda::ordenadas()->get()->map(fn (NotaMoeda $nota): array => [
                'nota_moeda_id' => $nota->id,
                'descricao' => $nota->descricao,
                'quantidade' => 0,
            ])->all()])
            ->schema([
                Repeater::make('notas')
                    ->label('')
                    ->schema([
                        Hidden::make('nota_moeda_id'),
                        TextInput::make('descricao')->label('Cédula/Moeda')->disabled()->dehydrated(),
                        TextInput::make('quantidade')->label('Quantidade')->numeric()->integer()->minValue(0)->default(0)->required(),
                    ])
                    ->columns(2)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
            ])
            ->action(function (array $data, Set $set): void {
                $valores = NotaMoeda::query()->pluck('valor', 'id');
                $total = collect($data['notas'] ?? [])->sum(fn (array $nota): float => (int) ($nota['quantidade'] ?? 0) * (float) ($valores[$nota['nota_moeda_id']] ?? 0));

                $set('valor_troco_gaveta', number_format($total, 2, ',', '.'));
            });
    }

    /** Troco padrão do caixa físico da sessão ou, sem ele, o saldo com que o caixa abriu. */
    private static function trocoSugerido(mixed $sessaoCaixaId): string
    {
        $sessao = $sessaoCaixaId ? SessaoCaixa::query()->with('caixa')->find($sessaoCaixaId) : null;
        $troco = $sessao?->caixa?->caixa_troco_padrao ?? $sessao?->sessaocaixa_saldo_inicial ?? 0;

        return number_format((float) $troco, 2, ',', '.');
    }

    /** Quanto será recolhido: dinheiro contado menos o troco salvo (nunca digitado). */
    private static function resumoRecolhimento(?FechamentoCaixa $record): string
    {
        if ($record === null || $record->valor_troco_gaveta === null) {
            return 'Valor recolhido: salve o troco para ver o valor.';
        }

        $recolhido = round($record->totalDinheiroContado - (float) $record->valor_troco_gaveta, 2);

        return 'Valor recolhido: R$ '.number_format($recolhido, 2, ',', '.').' (dinheiro contado R$ '.number_format($record->totalDinheiroContado, 2, ',', '.').' menos o troco).';
    }

    /** Maquininhas usadas na abertura da sessão — só a maquininha em si, o carryover fica só consultado. */
    private static function maquininhasDaAbertura(mixed $sessaoCaixaId): array
    {
        if (! $sessaoCaixaId) {
            return [];
        }

        return SessaoCaixaMaquininha::where('sessao_caixa_id', $sessaoCaixaId)
            ->get()
            ->map(fn (SessaoCaixaMaquininha $m): array => [
                'maquininha_id' => $m->maquininha_id,
                'valor_debito' => 0,
                'valor_credito' => 0,
                'valor_pix' => 0,
            ])
            ->toArray();
    }

    /** Texto de transparência: quanto cada maquininha da abertura já carregava, por categoria. */
    private static function resumoCarryover(mixed $sessaoCaixaId): string
    {
        if (! $sessaoCaixaId) {
            return '';
        }

        $linhas = SessaoCaixaMaquininha::where('sessao_caixa_id', $sessaoCaixaId)
            ->with('maquininha')
            ->get()
            ->filter(fn (SessaoCaixaMaquininha $m): bool => ((float) $m->valor_debito + (float) $m->valor_credito + (float) $m->valor_pix) > 0)
            ->map(fn (SessaoCaixaMaquininha $m): string => sprintf(
                '%s (Débito R$ %s · Crédito R$ %s · Pix R$ %s)',
                $m->maquininha?->nome ?? 'Maquininha',
                number_format((float) $m->valor_debito, 2, ',', '.'),
                number_format((float) $m->valor_credito, 2, ',', '.'),
                number_format((float) $m->valor_pix, 2, ',', '.'),
            ));

        if ($linhas->isEmpty()) {
            return 'Nenhuma maquininha desta sessão tinha carryover registrado na abertura.';
        }

        return 'Carregado da abertura — '.$linhas->implode(' · ');
    }

    /** Sessões FECHADA sem fechamento ainda, + a sessão já vinculada ao registro em edição. */
    private static function opcoesSessoes(?FechamentoCaixa $record): array
    {
        return SessaoCaixa::query()
            ->where('sessaocaixa_status', 'FECHADA')
            ->where(function (Builder $query) use ($record): void {
                $query->whereDoesntHave('fechamentoCaixa');

                if ($record?->sessao_caixa_id) {
                    $query->orWhere('id', $record->sessao_caixa_id);
                }
            })
            ->with(['caixa', 'user'])
            ->orderByDesc('sessaocaixa_data_hora_fechamento')
            ->get()
            ->mapWithKeys(fn (SessaoCaixa $sessao): array => [
                $sessao->id => sprintf(
                    '%s — %s (%s)',
                    $sessao->caixa?->caixa_nome ?? 'Caixa',
                    $sessao->sessaocaixa_data_hora_fechamento?->format('d/m/Y H:i') ?? '—',
                    $sessao->user?->name ?? '—',
                ),
            ])
            ->toArray();
    }
}
