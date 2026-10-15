<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\Ponto\OrigemMarcacao;
use App\Exceptions\PontoException;
use App\Filament\Garcom\Concerns\DoColaborador;
use App\Services\Ponto\MarcacoesDoDia;
use App\Services\Ponto\PontoService;
use App\Support\DiaOperacional;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Bater ponto pelo celular (spec §7): hora do servidor, geolocalização
 * capturada no aparelho (sem permissão → registra sem coordenadas) e selfie
 * opcional comprimida no navegador antes do upload.
 */
class BaterPonto extends Page
{
    use DoColaborador;
    use WithFileUploads;

    protected string $view = 'filament.garcom.pages.bater-ponto';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $navigationLabel = 'Bater ponto';

    protected static ?string $title = 'Bater ponto';

    protected static ?string $slug = 'ponto';

    protected static ?int $navigationSort = -10;

    /** Selfie já comprimida pelo navegador. */
    public ?TemporaryUploadedFile $selfie = null;

    /** @var array{tipo: string, nsr: int, data_hora: string, hash: string, sem_localizacao: bool, fora_da_cerca: bool}|null */
    #[Locked]
    public ?array $comprovante = null;

    /**
     * @param  float|null  $latitude  nulos quando o aparelho negou ou não respondeu a localização
     */
    public function bater(?float $latitude = null, ?float $longitude = null, ?int $precisao = null): void
    {
        $this->validate(['selfie' => ['nullable', 'image', 'max:4096']]);

        try {
            $marcacao = app(PontoService::class)->registrarMarcacao($this->colaborador(), OrigemMarcacao::App, [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'precisao_m' => $precisao,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'foto' => $this->selfie,
            ], auth()->user());
        } catch (PontoException $e) {
            Notification::make()->title('Ponto não registrado')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->selfie = null;
        $this->comprovante = [
            'tipo' => $marcacao->tipo_sugerido->getLabel(),
            'nsr' => $marcacao->nsr,
            'data_hora' => $marcacao->registrada_em->format('d/m/Y H:i:s'),
            'hash' => substr($marcacao->hash, 0, 8),
            'sem_localizacao' => $latitude === null,
            'fora_da_cerca' => $marcacao->dentro_da_cerca === false,
        ];
    }

    public function fecharComprovante(): void
    {
        $this->comprovante = null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $colaborador = $this->colaborador();
        $hoje = DiaOperacional::de();

        return [
            'colaborador' => $colaborador,
            'proximoTipo' => app(PontoService::class)->proximoTipoEsperado($colaborador),
            'marcacoesHoje' => app(MarcacoesDoDia::class)->efetivas($colaborador, $hoje),
            'hoje' => $hoje,
            'semEscala' => $colaborador->escalaVigente($hoje) === null,
            'agoraMs' => now()->getTimestampMs(),
            'cercaConfigurada' => filled(config('ponto.cerca.lat')) && filled(config('ponto.cerca.lng')),
            'exigeSelfie' => (bool) config('ponto.exigir_selfie'),
        ];
    }
}
