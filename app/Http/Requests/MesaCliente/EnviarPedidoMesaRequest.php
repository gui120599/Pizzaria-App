<?php

namespace App\Http\Requests\MesaCliente;

use App\Services\ItemSolicitado;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rodada enviada pelo celular. Só o formato é validado aqui: o que pode ser
 * vendido, o preço e as regras ficam no PedidoMesaClienteService. Preço vindo
 * do navegador é ignorado.
 */
class EnviarPedidoMesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Gerada no celular por rodada: reenviar depois de queda de rede não duplica.
            'chave' => ['required', 'uuid'],
            'itens' => ['required', 'array', 'min:1', 'max:50'],
            'itens.*.id' => ['required', 'integer'],
            'itens.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'itens.*.observacao' => ['nullable', 'string', 'max:500'],
            'itens.*.sabores' => ['nullable', 'array', 'min:2', 'max:6'],
            'itens.*.sabores.*.id' => ['required', 'integer'],
            'itens.*.oferta_produto_id' => ['nullable', 'integer'],
            'itens.*.respostas' => ['nullable', 'array', 'max:20'],
            'itens.*.respostas.*' => ['array', 'max:20'],
            'itens.*.respostas.*.*' => ['integer'],
            'itens.*.adicionais' => ['nullable', 'array', 'max:20'],
            'itens.*.adicionais.*' => ['integer'],
        ];
    }

    /** @return list<ItemSolicitado> */
    public function itens(): array
    {
        return array_map(fn (array $item) => ItemSolicitado::doCarrinho($item), $this->validated('itens'));
    }
}
