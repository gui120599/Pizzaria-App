<?php

namespace App\Filament\Resources\Lancamentos\Support;

use App\Enums\TipoLancamento;
use App\Http\Requests\LancamentoRequest;
use App\Models\Lancamento;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait PreparaLancamento
{
    /**
     * Normaliza o par tipo↔plano e valida os dados usando o LancamentoRequest
     * (regras canônicas), antes de persistir na criação/edição.
     *
     * @param  Lancamento|null  $record  Registro em edição (null na criação), usado para
     *                                   detectar título rateado entre várias contas.
     */
    protected function prepararDados(array $data, ?Lancamento $record = null): array
    {
        // Normaliza o tipo para o valor escalar (required_if/prohibited comparam com string).
        $tipo = $data['tipo'] ?? null;

        if ($tipo instanceof TipoLancamento) {
            $tipo = $tipo->value;
            $data['tipo'] = $tipo;
        }

        // data_pagamento/forma_pagamento agora são 100% derivados da soma dos pagamentos
        // (ver Lancamento::recalcularStatus) — o formulário não os edita mais diretamente.
        unset($data['data_pagamento'], $data['forma_pagamento']);

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

        // Título a pagar rateado entre várias contas (gerado por compra: plano_despesa_id
        // nulo no cabeçalho de propósito, classificação vive em lancamento_despesas — ver
        // CompraService::gerarContaPagar). A tela de edição só conhece um plano por vez,
        // então protege o cabeçalho contra o colapso do rateio numa única conta e contra
        // uma alteração de valor que descolaria da soma das linhas de rateio.
        $temRateio = $record !== null
            && $record->tipo === TipoLancamento::Pagar
            && $record->plano_despesa_id === null;

        if ($temRateio) {
            $data['plano_despesa_id'] = null;
            $data['valor'] = (string) $record->valor;
        }

        // Validação canônica via Form Request.
        $request = new LancamentoRequest;
        $validator = Validator::make($data, $request->rules($temRateio), $request->messages(), $request->attributes());

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
