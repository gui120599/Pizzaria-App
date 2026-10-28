<?php

namespace App\Filament\Influenciador\Pages\Auth;

use App\Enums\StatusInfluenciadorEnum;
use App\Filament\Influenciador\Auth\AguardandoAprovacaoResponse;
use App\Models\Influenciador;
use App\Models\RegulamentoInfluenciador;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Events\Registered;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class Register extends BaseRegister
{
    protected function getNameFormComponent(): Component
    {
        return TextInput::make('influenciador_nome')
            ->label('Nome')
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            TextInput::make('influenciador_whatsapp')
                ->label('WhatsApp')
                ->tel()
                ->maxLength(20),
            TextInput::make('influenciador_instagram')
                ->label('Instagram')
                ->maxLength(100),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            // Só aparece quando já existe um regulamento publicado; o aceite grava versão, data e IP.
            Checkbox::make('aceite_regulamento')
                ->label(fn (): HtmlString => new HtmlString('Li e aceito o <a href="'.e(route('influenciador.regulamento')).'" target="_blank" class="underline">regulamento do programa</a>'))
                ->accepted()
                ->visible(fn (): bool => RegulamentoInfluenciador::atual() !== null)
                ->dehydrated(false),
        ]);
    }

    /**
     * Cópia de Filament\Auth\Pages\Register::register() com uma mudança: o
     * influenciador nasce PENDENTE e canAccessPanel() bloqueia o painel, então
     * NÃO autenticamos automaticamente (evita logar uma sessão que bate 403
     * na primeira navegação) — em vez de redirect()->intended(), mandamos
     * para a página pública "cadastro em análise".
     */
    public function register(): ?RegistrationResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        if ($this->isRegisterRateLimited($this->data['email'] ?? '')) {
            return null;
        }

        $influenciador = $this->wrapInDatabaseTransaction(function (): Model {
            $this->callHook('beforeValidate');

            $data = $this->form->getState();

            $this->callHook('afterValidate');

            $data = $this->mutateFormDataBeforeRegister($data);

            $this->callHook('beforeRegister');

            $influenciador = $this->handleRegistration($data);

            $this->form->model($influenciador)->saveRelationships();

            $this->callHook('afterRegister');

            return $influenciador;
        });

        event(new Registered($influenciador));

        return new AguardandoAprovacaoResponse;
    }

    /**
     * `influenciador_status` é `$fillable` (o InfluenciadorResource do painel
     * /admin precisa setá-lo ao aprovar um cadastro), mas o form acima nem
     * declara esse campo — o Filament só aceita no submit o que está no
     * schema, então $data nunca chega com esse valor vindo do autocadastro.
     * Ainda assim, força PENDENTE aqui via forceFill() como defesa extra,
     * em vez de confiar só na ausência do campo no form.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        /** @var Influenciador $influenciador */
        $influenciador = Influenciador::create($data);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::PENDENTE])->save();
        $influenciador->aceitarRegulamento(request()->ip());

        return $influenciador;
    }
}
