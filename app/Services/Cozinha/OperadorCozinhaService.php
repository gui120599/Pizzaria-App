<?php

namespace App\Services\Cozinha;

use App\Exceptions\ProducaoException;
use App\Models\PontoMarcacao;
use App\Models\User;
use App\Services\Ponto\PontoAbertoService;
use App\Support\Cozinha\Operador;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Operador do tablet (spec §5.8): a pessoa que está produzindo, escolhida no
 * tablet logado na conta técnica da estação. Fica na sessão do tablet até
 * trocar ou expirar (cozinha.operador.expira_min). Quem entra com login
 * próprio (celular do gerente) é o próprio operador.
 */
class OperadorCozinhaService
{
    private const CHAVE_SESSAO = 'cozinha.operador';

    public function atual(): ?Operador
    {
        $conta = Auth::user();

        if ($conta === null) {
            return null;
        }

        if (! $this->ehContaDeEstacao($conta)) {
            return Operador::doUsuario($conta);
        }

        $salvo = Session::get(self::CHAVE_SESSAO);

        if (! is_array($salvo) || ($salvo['conta'] ?? null) !== $conta->id) {
            return null;
        }

        if (now()->timestamp - (int) ($salvo['em'] ?? 0) > (int) config('cozinha.operador.expira_min', 240) * 60) {
            $this->sair();

            return null;
        }

        return Operador::fromLivewire($salvo['operador']);
    }

    /** O tablet precisa escolher alguém (a conta logada é a da estação). */
    public function precisaEscolher(): bool
    {
        $conta = Auth::user();

        return $conta !== null && $this->ehContaDeEstacao($conta);
    }

    public function exigePin(): bool
    {
        return (bool) config('cozinha.operador.exigir_pin');
    }

    /**
     * Quem pode ser operador agora: papéis de cozinha (config) e, com
     * fonte = ponto, só quem está com o ponto aberto.
     *
     * @return Collection<int, User>
     */
    public function opcoes(): Collection
    {
        $usuarios = User::role((array) config('cozinha.operador.papeis', ['Cozinha']))
            ->orderBy('name_first')
            ->get();

        // Sem o módulo de folha de ponto instalado, vale a lista de usuários.
        if (config('cozinha.operador.fonte') !== 'ponto' || ! class_exists(PontoMarcacao::class)) {
            return $usuarios;
        }

        $comPontoAberto = app(PontoAbertoService::class)->usuariosComPontoAberto($usuarios->modelKeys());

        return $usuarios->filter(fn (User $user) => in_array($user->id, $comPontoAberto, true))->values();
    }

    public function selecionar(User $user, ?string $pin = null): Operador
    {
        if (! $this->opcoes()->contains('id', $user->id)) {
            throw new ProducaoException("{$user->name_first} não pode operar este tablet agora.");
        }

        if ($this->exigePin() && ! $user->conferePin((string) $pin)) {
            throw new ProducaoException('PIN incorreto.');
        }

        $operador = Operador::doUsuario($user);

        Session::put(self::CHAVE_SESSAO, [
            'conta' => Auth::id(),
            'em' => now()->timestamp,
            'operador' => $operador->toLivewire(),
        ]);

        return $operador;
    }

    public function sair(): void
    {
        Session::forget(self::CHAVE_SESSAO);
    }

    private function ehContaDeEstacao(User $user): bool
    {
        return $user->hasRole(PareamentoDispositivoService::PAPEL_ESTACAO);
    }
}
