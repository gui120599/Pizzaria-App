<?php

namespace App\Livewire\Ponto;

use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Services\Ponto\PontoService;
use App\Services\Ponto\QuiosquePonto;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Quiosque de ponto (tablet da loja, fora dos painéis e sem login): PIN →
 * nome e próxima marcação → confirmar → comprovante. O tablet é conferido
 * pelo cookie de pareamento a cada requisição, não só no mount.
 */
#[Layout('layouts.quiosque')]
#[Title('Ponto')]
class Quiosque extends Component
{
    private const TAMANHO_MAXIMO_PIN = 6;

    public string $pin = '';

    public ?string $erro = null;

    #[Locked]
    public ?int $colaboradorId = null;

    #[Locked]
    public ?int $identificadoEm = null;

    /** @var array{nome: string, tipo: string, nsr: int, data_hora: string, hash: string}|null */
    #[Locked]
    public ?array $comprovante = null;

    public function mount(): void
    {
        $this->garantirTablet();
    }

    public function digitar(string $digito): void
    {
        if (! ctype_digit($digito) || strlen($digito) !== 1 || strlen($this->pin) >= self::TAMANHO_MAXIMO_PIN) {
            return;
        }

        $this->erro = null;
        $this->pin .= $digito;
    }

    public function apagar(): void
    {
        $this->pin = substr($this->pin, 0, -1);
    }

    public function identificar(QuiosquePonto $quiosque): void
    {
        $token = $this->garantirTablet();

        try {
            $colaborador = $quiosque->identificar($this->pin, $token);
            $this->colaboradorId = $colaborador->id;
            $this->identificadoEm = now()->getTimestamp();
            $this->erro = null;
        } catch (PontoException $e) {
            $this->erro = $e->getMessage();
        }

        $this->pin = '';
    }

    public function confirmar(QuiosquePonto $quiosque): void
    {
        $this->garantirTablet();
        $colaborador = $this->colaborador();

        if ($colaborador === null) {
            $this->reiniciar('Tempo esgotado. Digite o PIN de novo.');

            return;
        }

        try {
            $marcacao = $quiosque->registrar($colaborador, request()->ip(), request()->userAgent());
        } catch (PontoException $e) {
            $this->reiniciar($e->getMessage());

            return;
        }

        $this->comprovante = [
            'nome' => $colaborador->nome,
            'tipo' => $marcacao->tipo_sugerido->getLabel(),
            'nsr' => $marcacao->nsr,
            'data_hora' => $marcacao->registrada_em->format('d/m/Y H:i:s'),
            'hash' => substr($marcacao->hash, 0, 8),
        ];
        $this->colaboradorId = null;
        $this->identificadoEm = null;
    }

    public function reiniciar(?string $erro = null): void
    {
        $this->pin = '';
        $this->colaboradorId = null;
        $this->identificadoEm = null;
        $this->comprovante = null;
        $this->erro = $erro;
    }

    public function render(PontoService $ponto): View
    {
        $colaborador = $this->colaborador();

        return view('livewire.ponto.quiosque', [
            'colaborador' => $colaborador,
            'proximoTipo' => $colaborador === null ? null : $ponto->proximoTipoEsperado($colaborador),
            'agoraMs' => now()->getTimestampMs(),
            'tamanhoPin' => self::TAMANHO_MAXIMO_PIN,
        ]);
    }

    /** Identificação vale por alguns segundos: depois disso, PIN de novo. */
    private function colaborador(): ?Colaborador
    {
        if ($this->colaboradorId === null || $this->identificadoEm === null) {
            return null;
        }

        if (now()->getTimestamp() - $this->identificadoEm > (int) config('ponto.quiosque.expira_identificacao_s', 30)) {
            return null;
        }

        return Colaborador::query()->ativos()->find($this->colaboradorId);
    }

    private function garantirTablet(): string
    {
        $token = (string) request()->cookie(QuiosquePonto::COOKIE);

        abort_unless(app(QuiosquePonto::class)->tokenValido($token), 403, 'Este aparelho não está autorizado como quiosque de ponto.');

        return $token;
    }
}
