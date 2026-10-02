<?php

namespace App\Filament\Garcom\Pages\Auth;

use App\Exceptions\AutorizacaoNegadaException;
use App\Models\User;
use App\Services\Garcom\PinService;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Computed;

/**
 * Login do Painel do Garçom: PIN com escolha rápida do usuário (aparelho
 * compartilhado do salão) ou e-mail/senha (aparelho próprio).
 */
class LoginGarcom extends Login
{
    public string $modo = 'pin';

    public ?int $pinUsuarioId = null;

    public string $pin = '';

    public function mount(): void
    {
        parent::mount();

        if ($this->usuariosComPin === []) {
            $this->modo = 'senha';
        }
    }

    /**
     * Usuários que podem entrar no salão e já têm PIN cadastrado.
     *
     * @return array<int, array{id: int, nome: string, avatar: ?string}>
     */
    #[Computed]
    public function usuariosComPin(): array
    {
        return User::query()
            ->whereNotNull('user_pin')
            ->role(User::GARCOM_PANEL_ROLES)
            ->orderBy('name_first')
            ->get(['id', 'name', 'name_first', 'avatar'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'nome' => $user->name_first ?: $user->name,
                'avatar' => $user->getFilamentAvatarUrl(),
            ])
            ->all();
    }

    public function usarModo(string $modo): void
    {
        $this->modo = $modo === 'senha' ? 'senha' : 'pin';
        $this->reset('pinUsuarioId', 'pin');
    }

    public function escolherUsuario(int $id): void
    {
        $this->pinUsuarioId = $id;
        $this->pin = '';
    }

    public function entrarComPin(): ?LoginResponse
    {
        $user = User::find($this->pinUsuarioId);

        if (! $user || ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            $this->addError('pin', 'Usuário sem acesso ao salão.');

            return null;
        }

        try {
            app(PinService::class)->verificar($user, $this->pin);
        } catch (AutorizacaoNegadaException $e) {
            $this->pin = '';
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }

        Filament::auth()->login($user);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.garcom.auth.login-pin')
                ->visible(fn (): bool => $this->modo === 'pin'),
            $this->getFormContentComponent(),
            View::make('filament.garcom.auth.trocar-para-pin')
                ->visible(fn (): bool => $this->modo === 'senha' && $this->usuariosComPin !== []),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return parent::getFormContentComponent()
            ->visible(fn (): bool => $this->modo === 'senha');
    }

    public function getHeading(): string
    {
        return $this->modo === 'pin' ? 'Quem está atendendo?' : 'Entrar no salão';
    }
}
