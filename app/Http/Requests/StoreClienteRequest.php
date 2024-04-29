<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClienteRequest extends FormRequest
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
            'cliente_nome' => 'required|string|max:255',
            'cliente_data_nascimento' => $this->tipoPessoaFisica() ? 'nullable|date' : 'nullable|date',
            'cliente_tipo' => 'required|string|max:255',
            'cliente_cpf' => 'nullable|string|max:20', // Pode ser nulo, mas se fornecido, deve ser uma string com no máximo 20 caracteres
            'cliente_rg' => 'nullable|string|max:20',
            'cliente_cnpj' => $this->tipoPessoaJuridica() ? 'required|string' : 'nullable|string',
            'cliente_celular' => 'nullable|string|max:20',
            'cliente_email' => 'nullable|email|max:255',
            'cliente_endereco' => 'nullable|string|max:255',
            'cliente_bairro' => 'nullable|string|max:255',
            'cliente_cidade' => 'nullable|string|max:255',
            'cliente_estado' => 'nullable|string|max:255',
            'cliente_uf_estado' => 'nullable|string|max:2',
            'cliente_cep' => 'nullable|string|max:15',
            'cliente_foto' => 'image|mimes:jpeg,png,jpg,gif|max:2048', // Exemplo de validação para imagem
        ];
    }
    // Método auxiliar para verificar se o tipo de pessoa é física
    private function tipoPessoaFisica(): bool
    {
        return $this->input('cliente_tipo') === 'Física';
    }
    // Método auxiliar para verificar se o tipo de pessoa é Juridica
    private function tipoPessoaJuridica(): bool
    {
        return $this->input('cliente_tipo') === 'Jurídica';
    }
}
