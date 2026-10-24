<?php

namespace App\Services;

use App\Enums\FormaPagamentoComissaoEnum;
use App\Enums\OrigemCupomEnum;
use App\Enums\StatusComissaoEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\PagamentoComissao;
use App\Notifications\ComissaoPagaNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PagamentoComissaoService
{
    /**
     * @param  Collection<int, Comissao>  $comissoes
     *
     * @throws InvalidArgumentException se a seleção misturar influenciadores
     *                                  diferentes ou incluir comissão não aprovada
     */
    public function gerar(
        Collection $comissoes,
        FormaPagamentoComissaoEnum $forma,
        ?string $comprovantePath = null,
    ): PagamentoComissao {
        if ($comissoes->isEmpty()) {
            throw new InvalidArgumentException('Selecione ao menos uma comissão aprovada.');
        }

        $influenciadorIds = $comissoes->pluck('comissao_influenciador_id')->unique();

        if ($influenciadorIds->count() > 1) {
            throw new InvalidArgumentException('Todas as comissões selecionadas precisam ser do mesmo influenciador.');
        }

        if ($comissoes->contains(fn (Comissao $c) => $c->comissao_status !== StatusComissaoEnum::APROVADA)) {
            throw new InvalidArgumentException('Só é possível gerar pagamento para comissões aprovadas.');
        }

        /** @var Influenciador $influenciador */
        $influenciador = Influenciador::findOrFail($influenciadorIds->first());
        $valorTotal = round((float) $comissoes->sum('comissao_valor'), 2);

        return DB::transaction(function () use ($comissoes, $forma, $comprovantePath, $influenciador, $valorTotal) {
            $pagamento = PagamentoComissao::create([
                'pgcom_influenciador_id' => $influenciador->id,
                'pgcom_periodo_referencia' => now()->startOfMonth(),
                'pgcom_valor_total' => $valorTotal,
                'pgcom_forma' => $forma->value,
                'pgcom_comprovante_path' => $comprovantePath,
                'pgcom_pago_em' => now(),
            ]);

            if ($forma === FormaPagamentoComissaoEnum::CREDITO_PIZZA) {
                $cupomCredito = Cupom::create([
                    'cupom_influenciador_id' => $influenciador->id,
                    'cupom_origem' => OrigemCupomEnum::CREDITO_COMISSAO->value,
                    'cupom_codigo' => 'CREDITO-'.$pagamento->id.'-'.strtoupper(substr(md5((string) now()->timestamp), 0, 4)),
                    'cupom_tipo_desconto' => TipoDescontoEnum::VALOR_FIXO->value,
                    'cupom_valor_desconto' => $valorTotal,
                    'cupom_limite_uso_total' => 1,
                ]);

                $pagamento->forceFill(['pgcom_cupom_credito_id' => $cupomCredito->id])->save();
            }

            foreach ($comissoes as $comissao) {
                $comissao->forceFill([
                    'comissao_status' => StatusComissaoEnum::PAGA,
                    'comissao_paga_em' => now(),
                    'comissao_pgcom_id' => $pagamento->id,
                ])->save();
            }

            $influenciador->notify(new ComissaoPagaNotification($pagamento));

            return $pagamento;
        });
    }
}
