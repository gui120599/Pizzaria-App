<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItensComboProdutoRequest extends FormRequest
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
            'item_combo_produto_combo_id' => 'sometimes|exists:combo_produtos,id',
            'item_combo_produto_produto_id' => 'sometimes|exists:produtos,id',
            'item_combo_produto_valor_produto' => 'sometimes|numeric|min:0',
            'item_combo_produto_valor_desconto' => 'sometimes|numeric|min:0',
            'item_combo_produto_valor_total' => 'sometimes|numeric|min:0',
        ];
    }
}
