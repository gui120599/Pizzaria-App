<?php

namespace App\Http\Controllers;

use App\Services\Ponto\QuiosquePonto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/** Pareia o tablet da loja como quiosque de ponto (grava o cookie com o token). */
class PontoQuiosqueController extends Controller
{
    /** Cookie de pareamento: 5 anos, só HTTP. */
    private const VALIDADE_MINUTOS = 60 * 24 * 365 * 5;

    public function parear(string $token, QuiosquePonto $quiosque): RedirectResponse
    {
        abort_unless($quiosque->tokenValido($token), 403, 'Token de quiosque inválido.');

        return redirect()->route('ponto.quiosque')
            ->withCookie(Cookie::make(QuiosquePonto::COOKIE, $token, self::VALIDADE_MINUTOS, httpOnly: true));
    }
}
