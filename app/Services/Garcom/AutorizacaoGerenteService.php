<?php

namespace App\Services\Garcom;

use App\Enums\AcaoAutorizadaEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Models\AutorizacaoGerente;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Autoriza uma ação sensível e grava a trilha em autorizacoes_gerente.
 *
 * Quem tem a permissão da ação (AcaoAutorizadaEnum::permissao()) se
 * autoriza sozinho; os demais precisam do PIN de alguém que tenha. A gravação
 * acontece antes da ação — quem chama deve envolver os dois na mesma
 * transação para não sobrar autorização de ação que falhou.
 */
class AutorizacaoGerenteService
{
    public function __construct(private PinService $pins) {}

    /** O operador pode executar a ação sem pedir PIN a ninguém? */
    public function dispensaPin(User $solicitante, AcaoAutorizadaEnum $acao): bool
    {
        return $solicitante->can($acao->permissao());
    }

    /**
     * @param  array<string, mixed>  $dados  Antes/depois da ação, para auditoria
     *
     * @throws AutorizacaoNegadaException
     */
    public function autorizar(
        AcaoAutorizadaEnum $acao,
        User $solicitante,
        Model $auditavel,
        ?int $autorizadorId = null,
        ?string $pin = null,
        ?string $motivo = null,
        array $dados = [],
    ): AutorizacaoGerente {
        $autorizador = $this->dispensaPin($solicitante, $acao)
            ? $solicitante
            : $this->autorizadorPorPin($acao, $autorizadorId, $pin);

        return AutorizacaoGerente::create([
            'autorizacao_acao' => $acao,
            'autorizacao_solicitante_id' => $solicitante->id,
            'autorizacao_autorizador_id' => $autorizador->id,
            'autorizacao_auditavel_type' => $auditavel->getMorphClass(),
            'autorizacao_auditavel_id' => $auditavel->getKey(),
            'autorizacao_motivo' => filled($motivo) ? $motivo : null,
            'autorizacao_dados' => $dados ?: null,
        ]);
    }

    /**
     * Usuários que podem autorizar a ação com PIN — opções do seletor do modal.
     *
     * @return array<int, string>
     */
    public function autorizadoresDisponiveis(AcaoAutorizadaEnum $acao): array
    {
        return User::query()
            ->whereNotNull('user_pin')
            ->role(['Admin', 'Gerente'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (User $user) => $user->can($acao->permissao()))
            ->pluck('name', 'id')
            ->all();
    }

    private function autorizadorPorPin(AcaoAutorizadaEnum $acao, ?int $autorizadorId, ?string $pin): User
    {
        if (! $autorizadorId || blank($pin)) {
            throw new AutorizacaoNegadaException('Esta ação precisa da autorização de um gerente.');
        }

        $autorizador = User::find($autorizadorId);

        if (! $autorizador || ! $autorizador->can($acao->permissao())) {
            throw new AutorizacaoNegadaException('Este usuário não pode autorizar esta ação.');
        }

        $this->pins->verificar($autorizador, $pin);

        return $autorizador;
    }
}
