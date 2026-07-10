<?php

namespace App\Filament\Resources\Lancamentos\Support;

use App\Enums\TipoLancamento;
use App\Http\Requests\LancamentoRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait PreparaLancamento
{
    /**
     * Normaliza o par tipo↔plano e valida os dados usando o LancamentoRequest
     * (regras canônicas), antes de persistir na criação/edição.
     */
    protected function prepararDados(array $data): array
    {
        // Normaliza o tipo para o valor escalar (required_if/prohibited comparam com string).
        $tipo = $data['tipo'] ?? null;

        if ($tipo instanceof TipoLancamento) {
            $tipo = $tipo->value;
            $data['tipo'] = $tipo;
        }

        // Mantém o par tipo↔plano consistente: zera o plano não aplicável ao tipo
        // (inclusive ao trocar o tipo numa edição). O snapshot do comportamento fica
        // por conta do evento saving do model quando for despesa.
        if ($tipo === TipoLancamento::Pagar->value) {
            $data['plano_receita_id'] = null;
            $data['cliente_id'] = null;
        } elseif ($tipo === TipoLancamento::Receber->value) {
            $data['plano_despesa_id'] = null;
            $data['comportamento'] = null;
            $data['favorecido_id'] = null;
        }

        // Validação canônica via Form Request.
        $request = new LancamentoRequest;
        $validator = Validator::make($data, $request->rules(), $request->messages(), $request->attributes());

        if ($validator->fails()) {
            // Reindexa as chaves para o statePath do formulário Filament (data.*),
            // exibindo cada erro no respectivo campo.
            $erros = [];

            foreach ($validator->errors()->messages() as $campo => $mensagens) {
                $erros['data.'.$campo] = $mensagens;
            }

            throw ValidationException::withMessages($erros);
        }

        return $data;
    }
}
