<?php

namespace App\Filament\Influenciador\Auth;

use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class AguardandoAprovacaoResponse implements RegistrationResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->route('influenciador.aguardando-aprovacao');
    }
}
