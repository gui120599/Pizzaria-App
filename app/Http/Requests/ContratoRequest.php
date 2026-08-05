<?php

namespace App\Http\Requests;

use App\Enums\FormaPagamento;
use App\Enums\StatusContrato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContratoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'favorecido_id' => [
                'required',
                Rule::exists('prestadores', 'id')->where('categoria', 'fornecedor'),
            ],
            'plano_despesa_id' => ['required', Rule::exists('planos_despesas', 'id')],
            'descricao' => ['required', 'string', 'max:255'],
            'numero_documento' => ['nullable', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'min:0.01'],
            'dia_vencimento' => ['required', 'integer', 'between:1,31'],
            'forma_pagamento' => ['nullable', Rule::enum(FormaPagamento::class)],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'status' => ['required', Rule::enum(StatusContrato::class)],
            'observacoes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'favorecido_id.required' => 'Selecione o fornecedor.',
            'favorecido_id.exists' => 'Fornecedor inválido.',
            'plano_despesa_id.required' => 'Selecione a conta do plano de despesas.',
            'plano_despesa_id.exists' => 'Conta de despesa inválida.',
            'descricao.required' => 'Informe a descrição do contrato.',
            'valor.required' => 'Informe o valor mensal.',
            'valor.min' => 'O valor deve ser maior que zero.',
            'dia_vencimento.required' => 'Informe o dia de vencimento.',
            'dia_vencimento.between' => 'O dia de vencimento deve estar entre 1 e 31.',
            'data_inicio.required' => 'Informe a data de início da vigência.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
        ];
    }

    public function attributes(): array
    {
        return [
            'favorecido_id' => 'fornecedor',
            'plano_despesa_id' => 'conta de despesa',
            'numero_documento' => 'número do documento',
            'dia_vencimento' => 'dia de vencimento',
            'forma_pagamento' => 'forma de pagamento',
            'data_inicio' => 'data de início',
            'data_fim' => 'data de fim',
        ];
    }
}
