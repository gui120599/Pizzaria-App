<?php

namespace App\Services\Garcom;

use App\Exceptions\AutorizacaoNegadaException;
use App\Models\DispositivoPush;
use App\Models\PlantaoFreelancer;
use App\Models\User;
use App\Support\DiaOperacional;
use Illuminate\Support\Collection;

/**
 * Plantão do dia dos garçons freelancers: o gerente marca quem está
 * trabalhando e, até a virada do dia operacional, essa pessoa conta como "em
 * turno" para o push do salão, sem bater ponto.
 */
class PlantaoFreelancerService
{
    public function diaAtual(): string
    {
        return DiaOperacional::de()->toDateString();
    }

    /**
     * Freelancers com papel do salão, com a marcação de hoje e se há um
     * aparelho recebendo notificações (para o gerente saber se o aviso chega).
     *
     * @return Collection<int, array{id: int, nome: string, trabalhando: bool, recebe_notificacoes: bool}>
     */
    public function freelancers(): Collection
    {
        $marcados = $this->marcadosHoje();
        $comAparelho = DispositivoPush::query()->recebendo()->pluck('dp_user_id')->filter()->flip();

        return User::query()
            ->where('user_freelancer', true)
            ->role(User::GARCOM_PANEL_ROLES)
            ->orderBy('name_first')
            ->get(['id', 'name', 'name_first'])
            ->map(fn (User $usuario): array => [
                'id' => $usuario->id,
                'nome' => $usuario->name_first ?: $usuario->name,
                'trabalhando' => in_array($usuario->id, $marcados, true),
                'recebe_notificacoes' => $comAparelho->has($usuario->id),
            ])
            ->values();
    }

    /**
     * @return list<int>
     */
    public function marcadosHoje(): array
    {
        return PlantaoFreelancer::query()
            ->whereDate('pl_dia', $this->diaAtual())
            ->pluck('pl_user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @throws AutorizacaoNegadaException
     */
    public function marcar(User $freelancer, User $gerente): PlantaoFreelancer
    {
        $this->exigirGerencia($gerente);
        $this->exigirFreelancer($freelancer);

        return PlantaoFreelancer::query()->firstOrCreate(
            ['pl_user_id' => $freelancer->id, 'pl_dia' => $this->diaAtual()],
            ['pl_marcado_por_id' => $gerente->id],
        );
    }

    /**
     * @throws AutorizacaoNegadaException
     */
    public function desmarcar(User $freelancer, User $gerente): void
    {
        $this->exigirGerencia($gerente);

        PlantaoFreelancer::query()
            ->where('pl_user_id', $freelancer->id)
            ->whereDate('pl_dia', $this->diaAtual())
            ->delete();
    }

    private function exigirGerencia(User $gerente): void
    {
        if (! $gerente->ehGerencia()) {
            throw new AutorizacaoNegadaException('Só gerente ou administrador marca o plantão.');
        }
    }

    private function exigirFreelancer(User $freelancer): void
    {
        if (! $freelancer->user_freelancer) {
            throw new AutorizacaoNegadaException('Este usuário não está cadastrado como freelancer.');
        }
    }
}
