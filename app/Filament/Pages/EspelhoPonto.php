<?php

namespace App\Filament\Pages;

use App\Enums\Ponto\MotivoAjuste;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\PontoMarcacao;
use App\Models\PontoPeriodo;
use App\Services\Ponto\CorrecaoPontoService;
use App\Services\Ponto\EspelhoPontoService;
use App\Services\Ponto\PermissoesPonto;
use App\Support\DiaOperacional;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Espelho de ponto do período (spec §6): grade colaborador × dia com a
 * situação de cada dia; o modal do dia mostra as marcações efetivas e as
 * correções, com as ações do gestor (§5.2.1). As regras (quem corrige,
 * período aberto, justificativa) ficam no CorrecaoPontoService.
 */
class EspelhoPonto extends Page
{
    protected static string $routePath = '/espelho-ponto';

    protected static ?string $title = 'Espelho de ponto';

    protected static ?string $navigationLabel = 'Espelho de ponto';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static UnitEnum|string|null $navigationGroup = 'Ponto e Repasses';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.espelho-ponto';

    #[Url]
    public ?int $periodo = null;

    #[Url]
    public ?int $colaborador = null;

    #[Url]
    public ?string $setor = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('view_espelho:ponto') ?? false;
    }

    public function mount(): void
    {
        $this->periodo ??= PontoPeriodo::query()->contendo(DiaOperacional::de(now()))->orderBy('id')->value('id')
            ?? PontoPeriodo::query()->orderByDesc('data_inicio')->value('id');
    }

    #[Computed]
    public function periodoAtual(): ?PontoPeriodo
    {
        return $this->periodo !== null ? PontoPeriodo::query()->find($this->periodo) : null;
    }

    /** @return array<int, string> */
    #[Computed]
    public function opcoesPeriodo(): array
    {
        return PontoPeriodo::query()->orderByDesc('data_inicio')->limit(40)->get()
            ->mapWithKeys(fn (PontoPeriodo $periodo): array => [$periodo->id => "{$periodo->periodicidade->getLabel()} {$periodo->rotulo()} — {$periodo->status->getLabel()}"])
            ->all();
    }

    /** @return Collection<int, Colaborador> */
    #[Computed]
    public function colaboradores(): Collection
    {
        if ($this->periodoAtual === null) {
            return collect();
        }

        return Colaborador::query()
            ->doPeriodo($this->periodoAtual)
            ->when($this->colaborador, fn ($query) => $query->whereKey($this->colaborador))
            ->when(filled($this->setor), fn ($query) => $query->where('setor', $this->setor))
            ->orderBy('nome')
            ->get();
    }

    /** @return array<string, string> */
    #[Computed]
    public function opcoesSetor(): array
    {
        return Colaborador::query()->whereNotNull('setor')->distinct()->orderBy('setor')->pluck('setor', 'setor')->all();
    }

    /** @return array{dias: list<CarbonImmutable>, linhas: list<array<string, mixed>>} */
    #[Computed]
    public function grade(): array
    {
        return $this->periodoAtual === null
            ? ['dias' => [], 'linhas' => []]
            : app(EspelhoPontoService::class)->grade($this->periodoAtual, $this->colaboradores);
    }

    /** Correções só com período aberto e nunca no próprio ponto (spec §5.6). */
    public function podeCorrigir(Colaborador $colaborador): bool
    {
        return $this->periodoAtual?->estaTravado() === false
            && app(PermissoesPonto::class)->podeCorrigir(Auth::user(), $colaborador);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (): bool => $this->periodoAtual !== null)
                ->url(fn (): string => route('ponto.espelho.imprimir', array_filter(['periodo' => $this->periodo, 'colaborador' => $this->colaborador, 'setor' => $this->setor])))
                ->openUrlInNewTab(),
            Action::make('exportarCsv')
                ->label('CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => $this->periodoAtual !== null)
                ->action(fn (): StreamedResponse => $this->exportarCsv()),
            $this->abonarEmLoteAction(),
        ];
    }

    public function diaAction(): Action
    {
        return Action::make('dia')
            ->modalHeading(fn (array $arguments): string => $this->colaboradorDe($arguments)->nome.' — '.Carbon::parse($arguments['dia'])->translatedFormat('D, d/m/Y'))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalContent(fn (array $arguments) => view('filament.pages.partials.espelho-dia', [
                'colaborador' => $this->colaboradorDe($arguments),
                'dia' => Carbon::parse($arguments['dia']),
                'detalhe' => app(EspelhoPontoService::class)->detalheDoDia($this->colaboradorDe($arguments), Carbon::parse($arguments['dia'])),
                'podeCorrigir' => $this->podeCorrigir($this->colaboradorDe($arguments)),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar');
    }

    public function incluirMarcacaoAction(): Action
    {
        return $this->acaoDeCorrecao('incluirMarcacao', 'Incluir marcação')
            ->schema([
                TimePicker::make('hora')->label('Horário')->seconds(false)->required()
                    ->helperText('Antes da virada do dia ('.config('operacao.virada_dia').') conta como madrugada do dia seguinte.'),
                ...$this->camposDeMotivo(),
            ])
            ->action(fn (array $arguments, array $data, Action $action) => $this->corrigir($action, 'Marcação incluída', fn (CorrecaoPontoService $correcao) => $correcao->incluirMarcacao(
                $this->colaboradorDe($arguments),
                DiaOperacional::momentoNoDia(Carbon::parse($arguments['dia']), substr((string) $data['hora'], 0, 5)),
                $this->motivo($data),
                $data['justificativa'],
                Auth::user(),
            )));
    }

    public function corrigirHorarioAction(): Action
    {
        return $this->acaoDeCorrecao('corrigirHorario', 'Corrigir horário')
            ->schema([
                TimePicker::make('hora')->label('Horário correto')->seconds(false)->required(),
                ...$this->camposDeMotivo(MotivoAjuste::HorarioErrado),
            ])
            ->action(function (array $arguments, array $data, Action $action): void {
                $marcacao = PontoMarcacao::query()->findOrFail($arguments['marcacao']);

                $this->corrigir($action, 'Horário corrigido', fn (CorrecaoPontoService $correcao) => $correcao->corrigirHorario(
                    $marcacao,
                    DiaOperacional::momentoNoDia(Carbon::parse($marcacao->data_referencia), substr((string) $data['hora'], 0, 5)),
                    $this->motivo($data),
                    $data['justificativa'],
                    Auth::user(),
                ));
            });
    }

    public function desconsiderarAction(): Action
    {
        return $this->acaoDeCorrecao('desconsiderar', 'Desconsiderar marcação')
            ->color('danger')
            ->modalDescription('A marcação original continua registrada; só deixa de valer na apuração.')
            ->schema($this->camposDeMotivo(MotivoAjuste::MarcacaoDuplicada))
            ->action(fn (array $arguments, array $data, Action $action) => $this->corrigir($action, 'Marcação desconsiderada', fn (CorrecaoPontoService $correcao) => $correcao->desconsiderar(
                PontoMarcacao::query()->findOrFail($arguments['marcacao']),
                $this->motivo($data),
                $data['justificativa'],
                Auth::user(),
            )));
    }

    public function lancarDiaAction(): Action
    {
        return $this->acaoDeCorrecao('lancarDia', 'Lançar dia completo')
            ->schema([
                Repeater::make('horarios')
                    ->label('Horários (entrada, saída, entrada, saída…)')
                    ->simple(TimePicker::make('hora')->seconds(false)->required())
                    ->default([['hora' => null], ['hora' => null]])
                    ->minItems(2)
                    ->reorderable(false)
                    ->addActionLabel('Adicionar horário'),
                Toggle::make('desconsiderar_existentes')->label('Desconsiderar as marcações que já existem no dia'),
                ...$this->camposDeMotivo(MotivoAjuste::FalhaSistema),
            ])
            ->action(fn (array $arguments, array $data, Action $action) => $this->corrigir($action, 'Dia lançado', fn (CorrecaoPontoService $correcao) => $correcao->lancarDia(
                $this->colaboradorDe($arguments),
                Carbon::parse($arguments['dia']),
                collect($data['horarios'])->map(fn (mixed $hora): string => substr((string) (is_array($hora) ? ($hora['hora'] ?? '') : $hora), 0, 5))->filter()->values()->all(),
                $this->motivo($data),
                $data['justificativa'],
                Auth::user(),
                (bool) ($data['desconsiderar_existentes'] ?? false),
            )));
    }

    public function abonarAction(): Action
    {
        return $this->acaoDeCorrecao('abonar', 'Abonar')
            ->schema([
                Toggle::make('dia_inteiro')->label('Dia inteiro')->default(true)->live(),
                TextInput::make('minutos')->label('Minutos abonados')->numeric()->integer()->minValue(1)
                    ->visible(fn (Get $get): bool => ! $get('dia_inteiro'))
                    ->required(fn (Get $get): bool => ! $get('dia_inteiro')),
                ...$this->camposDeMotivo(MotivoAjuste::Atestado),
            ])
            ->action(fn (array $arguments, array $data, Action $action) => $this->corrigir($action, 'Abono lançado', fn (CorrecaoPontoService $correcao) => $correcao->abonar(
                $this->colaboradorDe($arguments),
                Carbon::parse($arguments['dia']),
                ($data['dia_inteiro'] ?? true) ? null : (int) $data['minutos'],
                $this->motivo($data),
                $data['justificativa'],
                Auth::user(),
            )));
    }

    public function desfazerCorrecaoAction(): Action
    {
        return Action::make('desfazerCorrecao')
            ->label('Desfazer correção')
            ->color('warning')
            ->modalDescription('Os ajustes do lote ficam no histórico como "desfeito" e o dia é reapurado.')
            ->schema([Textarea::make('justificativa')->label('Justificativa')->required()->minLength(10)->rows(2)])
            ->action(fn (array $arguments, array $data, Action $action) => $this->corrigir($action, 'Correção desfeita', fn (CorrecaoPontoService $correcao) => $correcao->desfazerCorrecao(
                $arguments['lote'],
                $data['justificativa'],
                Auth::user(),
            )));
    }

    /** Folga/abono para vários colaboradores e dias de uma vez (ex.: loja fechada). */
    public function abonarEmLoteAction(): Action
    {
        return Action::make('abonarEmLote')
            ->label('Abonar em lote')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->visible(fn (): bool => $this->periodoAtual?->estaTravado() === false && (Auth::user()?->can('corrigir:ponto') ?? false))
            ->modalDescription('Lança abono do dia inteiro para cada colaborador e dia escolhidos. Quem for você mesmo fica de fora.')
            ->schema([
                Select::make('colaboradores')->label('Colaboradores')->multiple()->required()
                    ->options(fn (): array => $this->colaboradores->pluck('nome', 'id')->all()),
                DatePicker::make('de')->label('De')->native(false)->displayFormat('d/m/Y')->required()
                    ->minDate(fn () => $this->periodoAtual?->data_inicio)->maxDate(fn () => $this->periodoAtual?->data_fim),
                DatePicker::make('ate')->label('Até')->native(false)->displayFormat('d/m/Y')->required()->afterOrEqual('de')
                    ->minDate(fn () => $this->periodoAtual?->data_inicio)->maxDate(fn () => $this->periodoAtual?->data_fim),
                ...$this->camposDeMotivo(MotivoAjuste::Acordo),
            ])
            ->action(function (array $data, CorrecaoPontoService $correcao): void {
                $feitos = 0;
                $recusados = [];

                foreach (Colaborador::query()->whereKey($data['colaboradores'])->get() as $colaborador) {
                    for ($dia = Carbon::parse($data['de']); $dia->lte(Carbon::parse($data['ate'])); $dia->addDay()) {
                        try {
                            $correcao->abonar($colaborador, $dia->copy(), null, $this->motivo($data), $data['justificativa'], Auth::user());
                            $feitos++;
                        } catch (PontoException $e) {
                            $recusados[] = "{$colaborador->nome} {$dia->format('d/m')}: {$e->getMessage()}";
                        }
                    }
                }

                Notification::make()
                    ->title("{$feitos} abono(s) lançado(s)")
                    ->body($recusados === [] ? null : implode("\n", array_slice($recusados, 0, 15)))
                    ->color($recusados === [] ? 'success' : 'warning')
                    ->persistent($recusados !== [])
                    ->send();
            });
    }

    private function acaoDeCorrecao(string $nome, string $rotulo): Action
    {
        return Action::make($nome)
            ->label($rotulo)
            ->modalHeading($rotulo)
            ->modalSubmitActionLabel('Salvar correção');
    }

    /** @return list<Field> */
    private function camposDeMotivo(MotivoAjuste $padrao = MotivoAjuste::Esquecimento): array
    {
        return [
            Select::make('motivo')->label('Motivo')->options(MotivoAjuste::class)->default($padrao->value)->required(),
            Textarea::make('justificativa')->label('Justificativa')->required()->minLength(10)->rows(2)
                ->helperText('Fica no histórico e o colaborador é avisado.'),
        ];
    }

    private function corrigir(Action $action, string $sucesso, callable $operacao): void
    {
        try {
            app()->call($operacao);
        } catch (PontoException $e) {
            Notification::make()->title('Correção não feita')->body($e->getMessage())->danger()->send();
            $action->halt();
        }

        Notification::make()->title($sucesso)->body('O dia foi reapurado e o colaborador avisado.')->success()->send();
        unset($this->grade);
    }

    /** @param  array<string, mixed>  $data */
    private function motivo(array $data): MotivoAjuste
    {
        return $data['motivo'] instanceof MotivoAjuste ? $data['motivo'] : MotivoAjuste::from($data['motivo']);
    }

    /** @param  array<string, mixed>  $arguments */
    private function colaboradorDe(array $arguments): Colaborador
    {
        return Colaborador::withTrashed()->findOrFail($arguments['colaborador']);
    }

    private function exportarCsv(): StreamedResponse
    {
        $grade = $this->grade;
        $periodo = $this->periodoAtual;

        return response()->streamDownload(function () use ($grade): void {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Colaborador', 'Dia', 'Situação', 'Trabalhado', 'Previsto', 'Intervalo', 'Noturno', 'Extra 50%', 'Extra 100%', 'Atraso', 'Abonado', 'Entregas', 'Inconsistências', 'Corrigido'], ';');

            foreach ($grade['linhas'] as $linha) {
                foreach ($linha['celulas'] as $data => $dia) {
                    if ($dia === null) {
                        continue;
                    }

                    fputcsv($saida, [
                        $linha['colaborador']->nome,
                        Carbon::parse($data)->format('d/m/Y'),
                        $dia->situacao->getLabel(),
                        EspelhoPontoService::horas($dia->minutos_trabalhados),
                        EspelhoPontoService::horas($dia->minutos_previstos),
                        EspelhoPontoService::horas($dia->minutos_intervalo),
                        EspelhoPontoService::horas($dia->minutos_noturnos),
                        EspelhoPontoService::horas($dia->minutos_extra_50),
                        EspelhoPontoService::horas($dia->minutos_extra_100),
                        EspelhoPontoService::horas($dia->minutos_atraso),
                        EspelhoPontoService::horas($dia->minutos_abonados),
                        $dia->entregas_qtd,
                        implode(', ', $dia->rotulosInconsistencias()),
                        isset($linha['corrigidos'][$data]) ? 'sim' : '',
                    ], ';');
                }
            }

            fclose($saida);
        }, 'espelho-ponto-'.$periodo->data_inicio->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
