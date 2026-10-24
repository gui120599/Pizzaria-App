<?php

namespace App\Http\Middleware;

use App\Filament\Influenciador\Pages\AceitarRegulamento;
use App\Filament\Influenciador\Pages\Auth\EditProfile;
use App\Models\Influenciador;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Antes de qualquer outra página do painel do influenciador: senha temporária
 * (definida pelo Admin) manda para o perfil trocar a senha; regulamento novo
 * manda para "Aceitar regulamento". Chamadas do Livewire e o logout passam.
 */
class ExigePendenciasDoInfluenciador
{
    public function handle(Request $request, Closure $next): Response
    {
        $influenciador = Filament::auth()->user();

        if (! $influenciador instanceof Influenciador || $this->ehRotaLivre($request)) {
            return $next($request);
        }

        if ($influenciador->influenciador_deve_trocar_senha) {
            if (! $request->routeIs(EditProfile::getRouteName(Filament::getPanel('influenciador')))) {
                return redirect()->to(Filament::getPanel('influenciador')->getProfileUrl());
            }

            return $next($request);
        }

        if ($influenciador->precisaAceitarRegulamento() && ! $request->routeIs(AceitarRegulamento::getRouteName(Filament::getPanel('influenciador')))) {
            return redirect()->to(AceitarRegulamento::getUrl(panel: 'influenciador'));
        }

        return $next($request);
    }

    private function ehRotaLivre(Request $request): bool
    {
        return $request->routeIs('livewire.*') || $request->routeIs('filament.influenciador.auth.logout');
    }
}
