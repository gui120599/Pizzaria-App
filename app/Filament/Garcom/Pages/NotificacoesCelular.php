<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\EventoPushGarcomEnum;
use App\Exceptions\PushException;
use App\Filament\Garcom\Concerns\RestritoAoSalao;
use App\Models\DispositivoPush;
use App\Models\User;
use App\Services\Push\DispositivoPushService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Ativação do push neste aparelho: status, ativar, testar, pausar, desativar
 * e escolher quais avisos receber. A permissão do navegador só é pedida no
 * toque em "Ativar" (resources/js/garcom-push.js).
 */
class NotificacoesCelular extends Page
{
    use RestritoAoSalao {
        canAccess as private canAccessSalao;
    }

    protected string $view = 'filament.garcom.pages.notificacoes-celular';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'Notificações no celular';

    protected static ?string $title = 'Notificações no celular';

    protected static ?string $slug = 'notificacoes';

    protected static bool $shouldRegisterNavigation = false;

    /** Este aparelho está inscrito e ativo para o usuário atual. */
    #[Locked]
    public bool $servidorAtivo = false;

    /** Pausa em andamento (hh:mm), se houver. */
    #[Locked]
    public ?string $pausadoAte = null;

    /**
     * Avisos ligados por evento (chave = EventoPushGarcomEnum).
     *
     * @var array<string, bool>
     */
    public array $preferencias = [];

    public static function canAccess(): bool
    {
        return DispositivoPushService::ativo() && static::canAccessSalao();
    }

    public function mount(): void
    {
        $this->carregarEstado();
    }

    /**
     * @param  array<string, mixed>  $inscricao  PushSubscription.toJSON()
     */
    public function registrar(array $inscricao, bool $instalado, DispositivoPushService $dispositivos): void
    {
        try {
            $dispositivos->registrar($this->usuario(), $inscricao, request()->userAgent(), $instalado);
        } catch (PushException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->carregarEstado();
    }

    public function testar(DispositivoPushService $dispositivos): void
    {
        $dispositivo = $dispositivos->daSessao($this->usuario());

        if ($dispositivo === null || ! $dispositivo->dp_ativo) {
            Notification::make()->warning()->title('Ative as notificações neste aparelho primeiro.')->send();

            return;
        }

        try {
            $envio = $dispositivos->enviarTeste($this->usuario(), $dispositivo);
        } catch (PushException $e) {
            Notification::make()->warning()->title($e->getMessage())->send();

            return;
        }

        $this->carregarEstado();

        if ($envio->pe_status === 'ENVIADO') {
            Notification::make()->success()->title('Teste enviado')->body('Deve chegar em alguns segundos.')->send();
        } else {
            Notification::make()->danger()->title('Não foi possível enviar o teste')->body($envio->pe_erro ?: 'O serviço de notificações não respondeu.')->send();
        }
    }

    public function desativar(DispositivoPushService $dispositivos): void
    {
        $dispositivo = $dispositivos->daSessao($this->usuario());

        if ($dispositivo !== null) {
            $dispositivos->desativar($dispositivo, 'usuario');
        }

        $this->carregarEstado();
    }

    public function pausar(int $horas, DispositivoPushService $dispositivos): void
    {
        $dispositivo = $dispositivos->daSessao($this->usuario());

        if ($dispositivo !== null) {
            $dispositivos->pausar($dispositivo, now()->addHours(max(1, min($horas, 24))));
        }

        $this->carregarEstado();
    }

    public function retomar(DispositivoPushService $dispositivos): void
    {
        $dispositivo = $dispositivos->daSessao($this->usuario());

        if ($dispositivo !== null) {
            $dispositivos->retomar($dispositivo);
        }

        $this->carregarEstado();
    }

    public function updatedPreferencias(): void
    {
        $permitidos = array_map(fn (EventoPushGarcomEnum $e): string => $e->value, EventoPushGarcomEnum::configuraveis());
        $salvas = [];

        foreach ($permitidos as $evento) {
            $salvas[$evento] = (bool) ($this->preferencias[$evento] ?? true);
        }

        $this->usuario()->forceFill(['user_push_preferencias' => $salvas])->save();
    }

    /**
     * @return list<EventoPushGarcomEnum>
     */
    public function eventos(): array
    {
        return EventoPushGarcomEnum::configuraveis();
    }

    public function chavePublica(): string
    {
        return (string) config('pizzaria.push.vapid.publica');
    }

    private function usuario(): User
    {
        /** @var User $usuario */
        $usuario = Auth::user();

        return $usuario;
    }

    private function carregarEstado(): void
    {
        $usuario = $this->usuario()->fresh();
        $dispositivo = app(DispositivoPushService::class)->daSessao($usuario);

        $this->servidorAtivo = $dispositivo instanceof DispositivoPush && $dispositivo->dp_ativo;
        $this->pausadoAte = $dispositivo?->estaPausado() ? $dispositivo->dp_pausado_ate->format('H:i') : null;

        $salvas = $usuario->user_push_preferencias ?? [];
        $this->preferencias = [];

        foreach ($this->eventos() as $evento) {
            $this->preferencias[$evento->value] = (bool) ($salvas[$evento->value] ?? true);
        }
    }
}
