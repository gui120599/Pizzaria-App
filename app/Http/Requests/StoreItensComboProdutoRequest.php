<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreItensComboProdutoRequest extends FormRequest
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
            'item_combo_produto_combo_id' => 'required|exists:combo_produtos,id',
            'item_combo_produto_produto_id' => 'required|exists:produtos,id',
            'item_combo_produto_valor_produto' => 'required|numeric|min:0',
            'item_combo_produto_valor_desconto' => 'required|numeric|min:0',
            'item_combo_produto_valor_total' => 'required|numeric|min:0',
        ];
    }
}
