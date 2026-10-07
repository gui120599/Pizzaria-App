<?php

namespace App\Http\Requests\MesaCliente;

use Illuminate\Foundation\Http\FormRequest;

/** Identificação do celular na mesa: nome e celular com DDD (sem verificação por SMS). */
class EntrarMesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:120'],
            'celular' => ['required', 'string', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe seu nome.',
            'celular.required' => 'Informe o celular com DDD.',
        ];
    }
}
