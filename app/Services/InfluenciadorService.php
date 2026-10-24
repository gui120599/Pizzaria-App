<?php

namespace App\Services;

use App\Enums\StatusInfluenciadorEnum;
use App\Exceptions\InfluenciadorInvalidoException;
use App\Models\Empresa;
use App\Models\Influenciador;
use App\Notifications\CadastroAprovadoNotification;
use Illuminate\Support\Facades\DB;

class InfluenciadorService
{
    /**
     * Ativa o cadastro. Se a comissão do influenciador estiver zerada, aplica a
     * comissão padrão da configuração; sem comissão maior que zero, não aprova.
     *
     * @throws InfluenciadorInvalidoException
     */
    public function aprovar(Influenciador $influenciador): void
    {
        DB::transaction(function () use ($influenciador): void {
            $this->aplicarComissaoPadraoSeZerada($influenciador);

            if ((float) $influenciador->influenciador_valor_comissao <= 0) {
                throw new InfluenciadorInvalidoException(
                    'Defina uma comissão maior que zero para o influenciador (ou a comissão padrão em Configurações › Influenciadores).'
                );
            }

            $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();
        });

        $influenciador->notify(new CadastroAprovadoNotification);
    }

    /** Preenche tipo e valor da comissão com o padrão da empresa quando o valor está zerado. */
    public function aplicarComissaoPadraoSeZerada(Influenciador $influenciador): void
    {
        if ((float) $influenciador->influenciador_valor_comissao > 0) {
            return;
        }

        $empresa = Empresa::first();

        if (! $empresa || (float) $empresa->empresa_influ_comissao_valor <= 0) {
            return;
        }

        $influenciador->forceFill([
            'influenciador_tipo_comissao' => $empresa->empresa_influ_comissao_tipo,
            'influenciador_valor_comissao' => $empresa->empresa_influ_comissao_valor,
        ]);
    }
}
