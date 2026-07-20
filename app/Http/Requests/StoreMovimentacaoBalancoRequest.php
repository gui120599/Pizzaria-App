<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMovimentacaoBalancoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'produto_id' => ['required', 'integer', 'exists:produtos,id'],
            'quantidade_balanco' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'produto_id.required' => 'O produto é obrigatório.',
            'produto_id.exists' => 'Produto não encontrado.',
            'quantidade_balanco.required' => 'A quantidade física é obrigatória.',
            'quantidade_balanco.numeric' => 'A quantidade deve ser um número.',
            'quantidade_balanco.min' => 'A quantidade não pode ser negativa.',
            'quantidade_balanco.decimal' => 'A quantidade aceita no máximo 3 casas decimais.',
            'observacao.max' => 'A observação não pode ultrapassar 500 caracteres.',
        ];
    }

    public function attributes(): array
    {
        return [
            'produto_id' => 'produto',
            'quantidade_balanco' => 'quantidade física',
            'observacao' => 'observação',
        ];
    }
}
