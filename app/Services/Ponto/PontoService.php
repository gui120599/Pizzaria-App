<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\MotivoAjuste;
use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\OrigemMarcacao;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\TipoAjuste;
use App\Enums\Ponto\TipoMarcacao;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\PontoAjuste;
use App\Models\PontoMarcacao;
use App\Models\PontoSequencia;
use App\Models\User;
use App\Notifications\Ponto\AjusteDecididoNotification;
use App\Notifications\Ponto\AjusteSolicitadoNotification;
use App\Notifications\Ponto\MarcacaoEmPeriodoFechadoNotification;
use App\Support\DiaOperacional;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bater ponto e o ciclo de ajustes solicitados pelo colaborador.
 *
 * Marcação: hora sempre do servidor; NSR e hash encadeado gerados com a
 * linha 'marcacoes' de ponto_sequencias travada (lockForUpdate), o que
 * também serializa a checagem de marcação duplicada. A apuração do dia roda
 * na hora, de forma síncrona (barata, e não há worker de fila garantido).
 */
class PontoService
{
    public function __construct(
        private readonly ApuracaoService $apuracao,
        private readonly MarcacoesDoDia $marcacoes,
        private readonly PeriodosPonto $periodos,
        private readonly PermissoesPonto $permissoes,
    ) {}

    /**
     * @param  array{latitude?: float|string|null, longitude?: float|string|null, precisao_m?: int|null, ip?: string|null, user_agent?: string|null, foto?: UploadedFile|null}  $contexto
     *
     * @throws PontoException
     */
    public function registrarMarcacao(Colaborador $colaborador, OrigemMarcacao $origem, array $contexto = [], ?User $registradaPor = null): PontoMarcacao
    {
        $agora = CarbonImmutable::now()->startOfSecond();
        $dia = DiaOperacional::de($agora);

        if (! $colaborador->estaAtivoEm($agora)) {
            throw new PontoException('Colaborador desligado (ou ainda não admitido) não pode marcar ponto.');
        }

        if ($colaborador->escalaVigente($dia) === null) {
            throw new PontoException('Colaborador sem escala vigente não pode marcar ponto. Fale com o gerente.');
        }

        $latitude = $contexto['latitude'] ?? null;
        $longitude = $contexto['longitude'] ?? null;
        $dentroDaCerca = $this->avaliarCerca($latitude, $longitude);

        if ($dentroDaCerca === false && config('ponto.cerca.bloquear_fora')) {
            throw new PontoException('Você está fora da área da loja. A marcação não foi registrada.');
        }

        $foto = $contexto['foto'] ?? null;

        if ($foto === null && config('ponto.exigir_selfie')) {
            throw new PontoException('Tire a selfie para registrar o ponto.');
        }

        $fotoCaminho = $foto?->store('ponto/selfies/'.$agora->format('Y/m'), (string) config('ponto.disco'));

        try {
            $marcacao = DB::transaction(function () use ($colaborador, $origem, $contexto, $registradaPor, $agora, $dia, $latitude, $longitude, $dentroDaCerca, $fotoCaminho): PontoMarcacao {
                $sequencia = PontoSequencia::query()->where('nome', PontoSequencia::MARCACOES)->lockForUpdate()->firstOrFail();

                $ultima = PontoMarcacao::query()
                    ->where('colaborador_id', $colaborador->id)
                    ->orderByDesc('registrada_em')
                    ->first();
                $intervaloMinimo = (int) config('ponto.intervalo_minimo_duplicada_s', 60);

                if ($ultima !== null && abs($agora->getTimestamp() - $ultima->registrada_em->getTimestamp()) < $intervaloMinimo) {
                    throw new PontoException("Marcação duplicada: a anterior foi às {$ultima->registrada_em->format('H:i:s')}. Aguarde {$intervaloMinimo} segundos.");
                }

                $marcacao = new PontoMarcacao([
                    'nsr' => $sequencia->ultimo_nsr + 1,
                    'colaborador_id' => $colaborador->id,
                    'registrada_em' => $agora,
                    'data_referencia' => $dia->toDateString(),
                    'tipo_sugerido' => TipoMarcacao::sugerido($this->marcacoes->efetivas($colaborador, $dia)->count()),
                    'origem' => $origem,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'precisao_m' => $contexto['precisao_m'] ?? null,
                    'dentro_da_cerca' => $dentroDaCerca,
                    'ip' => $contexto['ip'] ?? null,
                    'user_agent' => isset($contexto['user_agent']) ? mb_substr((string) $contexto['user_agent'], 0, 255) : null,
                    'foto_caminho' => $fotoCaminho,
                    'hash_anterior' => $sequencia->ultimo_hash,
                    'registrada_por' => $registradaPor?->id,
                ]);
                $marcacao->hash = PontoMarcacao::calcularHash($marcacao);
                $marcacao->save();

                $sequencia->update(['ultimo_nsr' => $marcacao->nsr, 'ultimo_hash' => $marcacao->hash]);

                return $marcacao;
            });
        } catch (Throwable $e) {
            if ($fotoCaminho !== null) {
                Storage::disk((string) config('ponto.disco'))->delete($fotoCaminho);
            }

            throw $e;
        }

        $periodo = $this->periodos->periodoDe($colaborador, $dia);

        if ($periodo?->estaTravado()) {
            // É fato e fica gravada, mas não muda o período travado: o gestor decide.
            Notification::send(GestoresPonto::destinatarios(), new MarcacaoEmPeriodoFechadoNotification($marcacao, $periodo));
        } else {
            $this->apuracao->apurarDia($colaborador, $dia);
        }

        return $marcacao;
    }

    /** Rótulo da próxima marcação esperada no dia operacional atual (só sugestão). */
    public function proximoTipoEsperado(Colaborador $colaborador, ?CarbonInterface $agora = null): TipoMarcacao
    {
        return TipoMarcacao::sugerido($this->marcacoes->efetivas($colaborador, DiaOperacional::de($agora))->count());
    }

    /**
     * Solicitação do próprio colaborador; fica pendente até um gestor decidir.
     *
     * @param  array{data_referencia: CarbonInterface|string, data_hora?: CarbonInterface|string|null, ponto_marcacao_id?: int|null, minutos?: int|null, justificativa: string, anexo?: UploadedFile|null}  $dados
     *
     * @throws PontoException
     */
    public function solicitarAjuste(Colaborador $colaborador, TipoAjuste $tipo, MotivoAjuste $motivo, array $dados, User $solicitante): PontoAjuste
    {
        if ($colaborador->user_id !== $solicitante->id) {
            throw new PontoException('Só o próprio colaborador solicita ajuste do seu ponto. Gestores corrigem direto no espelho.');
        }

        $dia = CarbonImmutable::parse($dados['data_referencia'])->startOfDay();
        $justificativa = trim((string) ($dados['justificativa'] ?? ''));

        if ($justificativa === '') {
            throw new PontoException('Informe a justificativa do ajuste.');
        }

        $this->periodos->garantirAberto($colaborador, $dia);

        $atributos = $this->validarDadosDoTipo($colaborador, $tipo, $dia, $dados);
        $anexo = isset($dados['anexo']) && $dados['anexo'] instanceof UploadedFile
            ? $dados['anexo']->store('ponto/anexos/'.$dia->format('Y/m'), (string) config('ponto.disco'))
            : null;

        $ajuste = PontoAjuste::create([
            ...$atributos,
            'colaborador_id' => $colaborador->id,
            'data_referencia' => $dia->toDateString(),
            'tipo' => $tipo,
            'motivo' => $motivo,
            'justificativa' => $justificativa,
            'anexo_caminho' => $anexo,
            'status' => StatusAjuste::Pendente,
            'origem' => OrigemAjuste::Colaborador,
            'solicitado_por' => $solicitante->id,
        ]);

        Notification::send(
            GestoresPonto::destinatarios()->reject(fn (User $gestor): bool => $gestor->id === $solicitante->id),
            new AjusteSolicitadoNotification($ajuste),
        );

        return $ajuste;
    }

    /** @throws PontoException */
    public function aprovarAjuste(PontoAjuste $ajuste, User $gestor): PontoAjuste
    {
        $ajuste = DB::transaction(function () use ($ajuste, $gestor): PontoAjuste {
            $ajuste = PontoAjuste::query()->whereKey($ajuste->id)->lockForUpdate()->firstOrFail();
            $colaborador = $ajuste->colaborador;

            $this->garantirPendente($ajuste);
            $this->permissoes->garantirAprovacao($gestor, $colaborador);
            $this->periodos->garantirAberto($colaborador, $ajuste->data_referencia);

            if ($ajuste->tipo === TipoAjuste::Inclusao) {
                $this->garantirSemMarcacaoProxima($colaborador, $ajuste->data_referencia, CarbonImmutable::instance($ajuste->data_hora));
            }

            $ajuste->update([
                'status' => StatusAjuste::Aprovado,
                'aprovado_por' => $gestor->id,
                'decidido_em' => now(),
            ]);

            return $ajuste;
        });

        $this->apuracao->apurarDia($ajuste->colaborador, $ajuste->data_referencia);
        $this->notificarDecisao($ajuste);

        return $ajuste;
    }

    /** @throws PontoException */
    public function rejeitarAjuste(PontoAjuste $ajuste, User $gestor, string $motivoRejeicao): PontoAjuste
    {
        if (trim($motivoRejeicao) === '') {
            throw new PontoException('Informe o motivo da rejeição.');
        }

        $ajuste = DB::transaction(function () use ($ajuste, $gestor, $motivoRejeicao): PontoAjuste {
            $ajuste = PontoAjuste::query()->whereKey($ajuste->id)->lockForUpdate()->firstOrFail();

            $this->garantirPendente($ajuste);
            $this->permissoes->garantirAprovacao($gestor, $ajuste->colaborador);

            $ajuste->update([
                'status' => StatusAjuste::Rejeitado,
                'aprovado_por' => $gestor->id,
                'decidido_em' => now(),
                'motivo_rejeicao' => trim($motivoRejeicao),
            ]);

            return $ajuste;
        });

        $this->notificarDecisao($ajuste);

        return $ajuste;
    }

    /**
     * Valida os campos exigidos por tipo e devolve os atributos do ajuste.
     * Inclusão exige horário dentro do dia operacional e não futuro;
     * desconsideração exige uma marcação original do dia; abono aceita
     * minutos (nulo = dia inteiro).
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     *
     * @throws PontoException
     */
    public function validarDadosDoTipo(Colaborador $colaborador, TipoAjuste $tipo, CarbonImmutable $dia, array $dados): array
    {
        return match ($tipo) {
            TipoAjuste::Inclusao => ['data_hora' => $this->validarHorario($colaborador, $dia, $dados['data_hora'] ?? null)],
            TipoAjuste::Desconsideracao => ['ponto_marcacao_id' => $this->validarMarcacaoDoDia($colaborador, $dia, $dados['ponto_marcacao_id'] ?? null)->id],
            TipoAjuste::Abono => ['minutos' => $this->validarMinutos($dados['minutos'] ?? null)],
            TipoAjuste::FolgaCompensatoria, TipoAjuste::FaltaJustificada => [],
        };
    }

    /** @throws PontoException */
    public function validarHorario(Colaborador $colaborador, CarbonImmutable $dia, CarbonInterface|string|null $dataHora): CarbonImmutable
    {
        if ($dataHora === null || $dataHora === '') {
            throw new PontoException('Informe o horário da marcação.');
        }

        $momento = CarbonImmutable::parse($dataHora)->startOfMinute();

        if (! DiaOperacional::contem($dia, $momento)) {
            throw new PontoException("O horário {$momento->format('d/m H:i')} não pertence ao dia {$dia->format('d/m/Y')} (o dia vira às ".config('operacao.virada_dia').').');
        }

        if ($momento->isFuture()) {
            throw new PontoException('Não é possível incluir marcação no futuro.');
        }

        return $momento;
    }

    /**
     * Duas marcações efetivas com menos de 1 minuto de diferença não podem coexistir.
     *
     * @throws PontoException
     */
    public function garantirSemMarcacaoProxima(Colaborador $colaborador, CarbonInterface $dia, CarbonImmutable $momento, ?int $ignorarMarcacaoId = null): void
    {
        $conflito = $this->marcacoes->efetivas($colaborador, $dia)
            ->reject(fn (MarcacaoEfetiva $efetiva): bool => $ignorarMarcacaoId !== null && $efetiva->marcacao?->id === $ignorarMarcacaoId)
            ->first(fn (MarcacaoEfetiva $efetiva): bool => abs($efetiva->momento->getTimestamp() - $momento->getTimestamp()) < 60);

        if ($conflito !== null) {
            throw new PontoException("Já existe marcação às {$conflito->momento->format('H:i')} — menos de 1 minuto do horário informado.");
        }
    }

    /** True/false se a cerca estiver configurada e houver coordenadas; null caso contrário. */
    public function avaliarCerca(float|string|null $latitude, float|string|null $longitude): ?bool
    {
        $centroLat = config('ponto.cerca.lat');
        $centroLng = config('ponto.cerca.lng');

        if ($latitude === null || $longitude === null || $centroLat === null || $centroLng === null) {
            return null;
        }

        return self::distanciaMetros((float) $latitude, (float) $longitude, (float) $centroLat, (float) $centroLng)
            <= (int) config('ponto.cerca.raio_m', 150);
    }

    /** Haversine. */
    private static function distanciaMetros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $raioTerra = 6_371_000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $raioTerra * asin(min(1, sqrt($a)));
    }

    /** @throws PontoException */
    private function validarMarcacaoDoDia(Colaborador $colaborador, CarbonImmutable $dia, mixed $marcacaoId): PontoMarcacao
    {
        $marcacao = $marcacaoId === null ? null : PontoMarcacao::query()
            ->whereKey($marcacaoId)
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('data_referencia', $dia->toDateString())
            ->first();

        if ($marcacao === null) {
            throw new PontoException('Escolha uma marcação deste colaborador neste dia.');
        }

        return $marcacao;
    }

    /** @throws PontoException */
    private function validarMinutos(mixed $minutos): ?int
    {
        if ($minutos === null || $minutos === '') {
            return null;
        }

        if (! is_numeric($minutos) || (int) $minutos <= 0 || (int) $minutos > 24 * 60) {
            throw new PontoException('Informe os minutos abonados (entre 1 e 1440) ou deixe em branco para o dia inteiro.');
        }

        return (int) $minutos;
    }

    /** @throws PontoException */
    private function garantirPendente(PontoAjuste $ajuste): void
    {
        if ($ajuste->status !== StatusAjuste::Pendente) {
            throw new PontoException("Este ajuste não está mais pendente ({$ajuste->status->getLabel()}).");
        }
    }

    private function notificarDecisao(PontoAjuste $ajuste): void
    {
        $usuario = $ajuste->colaborador->user;

        if ($usuario !== null) {
            $usuario->notify(new AjusteDecididoNotification($ajuste));
        }
    }
}
