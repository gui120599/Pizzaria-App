<?php

namespace App\Mcp\Tools\Compras\Avulsa;

use App\Mcp\Tools\Compras\FerramentaCompras;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Base das tools de compra avulsa: só aparecem (shouldRegister) e só rodam
 * para quem passa no Gate compras.avulsa — nunca para o papel Agente.
 */
abstract class FerramentaCompraAvulsa extends FerramentaCompras
{
    public function shouldRegister(): bool
    {
        return self::permitido(auth()->user());
    }

    public function handle(Request $request): Response
    {
        if (! self::permitido($request->user())) {
            return Response::error('Acesso negado: só quem pode lançar compra avulsa usa esta ferramenta.');
        }

        try {
            return parent::handle($request);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }
    }

    protected function usuario(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private static function permitido(mixed $usuario): bool
    {
        return $usuario instanceof User && ! $usuario->hasRole('Agente') && Gate::forUser($usuario)->allows('compras.avulsa');
    }
}
