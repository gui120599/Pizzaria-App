<?php

namespace App\Observers;

use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Models\Colaborador;
use App\Models\Prestador;

/**
 * Mantém o Prestador espelho do colaborador (favorecido dos repasses no
 * contas a pagar). Sincronização num sentido só: o espelho é somente leitura
 * no financeiro e sempre reflete o cadastro do colaborador.
 */
class ColaboradorObserver
{
    public function saved(Colaborador $colaborador): void
    {
        $this->sincronizar($colaborador);
    }

    /** Soft delete do colaborador leva o espelho junto (o histórico financeiro continua apontando para ele). */
    public function deleted(Colaborador $colaborador): void
    {
        if ($colaborador->isForceDeleting()) {
            return;
        }

        Prestador::sincronizandoEspelho(fn () => Prestador::query()->where('colaborador_id', $colaborador->id)->first()?->delete());
    }

    public function restored(Colaborador $colaborador): void
    {
        Prestador::sincronizandoEspelho(fn () => Prestador::withTrashed()->where('colaborador_id', $colaborador->id)->first()?->restore());

        $this->sincronizar($colaborador);
    }

    private function sincronizar(Colaborador $colaborador): void
    {
        $prestador = Prestador::withTrashed()->firstOrNew(['colaborador_id' => $colaborador->id]);

        $prestador->fill([
            'tipo' => PrestadorTipoEnum::PF,
            'categoria' => $colaborador->vinculo->ehEmpregado() ? PrestadorCategoriaEnum::FUNCIONARIO : PrestadorCategoriaEnum::AUTONOMO,
            'nome' => $colaborador->nome,
            'cpf_cnpj' => self::formatarCpf($colaborador->cpf),
            'tipo_chave_pix' => $colaborador->tipo_chave_pix,
            'chave_pix' => $colaborador->chave_pix,
        ]);

        if (! $prestador->exists || $prestador->isDirty()) {
            Prestador::sincronizandoEspelho(fn () => $prestador->save());
        }
    }

    /** Mesmo formato dos demais prestadores (000.000.000-00). */
    private static function formatarCpf(?string $cpf): ?string
    {
        if ($cpf === null || strlen($cpf) !== 11) {
            return $cpf;
        }

        return substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9, 2);
    }
}
