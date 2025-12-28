<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMovimentacoesSessaoCaixaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mov_sessaocaixa_id' => 'required|exists:sessao_caixas,id',
            'mov_descricao' => 'nullable|string|max:255',
            'mov_observacoes' => 'nullable|string',
        ];
    }

    public function messages()
    {
        return [
            'mov_sessaocaixa_id.required' => 'O campo sessão de caixa é obrigatório.',
            'mov_sessaocaixa_id.exists' => 'A sessão de caixa selecionada não é válida.',
        ];
    }
}
