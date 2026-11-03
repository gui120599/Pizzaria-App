<?php

namespace App\Services\Push;

use App\Enums\DestinoAvisoMesaEnum;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Garcom\PlantaoFreelancerService;
use App\Services\Ponto\PontoAbertoService;
use App\Support\Push\EventoGarcom;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quem recebe cada aviso (plano, seção 8.4): o dono da mesa recebe sempre; no
 * modo "todos em turno" entram também os garçons com ponto aberto e os
 * freelancers marcados no plantão do dia; "quer abrir a mesa" ainda não tem
 * dono e vai só para quem está em turno (ponto aberto ou plantão).
 * Respeita as preferências de cada usuário.
 */
class DestinatariosGarcom
{
    public function __construct(
        private readonly PontoAbertoService $ponto,
        private readonly PlantaoFreelancerService $plantao,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function para(EventoGarcom $evento): Collection
    {
        $candidatos = User::query()->role(User::GARCOM_PANEL_ROLES)->get()->keyBy('id');

        if ($candidatos->isEmpty()) {
            return new Collection;
        }

        $ids = [];

        if ($evento->donoId !== null) {
            $ids[] = $evento->donoId;
        }

        $todosEmTurno = $evento->sempreTodosEmTurno
            || Empresa::configuracaoPush()['destino'] === DestinoAvisoMesaEnum::TODOS_EM_TURNO;

        if ($todosEmTurno) {
            $ids = [
                ...$ids,
                ...$this->ponto->usuariosComPontoAberto($candidatos->keys()->all()),
                ...$this->plantao->marcadosHoje(),
            ];
        }

        return $candidatos
            ->only(array_unique($ids))
            ->filter(fn (User $usuario): bool => ($usuario->user_push_preferencias[$evento->evento->value] ?? true) !== false)
            ->values();
    }
}
