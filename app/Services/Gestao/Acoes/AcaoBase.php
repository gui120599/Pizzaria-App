<?php

namespace App\Services\Gestao\Acoes;

use App\Models\Produto;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

/** Utilitários comuns às ações do chat (alertas, moeda, busca de produto). */
abstract class AcaoBase implements AcaoConfirmavel
{
    protected function pode(User $usuario, string $permissao): bool
    {
        return Gate::forUser($usuario)->allows($permissao);
    }

    /** @return array{codigo: string, mensagem: string, bloqueia: bool} */
    protected function alerta(string $codigo, string $mensagem, bool $bloqueia = false): array
    {
        return ['codigo' => $codigo, 'mensagem' => $mensagem, 'bloqueia' => $bloqueia];
    }

    protected function moeda(float $valor): string
    {
        return (string) Number::currency($valor, 'BRL', 'pt_BR');
    }

    protected function numero(float $valor): string
    {
        return (string) Number::format($valor, maxPrecision: 3, locale: 'pt_BR');
    }

    /**
     * Produto pelo id ou pelo nome. Nome ambíguo lista os candidatos para o
     * Claude perguntar qual é.
     *
     * @throws ValidationException
     */
    protected function produtoPor(?int $id, ?string $busca, string $campo = 'busca'): Produto
    {
        if ($id !== null) {
            return Produto::query()->findOr($id, fn () => throw ValidationException::withMessages([$campo => "Produto #{$id} não encontrado."]));
        }

        $busca = trim((string) $busca);
        $candidatos = Produto::query()->where('produto_descricao', 'like', '%'.$busca.'%')->orderBy('produto_descricao')->limit(10)->get();
        $exato = $candidatos->first(fn (Produto $p): bool => mb_strtolower($p->produto_descricao) === mb_strtolower($busca));

        return match (true) {
            $busca === '' => throw ValidationException::withMessages([$campo => 'Informe o produto (id ou nome).']),
            $exato !== null => $exato,
            $candidatos->count() === 1 => $candidatos->first(),
            $candidatos->isEmpty() => throw ValidationException::withMessages([$campo => "Nenhum produto com o nome \"{$busca}\"."]),
            default => throw ValidationException::withMessages([$campo => "Mais de um produto para \"{$busca}\" — use o produto_id: "
                .$candidatos->map(fn (Produto $p): string => "{$p->id} = {$p->produto_descricao}")->implode('; ')]),
        };
    }
}
