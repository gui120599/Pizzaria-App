<?php

namespace App\Http\Controllers\Cozinha;

use App\Exceptions\ProducaoException;
use App\Filament\Cozinha\Pages\Estacao;
use App\Http\Controllers\Controller;
use App\Http\Middleware\IdentificaDispositivoCozinha;
use App\Services\Cozinha\PareamentoDispositivoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

/**
 * O tablet abre /cozinha/parear/{token} lendo o QR do admin: consome o token
 * (uso único), entra na conta técnica da estação e grava o cookie do
 * dispositivo por um ano.
 */
class PareamentoController extends Controller
{
    private const COOKIE_MINUTOS = 60 * 24 * 365;

    public function __invoke(Request $request, string $token, PareamentoDispositivoService $pareamento): RedirectResponse|View
    {
        try {
            $dispositivo = $pareamento->parear($token, $request->ip());
        } catch (ProducaoException $e) {
            return view('cozinha.pareamento-falhou', ['mensagem' => $e->getMessage()]);
        }

        Auth::login($dispositivo->usuario, remember: true);
        $request->session()->regenerate();

        Cookie::queue(Cookie::make(
            IdentificaDispositivoCozinha::COOKIE,
            IdentificaDispositivoCozinha::valorDoCookie($dispositivo),
            self::COOKIE_MINUTOS,
        ));

        return redirect()->to(Estacao::getUrl(panel: 'cozinha'));
    }
}
