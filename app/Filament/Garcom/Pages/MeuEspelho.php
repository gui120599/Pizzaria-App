<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\Ponto\MotivoAjuste;
use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\StatusPeriodo;
use App\Enums\Ponto\TipoAjuste;
use App\Exceptions\PontoException;
use App\Filament\Garcom\Concerns\DoColaborador;
use App\Models\PontoAjuste;
use App\Models\PontoDia;
use App\Models\PontoPeriodo;
use App\Services\Ponto\CorrecaoPontoService;
use App\Services\Ponto\EspelhoPontoService;
use App\Services\Ponto\MarcacoesDoDia;
use App\Services\Ponto\PeriodosPonto;
use App\Services\Ponto\PontoService;
use App\Support\DiaOperacional;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * "Meu espelho" (spec §7): o próprio ponto do período, dia a dia. Dias
 * corrigidos pelo gestor ficam destacados até o colaborador dar ciência;
 * "Contestar" e "Solicitar ajuste" abrem uma solicitação normal (fila do
 * gestor). Período sem cadastro usa os limites da periodicidade.
 */
class MeuEspelho extends Page
{
    use DoColaborador;

    /** Tipos que o colaborador pode pedir (folga compensatória depende de banco de horas, fora desta versão). */
    private const TIPOS_SOLICITAVEIS = [TipoAjuste::Inclusao, TipoAjuste::Desconsideracao, TipoAjuste::Abono, TipoAjuste::FaltaJustificada];

    protected string $view = 'filament.garcom.pages.meu-espelho';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Meu espelho';

    protected static ?string $title = 'Meu espelho de ponto';

    protected static ?string $slug = 'meu-espelho';

    protected static ?int $navigationSort = -9;

    /** Vazio = período que contém hoje (o select manda "" ao voltar para o atual). */
    #[Url]
    public int|string|null $periodo = null;

    public function updatedPeriodo(): void
    {
        unset($this->periodoAtual, $this->espelho);
    }

    #[Computed]
    public function periodoAtual(): PontoPeriodo
    {
        $colaborador = $this->colaborador();

        if (filled($this->periodo)) {
            $escolhido = PontoPeriodo::query()->daPeriodicidade($colaborador->periodicidade_repasse)->find((int) $this->periodo);

            if ($escolhido !== null) {
                return $escolhido;
            }
        }

        $hoje = DiaOperacional::de();
        $periodos = app(PeriodosPonto::class);

        if ($existente = $periodos->periodoDe($colaborador, $hoje)) {
            return $existente;
        }

        [$inicio, $fim] = $periodos->limites($colaborador->periodicidade_repasse, $hoje);

        return new PontoPeriodo([
            'periodicidade' => $colaborador->periodicidade_repasse,
            'data_inicio' => $inicio,
            'data_fim' => $fim,
            'status' => StatusPeriodo::Aberto,
        ]);
    }

    /** @return array<int|string, string> */
    #[Computed]
    public function opcoesPeriodo(): array
    {
        return PontoPeriodo::query()
            ->daPeriodicidade($this->colaborador()->periodicidade_repasse)
            ->whereDate('data_inicio', '<=', DiaOperacional::de()->toDateString())
            ->orderByDesc('data_inicio')
            ->limit(6)
            ->get()
            ->mapWithKeys(fn (PontoPeriodo $periodo): array => [$periodo->id => "{$periodo->rotulo()} — {$periodo->status->getLabel()}"])
            ->all();
    }

    /**
     * @return array{dias: list<CarbonImmutable>, celulas: array<string, PontoDia|null>, totais: array<string, int>, cienciaPendente: array<string, true>, solicitacoesPendentes: array<string, int>}
     */
    #[Computed]
    public function espelho(): array
    {
        $colaborador = $this->colaborador();
        $periodo = $this->periodoAtual;
        $grade = app(EspelhoPontoService::class)->grade($periodo, $colaborador->newCollection([$colaborador]));
        $linha = $grade['linhas'][0];
        $ajustes = PontoAjuste::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('data_referencia', '>=', $periodo->data_inicio->toDateString())
            ->whereDate('data_referencia', '<=', $periodo->data_fim->toDateString())
            ->get(['data_referencia', 'origem', 'status', 'colaborador_ciente_em']);

        return [
            'dias' => array_values(array_filter($grade['dias'], fn (CarbonImmutable $dia): bool => $dia->lte(DiaOperacional::de()))),
            'celulas' => $linha['celulas'],
            'totais' => $linha['totais'],
            'cienciaPendente' => $ajustes
                ->filter(fn (PontoAjuste $ajuste): bool => $ajuste->origem === OrigemAjuste::Gestor
                    && in_array($ajuste->status, [StatusAjuste::Aprovado, StatusAjuste::Desfeito], true)
                    && $ajuste->colaborador_ciente_em === null)
                ->mapWithKeys(fn (PontoAjuste $ajuste): array => [$ajuste->data_referencia->toDateString() => true])
                ->all(),
            'solicitacoesPendentes' => $ajustes
                ->where('status', StatusAjuste::Pendente)
                ->countBy(fn (PontoAjuste $ajuste): string => $ajuste->data_referencia->toDateString())
                ->all(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->solicitarAjusteAction()
                ->name('solicitarAjusteHeader')
                ->visible(fn (): bool => ! $this->periodoAtual->estaTravado()),
        ];
    }

    public function diaAction(): Action
    {
        return Action::make('dia')
            ->modalHeading(fn (array $arguments): string => Carbon::parse($arguments['dia'])->translatedFormat('l, d/m/Y'))
            ->modalContent(function (array $arguments) {
                $colaborador = $this->colaborador();
                $dia = Carbon::parse($arguments['dia']);

                return view('filament.garcom.pages.partials.meu-espelho-dia', [
                    'dia' => $dia,
                    'detalhe' => app(EspelhoPontoService::class)->detalheDoDia($colaborador, $dia),
                    'solicitacoes' => $colaborador->ajustes()
                        ->where('origem', OrigemAjuste::Colaborador)
                        ->whereDate('data_referencia', $dia->toDateString())
                        ->latest('id')
                        ->get(),
                    'cienciaPendente' => isset($this->espelho['cienciaPendente'][$dia->toDateString()]),
                    'podeSolicitar' => ! $this->periodoAtual->estaTravado(),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar');
    }

    public function cienteAction(): Action
    {
        return Action::make('ciente')
            ->label('Ciente')
            ->icon(Heroicon::OutlinedCheck)
            ->requiresConfirmation()
            ->modalHeading('Dar ciência da correção')
            ->modalDescription('Confirma que viu a correção feita pelo gestor neste dia. Se não concordar, use "Contestar".')
            ->action(function (array $arguments, Action $action): void {
                try {
                    app(CorrecaoPontoService::class)->registrarCiencia($this->colaborador(), Carbon::parse($arguments['dia']), Auth::user());
                } catch (PontoException $e) {
                    Notification::make()->title('Não foi possível registrar')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Ciência registrada')->success()->send();
                unset($this->espelho);
            });
    }

    public function solicitarAjusteAction(): Action
    {
        return Action::make('solicitarAjuste')
            ->label(fn (array $arguments): string => ($arguments['contestar'] ?? false) ? 'Contestar' : 'Solicitar ajuste')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(fn (array $arguments): string => ($arguments['contestar'] ?? false) ? 'Contestar correção' : 'Solicitar ajuste')
            ->modalDescription('O gestor recebe o pedido e aprova ou rejeita. Você é avisado da decisão.')
            ->modalSubmitActionLabel('Enviar pedido')
            ->schema(fn (array $arguments): array => [
                DatePicker::make('dia')
                    ->label('Dia')
                    ->default(DiaOperacional::de()->toDateString())
                    ->maxDate(DiaOperacional::de()->toDateString())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required()
                    ->live()
                    ->visible(! isset($arguments['dia'])),
                Select::make('tipo')
                    ->label('O que aconteceu?')
                    ->options(collect(self::TIPOS_SOLICITAVEIS)->mapWithKeys(fn (TipoAjuste $tipo): array => [$tipo->value => self::rotuloTipo($tipo)])->all())
                    ->default(TipoAjuste::Inclusao->value)
                    ->required()
                    ->live(),
                TimePicker::make('hora')
                    ->label('Horário que faltou')
                    ->seconds(false)
                    ->required()
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoAjuste::Inclusao->value)
                    ->helperText('Antes da virada do dia ('.config('operacao.virada_dia').') conta como madrugada do dia seguinte.'),
                Select::make('ponto_marcacao_id')
                    ->label('Marcação indevida')
                    ->options(fn (Get $get): array => $this->opcoesMarcacao($arguments['dia'] ?? $get('dia')))
                    ->required()
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoAjuste::Desconsideracao->value),
                TextInput::make('minutos')
                    ->label('Minutos a abonar')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(1440)
                    ->helperText('Em branco = o dia inteiro.')
                    ->visible(fn (Get $get): bool => $get('tipo') === TipoAjuste::Abono->value),
                Select::make('motivo')
                    ->label('Motivo')
                    ->options(MotivoAjuste::class)
                    ->default(MotivoAjuste::Esquecimento->value)
                    ->required(),
                Textarea::make('justificativa')
                    ->label('Explique')
                    ->required()
                    ->minLength(10)
                    ->rows(3),
                FileUpload::make('anexo')
                    ->label('Anexo (atestado, declaração)')
                    ->acceptedFileTypes(['image/*', 'application/pdf'])
                    ->maxSize(5120)
                    // O PontoService grava o arquivo no disco do ponto.
                    ->storeFiles(false),
            ])
            ->action(function (array $arguments, array $data, Action $action): void {
                $dia = CarbonImmutable::parse($arguments['dia'] ?? $data['dia'])->startOfDay();
                $tipo = TipoAjuste::from($data['tipo']);

                try {
                    app(PontoService::class)->solicitarAjuste($this->colaborador(), $tipo, $data['motivo'] instanceof MotivoAjuste ? $data['motivo'] : MotivoAjuste::from($data['motivo']), [
                        'data_referencia' => $dia,
                        'data_hora' => $tipo === TipoAjuste::Inclusao ? DiaOperacional::momentoNoDia($dia, substr((string) $data['hora'], 0, 5)) : null,
                        'ponto_marcacao_id' => $data['ponto_marcacao_id'] ?? null,
                        'minutos' => $data['minutos'] ?? null,
                        'justificativa' => $data['justificativa'],
                        'anexo' => $data['anexo'] ?? null,
                    ], Auth::user());
                } catch (PontoException $e) {
                    Notification::make()->title('Pedido não enviado')->body($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Pedido enviado ao gestor')->success()->send();
                unset($this->espelho);
            });
    }

    public static function rotuloTipo(TipoAjuste $tipo): string
    {
        return match ($tipo) {
            TipoAjuste::Inclusao => 'Esqueci de marcar (incluir horário)',
            TipoAjuste::Desconsideracao => 'Marcação indevida (desconsiderar)',
            TipoAjuste::Abono => 'Abono (atestado, declaração)',
            default => $tipo->getLabel(),
        };
    }

    /** @return array<int, string> marcações originais do dia, para o pedido de desconsideração */
    private function opcoesMarcacao(?string $dia): array
    {
        if (blank($dia)) {
            return [];
        }

        return app(MarcacoesDoDia::class)->originais($this->colaborador(), Carbon::parse($dia))
            ->mapWithKeys(fn ($marcacao): array => [$marcacao->id => $marcacao->registrada_em->format('H:i')." (NSR {$marcacao->nsr})"])
            ->all();
    }
}
