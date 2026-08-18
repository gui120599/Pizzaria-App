<?php

namespace App\Filament\Resources\FechamentosCaixa\Schemas;

use App\Enums\OperadoraMaquininha;
use App\Models\FechamentoCaixa;
use App\Models\NotaMoeda;
use App\Models\SessaoCaixa;
use App\Models\SessaoCaixaMaquininha;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
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
                        ->options(fn(?FechamentoCaixa $record): array => self::opcoesSessoes($record))
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->live()
                        // Pré-preenche as maquininhas com o que foi registrado na abertura
                        // (ver SessaoCaixaMaquininha) — o saldo_inicial (odômetro) já vem
                        // certo, só falta o operador digitar débito/crédito/pix do turno.
                        ->afterStateUpdated(fn(Set $set, mixed $state) => $set('maquininhas', self::maquininhasDaAbertura($state)))
                        ->disabled(fn(?FechamentoCaixa $record): bool => $record?->exists ?? false)
                        ->dehydrated(),
                ]),

            Section::make('Contagem de dinheiro')
                ->columnSpan(2)
                ->collapsed()
                ->description('Todas as cédulas/moedas do catálogo já vêm listadas — informe só a quantidade contada de cada uma.')
                ->schema([
                    Repeater::make('notas')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Hidden::make('nota_moeda_id'),
                            TextEntry::make('nota_moeda_label')
                                ->label('Cédula/Moeda')
                                ->state(fn(Get $get): string => NotaMoeda::find($get('nota_moeda_id'))?->descricao ?? '—'),
                            TextInput::make('quantidade')
                                ->label('Quantidade')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->live(onBlur: true)
                                ->required(),
                            TextEntry::make('subtotal')
                                ->label('Subtotal')
                                ->state(fn(Get $get): string => 'R$ ' . number_format(self::valorNota($get('nota_moeda_id')) * (int) ($get('quantidade') ?? 0), 2, ',', '.')),
                        ])
                        ->columns(3)
                        // Catálogo fixo: uma linha por NotaMoeda, já pré-carregada na criação
                        // (o usuário só digita a quantidade — não escolhe/adiciona/remove linhas).
                        ->default(fn(): array => NotaMoeda::ordenadas()->get()->map(fn(NotaMoeda $n): array => [
                            'nota_moeda_id' => $n->id,
                            'quantidade' => 0,
                        ])->toArray())
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->columnSpanFull(),
                ]),

            Section::make('Maquininhas')
                ->columnSpan(3)
                ->collapsed()
                ->description('Valores dos relatórios de fim de expediente de cada maquininha usada no turno.')
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
                                ->createOptionForm([
                                    TextInput::make('nome')->label('Nome')->required()->maxLength(255),
                                    Select::make('operadora')->label('Operadora')->options(OperadoraMaquininha::class)->required(),
                                ])
                                ->required(),
                            Money::make('valor_debito')->label('Débito')->minValue(0)->default(0),
                            Money::make('valor_credito')->label('Crédito')->minValue(0)->default(0),
                            Money::make('valor_pix')->label('Pix')->minValue(0)->default(0),
                            Money::make('saldo_inicial')
                                ->columnSpanFull()
                                ->label('Saldo inicial (odômetro)')
                                ->minValue(0)
                                ->helperText('Valor acumulado que a maquininha ainda não zerou — subtraído só do total geral.'),
                        ])
                        ->columns(3)
                        ->addActionLabel('Adicionar maquininha')
                        ->reorderable(false)
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ]),

            Section::make('Conferência')
                ->columnSpanFull()
                ->description('Apurado (dinheiro contado + maquininhas) × esperado (vendas da sessão) × diferença.')
                ->schema([
                    self::cardConferencia('Dinheiro', fn(FechamentoCaixa $r) => $r->totalDinheiroContado, fn(FechamentoCaixa $r) => (float) $r->total_esperado_dinheiro, fn(FechamentoCaixa $r) => $r->diferencaDinheiro),
                    self::cardConferencia('Débito', fn(FechamentoCaixa $r) => $r->totalDebito, fn(FechamentoCaixa $r) => (float) $r->total_esperado_debito, fn(FechamentoCaixa $r) => $r->diferencaDebito),
                    self::cardConferencia('Crédito', fn(FechamentoCaixa $r) => $r->totalCredito, fn(FechamentoCaixa $r) => (float) $r->total_esperado_credito, fn(FechamentoCaixa $r) => $r->diferencaCredito),
                    self::cardConferencia('Pix', fn(FechamentoCaixa $r) => $r->totalPix, fn(FechamentoCaixa $r) => (float) $r->total_esperado_pix, fn(FechamentoCaixa $r) => $r->diferencaPix),
                    self::cardConferencia('Total geral', fn(FechamentoCaixa $r) => $r->totalApurado, fn(FechamentoCaixa $r) => $r->totalEsperadoGeral, fn(FechamentoCaixa $r) => $r->diferencaGeral, destaque: true),
                ])
                ->columns(4)
                ->visible(fn(?FechamentoCaixa $record): bool => $record?->exists ?? false),

            Section::make('Observações')
                ->columnSpanFull()
                ->schema([
                    Textarea::make('observacoes')
                        ->label('Observações')
                        ->columnSpanFull(),
                ]),
        ])
            ->columns(5);
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
                    ->state(fn(FechamentoCaixa $record): string => 'R$ ' . number_format($apurado($record), 2, ',', '.')),
                TextEntry::make("conferencia_{$slug}_esperado")
                    ->label('Esperado')
                    ->state(fn(FechamentoCaixa $record): string => 'R$ ' . number_format($esperado($record), 2, ',', '.')),
                TextEntry::make("conferencia_{$slug}_diferenca")
                    ->label('Diferença')
                    ->weight(FontWeight::Bold)
                    ->badge()
                    ->color(fn(FechamentoCaixa $record): string => $diferenca($record) == 0.0 ? 'success' : 'danger')
                    ->state(function (FechamentoCaixa $record) use ($diferenca): string {
                        $valor = $diferenca($record);
                        $sinal = $valor > 0 ? 'Sobra' : ($valor < 0 ? 'Falta' : 'OK');

                        return $valor == 0.0
                            ? $sinal
                            : sprintf('%s R$ %s', $sinal, number_format(abs($valor), 2, ',', '.'));
                    }),
            ]);
    }

    /** Maquininhas usadas na abertura da sessão, com o saldo_inicial (odômetro) já preenchido. */
    private static function maquininhasDaAbertura(mixed $sessaoCaixaId): array
    {
        if (! $sessaoCaixaId) {
            return [];
        }

        return SessaoCaixaMaquininha::where('sessao_caixa_id', $sessaoCaixaId)
            ->get()
            ->map(fn(SessaoCaixaMaquininha $m): array => [
                'maquininha_id' => $m->maquininha_id,
                'valor_debito' => 0,
                'valor_credito' => 0,
                'valor_pix' => 0,
                'saldo_inicial' => $m->saldo_inicial,
            ])
            ->toArray();
    }

    private static function valorNota(mixed $notaMoedaId): float
    {
        if (! $notaMoedaId) {
            return 0.0;
        }

        return (float) (NotaMoeda::find($notaMoedaId)?->valor ?? 0);
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
            ->mapWithKeys(fn(SessaoCaixa $sessao): array => [
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
