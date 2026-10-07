<?php

namespace App\Http\Requests\MesaCliente;

use App\Enums\TipoChamadoMesaEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Chamado do celular identificado: chamar o garçom ou pedir a conta. */
class ChamadoMesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in([TipoChamadoMesaEnum::CHAMAR_GARCOM->value, TipoChamadoMesaEnum::PEDIR_CONTA->value])],
        ];
    }

    public function tipo(): TipoChamadoMesaEnum
    {
        return TipoChamadoMesaEnum::from($this->validated('tipo'));
    }
}
