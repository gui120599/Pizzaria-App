<?php

namespace App\Http\Requests;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LancamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoLancamento::class)],

            // Regra de integridade tipo↔plano:
            // pagar  => exige plano de despesas e proíbe plano de receitas;
            // receber => exige plano de receitas e proíbe plano de despesas.
            'plano_despesa_id' => [
                'nullable',
                'required_if:tipo,'.TipoLancamento::Pagar->value,
                'prohibited_unless:tipo,'.TipoLancamento::Pagar->value,
                Rule::exists('planos_despesas', 'id'),
            ],
            'plano_receita_id' => [
                'nullable',
                'required_if:tipo,'.TipoLancamento::Receber->value,
                'prohibited_unless:tipo,'.TipoLancamento::Receber->value,
                Rule::exists('planos_receitas', 'id'),
            ],

            'descricao' => ['required', 'string', 'max:255'],

            // Favorecido segue a mesma direção do tipo: fornecedor no pagar, cliente no receber.
            'favorecido_id' => [
                'nullable',
                'prohibited_unless:tipo,'.TipoLancamento::Pagar->value,
                Rule::exists('prestadores', 'id')->where('categoria', 'fornecedor'),
            ],
            'cliente_id' => [
                'nullable',
                'prohibited_unless:tipo,'.TipoLancamento::Receber->value,
                Rule::exists('clientes', 'id'),
            ],

            'numero_documento' => ['nullable', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'min:0'],
            'vencimento' => ['required', 'date'],
            'data_pagamento' => ['nullable', 'date'],
            'status' => ['required', Rule::enum(StatusLancamento::class)],
            'forma_pagamento' => ['nullable', Rule::enum(FormaPagamento::class)],
            'observacoes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'Selecione o tipo do lançamento.',

            'plano_despesa_id.required_if' => 'Selecione a conta do plano de despesas para um lançamento a pagar.',
            'plano_despesa_id.prohibited_unless' => 'A conta de despesa só é permitida em lançamentos a pagar.',
            'plano_despesa_id.exists' => 'Conta de despesa inválida.',

            'plano_receita_id.required_if' => 'Selecione a conta do plano de receitas para um lançamento a receber.',
            'plano_receita_id.prohibited_unless' => 'A conta de receita só é permitida em lançamentos a receber.',
            'plano_receita_id.exists' => 'Conta de receita inválida.',

            'favorecido_id.prohibited_unless' => 'O fornecedor só é permitido em lançamentos a pagar.',
            'favorecido_id.exists' => 'Fornecedor inválido.',
            'cliente_id.prohibited_unless' => 'O cliente só é permitido em lançamentos a receber.',
            'cliente_id.exists' => 'Cliente inválido.',

            'descricao.required' => 'Informe a descrição do lançamento.',
            'valor.required' => 'Informe o valor.',
            'valor.numeric' => 'O valor deve ser numérico.',
            'valor.min' => 'O valor não pode ser negativo.',
            'vencimento.required' => 'Informe a data de vencimento.',
            'vencimento.date' => 'Data de vencimento inválida.',
            'data_pagamento.date' => 'Data de pagamento inválida.',
        ];
    }

    public function attributes(): array
    {
        return [
            'plano_despesa_id' => 'conta de despesa',
            'plano_receita_id' => 'conta de receita',
            'favorecido_id' => 'fornecedor',
            'cliente_id' => 'cliente',
            'numero_documento' => 'número do documento',
            'data_pagamento' => 'data de pagamento',
            'forma_pagamento' => 'forma de pagamento',
        ];
    }
}
