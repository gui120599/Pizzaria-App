<?php

namespace App\Services\Financeiro;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\LancamentoPagamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\SessaoCaixa;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Toda entrada/saída de dinheiro numa conta financeira passa por aqui, em
 * transação com lock na conta. `saldo_atual` é cache do que saldoEm()
 * reconstrói; a sessão de caixa só é tocada (via CaixaPdvBridge) quando a
 * conta é o Caixa PDV.
 */
class MovimentacaoService
{
    private const MOTIVO_MINIMO = 5;

    public function __construct(private readonly CaixaPdvBridge $caixaPdv) {}

    /**
     * @param  array{lancamento_id?: int|null, lancamento_pagamento_id?: int|null, adiantamento_id?: int|null}  $vinculos
     *
     * @throws FinanceiroException
     */
    public function saida(
        ContaFinanceira $conta,
        float $valor,
        NaturezaMovimentacao $natureza,
        CarbonInterface $data,
        string $descricao,
        array $vinculos = [],
        ?User $usuario = null,
        ?SessaoCaixa $sessao = null,
    ): MovimentacaoFinanceira {
        return DB::transaction(fn (): MovimentacaoFinanceira => $this->movimentar(
            $conta, TipoMovimentacao::Saida, $valor, $natureza, $data, $descricao, $vinculos, $usuario, $sessao,
        ));
    }

    /**
     * Entrada numa conta. No Caixa PDV, `$registrarNaSessao` = false quando a
     * sessão já conta o dinheiro por conta própria (recebimento com
     * sessao_caixa_id, que a conferência soma como fiado).
     *
     * @param  array{lancamento_id?: int|null, lancamento_pagamento_id?: int|null, adiantamento_id?: int|null}  $vinculos
     *
     * @throws FinanceiroException
     */
    public function entrada(
        ContaFinanceira $conta,
        float $valor,
        NaturezaMovimentacao $natureza,
        CarbonInterface $data,
        string $descricao,
        array $vinculos = [],
        ?User $usuario = null,
        ?SessaoCaixa $sessao = null,
        bool $registrarNaSessao = true,
    ): MovimentacaoFinanceira {
        return DB::transaction(fn (): MovimentacaoFinanceira => $this->movimentar(
            $conta, TipoMovimentacao::Entrada, $valor, $natureza, $data, $descricao, $vinculos, $usuario, $sessao, $registrarNaSessao,
        ));
    }

    /**
     * Confere, sem gravar, se a saída cabe na conta. Usado antes de criar o
     * registro de origem (pagamento), para recusar sem deixar nada pela metade.
     * Devolve a sessão de caixa resolvida quando a conta é o Caixa PDV.
     *
     * @throws FinanceiroException
     */
    public function validarSaida(ContaFinanceira $conta, float $valor, ?SessaoCaixa $sessao = null): ?SessaoCaixa
    {
        self::garantirValor($valor);
        $sessao = $conta->ehCaixaPdv() ? $this->caixaPdv->sessaoParaMovimentar($sessao) : null;
        $this->garantirSaldo($conta, round($valor, 2), $sessao);

        return $sessao;
    }

    /**
     * Duas movimentações com o mesmo transferencia_uuid. Não gera lançamento
     * nem afeta o DRE; se uma ponta for o Caixa PDV, vira sangria ou
     * suprimento na sessão.
     *
     * @return array{saida: MovimentacaoFinanceira, entrada: MovimentacaoFinanceira}
     *
     * @throws FinanceiroException
     */
    public function transferir(
        ContaFinanceira $origem,
        ContaFinanceira $destino,
        float $valor,
        CarbonInterface $data,
        ?string $descricao,
        User $usuario,
        ?SessaoCaixa $sessao = null,
    ): array {
        if ($origem->is($destino)) {
            throw new FinanceiroException('Escolha contas diferentes para a transferência.');
        }

        $descricao = filled($descricao) ? trim($descricao) : "Transferência {$origem->nome} → {$destino->nome}";

        return DB::transaction(function () use ($origem, $destino, $valor, $data, $descricao, $usuario, $sessao): array {
            // Lock das duas pontas em ordem de id, para duas transferências cruzadas não travarem uma à outra.
            ContaFinanceira::query()->whereKey([$origem->id, $destino->id])->orderBy('id')->lockForUpdate()->get();

            $uuid = (string) Str::uuid();

            return [
                'saida' => $this->movimentar($origem, TipoMovimentacao::Saida, $valor, NaturezaMovimentacao::Transferencia, $data, $descricao, [], $usuario, $sessao, transferenciaUuid: $uuid),
                'entrada' => $this->movimentar($destino, TipoMovimentacao::Entrada, $valor, NaturezaMovimentacao::Transferencia, $data, $descricao, [], $usuario, $sessao, transferenciaUuid: $uuid),
            ];
        });
    }

    /**
     * Corrige o saldo para o valor contado/conferido, com uma movimentação da
     * diferença. Não vale para o Caixa PDV, cujo saldo vem das sessões.
     *
     * @throws FinanceiroException
     */
    public function ajustarSaldo(ContaFinanceira $conta, float $novoSaldo, string $motivo, User $usuario): MovimentacaoFinanceira
    {
        self::garantirMotivo($motivo);

        if ($conta->ehCaixaPdv()) {
            throw new FinanceiroException('O dinheiro do Caixa PDV é conferido no fechamento da sessão de caixa; ajuste por lá.');
        }

        $novoSaldo = round($novoSaldo, 2);

        if ($novoSaldo < 0 && ! $conta->permite_saldo_negativo) {
            throw new FinanceiroException("A conta {$conta->nome} não aceita saldo negativo.");
        }

        return DB::transaction(function () use ($conta, $novoSaldo, $motivo, $usuario): MovimentacaoFinanceira {
            $conta = ContaFinanceira::query()->lockForUpdate()->findOrFail($conta->id);
            $diferenca = round($novoSaldo - (float) $conta->saldo_atual, 2);

            if ($diferenca === 0.0) {
                throw new FinanceiroException('O saldo da conta já é esse.');
            }

            return $this->movimentar(
                $conta,
                $diferenca > 0 ? TipoMovimentacao::Entrada : TipoMovimentacao::Saida,
                abs($diferenca),
                NaturezaMovimentacao::AjusteSaldo,
                now(),
                'Ajuste de saldo: '.trim($motivo),
                [],
                $usuario,
                validarSaldo: false,
            );
        });
    }

    /**
     * Estorno pedido pelo usuário. Movimentação de baixa estorna a baixa
     * inteira: o pagamento é excluído (e o título reaberto), e o evento do
     * pagamento faz a reversão. Transferência estorna as duas pontas.
     *
     * @return Collection<int, MovimentacaoFinanceira> os estornos criados
     *
     * @throws FinanceiroException
     */
    public function estornar(MovimentacaoFinanceira $movimentacao, string $motivo, User $usuario): Collection
    {
        self::garantirMotivo($motivo);

        return DB::transaction(function () use ($movimentacao, $motivo, $usuario): Collection {
            $movimentacao = MovimentacaoFinanceira::query()->lockForUpdate()->findOrFail($movimentacao->id);
            $this->garantirEstornavel($movimentacao);

            if ($movimentacao->lancamento_pagamento_id !== null) {
                $pagamento = LancamentoPagamento::query()->findOrFail($movimentacao->lancamento_pagamento_id);
                $pagamento->motivoEstorno = $motivo;
                $pagamento->delete();

                return MovimentacaoFinanceira::query()->where('estorno_de_id', $movimentacao->id)->get();
            }

            $pernas = $movimentacao->transferencia_uuid === null
                ? collect([$movimentacao])
                : MovimentacaoFinanceira::query()
                    ->where('transferencia_uuid', $movimentacao->transferencia_uuid)
                    ->whereNull('estorno_de_id')
                    ->lockForUpdate()
                    ->get();

            return $pernas->map(fn (MovimentacaoFinanceira $perna): MovimentacaoFinanceira => $this->reverter($perna, $motivo, $usuario));
        });
    }

    /**
     * Movimentação inversa (estorno_de_id → original), sem validar saldo: o
     * estorno corrige um registro. Se a original passou pela sessão de caixa,
     * a retirada/suprimento dela é desfeita.
     *
     * @throws FinanceiroException
     */
    public function reverter(MovimentacaoFinanceira $original, string $motivo, ?User $usuario = null): MovimentacaoFinanceira
    {
        return DB::transaction(function () use ($original, $motivo, $usuario): MovimentacaoFinanceira {
            $original = MovimentacaoFinanceira::query()->lockForUpdate()->findOrFail($original->id);
            $this->garantirEstornavel($original);

            $this->caixaPdv->desfazer($original);

            return $this->movimentar(
                ContaFinanceira::withTrashed()->findOrFail($original->conta_financeira_id),
                $original->tipo->inverso(),
                (float) $original->valor,
                NaturezaMovimentacao::Estorno,
                now(),
                Str::limit("Estorno: {$original->descricao} — ".trim($motivo), 255, ''),
                [
                    'lancamento_id' => $original->lancamento_id,
                    'lancamento_pagamento_id' => $original->lancamento_pagamento_id,
                    'adiantamento_id' => $original->adiantamento_id,
                ],
                $usuario,
                validarSaldo: false,
                registrarNaSessao: false,
                transferenciaUuid: $original->transferencia_uuid,
                estornoDeId: $original->id,
                sessaoCaixaId: $original->sessao_caixa_id,
                permitirContaInativa: true,
            );
        });
    }

    /**
     * Reconstrói o cache a partir do saldo inicial e das movimentações.
     *
     * @return array{anterior: float, atual: float}
     */
    public function recalcularSaldo(ContaFinanceira $conta): array
    {
        return DB::transaction(function () use ($conta): array {
            $conta = ContaFinanceira::withTrashed()->lockForUpdate()->findOrFail($conta->id);
            $anterior = round((float) $conta->saldo_atual, 2);
            $atual = $conta->saldoEm(Carbon::now()->addYears(50));

            $conta->forceFill(['saldo_atual' => $atual])->save();

            return ['anterior' => $anterior, 'atual' => $atual];
        });
    }

    /**
     * Precisa rodar dentro de transação.
     *
     * @param  array{lancamento_id?: int|null, lancamento_pagamento_id?: int|null, adiantamento_id?: int|null}  $vinculos
     */
    private function movimentar(
        ContaFinanceira $conta,
        TipoMovimentacao $tipo,
        float $valor,
        NaturezaMovimentacao $natureza,
        CarbonInterface $data,
        string $descricao,
        array $vinculos,
        ?User $usuario,
        ?SessaoCaixa $sessao = null,
        bool $registrarNaSessao = true,
        bool $validarSaldo = true,
        ?string $transferenciaUuid = null,
        ?int $estornoDeId = null,
        ?int $sessaoCaixaId = null,
        bool $permitirContaInativa = false,
    ): MovimentacaoFinanceira {
        self::garantirValor($valor);
        $valor = round($valor, 2);

        $conta = ContaFinanceira::query()
            ->when($permitirContaInativa, fn ($query) => $query->withTrashed())
            ->lockForUpdate()
            ->find($conta->id) ?? throw new FinanceiroException("A conta {$conta->nome} está desativada.");

        $tocarSessao = $conta->ehCaixaPdv() && $registrarNaSessao;

        if ($tocarSessao || ($conta->ehCaixaPdv() && $sessao !== null)) {
            $sessao = $this->caixaPdv->sessaoParaMovimentar($sessao);
        }

        if ($tipo === TipoMovimentacao::Saida && $validarSaldo) {
            $this->garantirSaldo($conta, $valor, $conta->ehCaixaPdv() ? $sessao : null);
        }

        $movimentacao = MovimentacaoFinanceira::create([
            'conta_financeira_id' => $conta->id,
            'tipo' => $tipo,
            'natureza' => $natureza,
            'valor' => $valor,
            'data_movimento' => $data->toDateString(),
            'descricao' => Str::limit($descricao, 255, ''),
            'lancamento_id' => $vinculos['lancamento_id'] ?? null,
            'lancamento_pagamento_id' => $vinculos['lancamento_pagamento_id'] ?? null,
            'adiantamento_id' => $vinculos['adiantamento_id'] ?? null,
            'sessao_caixa_id' => $sessaoCaixaId ?? ($conta->ehCaixaPdv() ? $sessao?->id : null),
            'transferencia_uuid' => $transferenciaUuid,
            'estorno_de_id' => $estornoDeId,
            'registrado_por' => $usuario?->id ?? auth()->id(),
        ]);

        if ($movimentacao->data_movimento->gte($conta->data_saldo_inicial)) {
            $conta->forceFill([
                'saldo_atual' => round((float) $conta->saldo_atual + $movimentacao->valorComSinal(), 2),
            ])->save();
        }

        if ($tocarSessao) {
            $tipo === TipoMovimentacao::Saida
                ? $this->caixaPdv->registrarRetirada($sessao, $movimentacao)
                : $this->caixaPdv->registrarSuprimento($sessao, $movimentacao);
        }

        return $movimentacao;
    }

    /** @throws FinanceiroException */
    private function garantirSaldo(ContaFinanceira $conta, float $valor, ?SessaoCaixa $sessao): void
    {
        if ($conta->ehCaixaPdv()) {
            $disponivel = $this->caixaPdv->dinheiroDisponivel($sessao);

            if ($valor > $disponivel + 0.001) {
                throw new FinanceiroException(sprintf(
                    'A sessão de caixa #%d tem R$ %s em dinheiro, menos que os R$ %s da saída.',
                    $sessao->id,
                    self::moeda($disponivel),
                    self::moeda($valor),
                ));
            }

            return;
        }

        if (! $conta->permite_saldo_negativo && (float) $conta->saldo_atual - $valor < -0.001) {
            throw new FinanceiroException(sprintf(
                'Saldo insuficiente em %s: R$ %s disponível para uma saída de R$ %s.',
                $conta->nome,
                self::moeda((float) $conta->saldo_atual),
                self::moeda($valor),
            ));
        }
    }

    /** @throws FinanceiroException */
    private function garantirEstornavel(MovimentacaoFinanceira $movimentacao): void
    {
        if ($movimentacao->estorno_de_id !== null) {
            throw new FinanceiroException('Um estorno não pode ser estornado.');
        }

        if ($movimentacao->estorno()->exists()) {
            throw new FinanceiroException('Esta movimentação já foi estornada.');
        }
    }

    /** @throws FinanceiroException */
    private static function garantirValor(float $valor): void
    {
        if (round($valor, 2) <= 0) {
            throw new FinanceiroException('Informe um valor maior que zero.');
        }
    }

    /** @throws FinanceiroException */
    private static function garantirMotivo(string $motivo): void
    {
        if (mb_strlen(trim($motivo)) < self::MOTIVO_MINIMO) {
            throw new FinanceiroException('Informe o motivo.');
        }
    }

    private static function moeda(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
