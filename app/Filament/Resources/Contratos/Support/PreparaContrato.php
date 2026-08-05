<?php

namespace App\Filament\Resources\Contratos\Support;

use App\Http\Requests\ContratoRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Valida os dados do formulário contra o ContratoRequest (regras canônicas,
 * reaproveitáveis fora do Filament) antes de criar/editar — mesmo padrão de
 * App\Filament\Resources\Lancamentos\Support\PreparaLancamento.
 */
trait PreparaContrato
{
    protected function validarContrato(array $data): array
    {
        $request = new ContratoRequest;
        $validator = Validator::make($data, $request->rules(), $request->messages(), $request->attributes());

        if ($validator->fails()) {
            $erros = [];

            foreach ($validator->errors()->messages() as $campo => $mensagens) {
                $erros['data.'.$campo] = $mensagens;
            }

            throw ValidationException::withMessages($erros);
        }

        return $data;
    }
}
