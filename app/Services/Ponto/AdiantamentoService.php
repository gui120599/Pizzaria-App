<?php

namespace App\Services\Ponto;

use App\Enums\Financeiro\TipoContaFinanceira;
use App\Enums\FormaPagamento;
use App\Enums\Ponto\NaturezaAdiantamento;
use App\Enums\Ponto\StatusAdiantamento;
use App\Enums\StatusLancamento;
use App\Exceptions\PontoException;
use App\Models\Adiantamento;
use App\Models\Colaborador;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\PontoHistorico;
use App\Models\SessaoCaixa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Vale e consumo interno (spec §5.5). Vale/outro saem de uma conta ("Sai
 * de") e viram título já pago no plano do vínculo — saída real de dinheiro,
 * então o DRE soma adiantamento + líquido do repasse = bruto, sem duplicar.
 * Consumo e saldo devedor só descontam no repasse.
 */
class AdiantamentoService
{
    private const MOTIVO_MINIMO = 10;

    public function __construct(private readonly LancamentoGateway $lancamentos) {}

    /** @throws PontoException */
    public function registrar(
        Colaborador $colaborador,
        NaturezaAdiantamento $natureza,
        float $valor,
        CarbonInterface $data,
        ?string $descricao,
        User $usuario,
        ?ContaFinanceira $conta = null,
        ?SessaoCaixa $sessao = null,
        ?FormaPagamento $forma = null,
    ): Adiantamento {
        if (! $usuario->can('create:adiantamento')) {
            throw new PontoException('Você não tem permissão para registrar adiantamentos.');
        }

        $valor = round($valor, 2);
        $data = CarbonImmutable::instance($data)->startOfDay();

        if ($valor <= 0) {
            throw new PontoException('Informe um valor maior que zero.');
        }

        if ($data->isAfter(today())) {
            throw new PontoException('A data do adiantamento não pode ser futura.');
        }

        if (! $colaborador->estaAtivoEm($data)) {
            throw new PontoException("{$colaborador->nome} não estava ativo em {$data->format('d/m/Y')}.");
        }

        if ($natureza->saiDinheiro() && $conta === null) {
            throw new PontoException('Informe de qual conta sai o dinheiro.');
        }

        if (! $natureza->saiDinheiro() && $conta !== null) {
            throw new PontoException("{$natureza->getLabel()} não tira dinheiro de nenhuma conta.");
        }

        return DB::transaction(function () use ($colaborador, $natureza, $valor, $data, $descricao, $usuario, $conta, $sessao, $forma): Adiantamento {
            $adiantamento = Adiantamento::create([
                'colaborador_id' => $colaborador->id,
                'data' => $data->toDateString(),
                'natureza' => $natureza,
                'valor' => $valor,
                'descricao' => filled($descricao) ? trim($descricao) : null,
                'conta_financeira_id' => $conta?->id,
                'status' => StatusAdiantamento::Aberto,
                'registrado_por' => $usuario->id,
            ]);

            if ($conta === null) {
                return $adiantamento;
            }

            $lancamento = $this->lancamentos->criarPago(
                new ContaPagarDados(
                    favorecidoId: $this->lancamentos->favorecidoDe($colaborador),
                    planoDespesaId: $this->lancamentos->planoDe($colaborador),
                    valor: $valor,
                    vencimento: $data,
                    competencia: $data,
                    descricao: "{$natureza->getLabel()} – {$colaborador->nome} ({$data->format('d/m/Y')})",
                    observacoes: $adiantamento->descricao,
                    formaPagamento: $forma ?? self::formaSugerida($conta),
                    adiantamentoId: $adiantamento->id,
                ),
                $conta,
                $data,
                $sessao,
            );

            // No Caixa PDV a sessão é resolvida na baixa (a escolhida ou a única aberta).
            $adiantamento->update(['sessao_caixa_id' => $lancamento->pagamentos()->value('sessao_caixa_id')]);

            return $adiantamento;
        });
    }

    /**
     * Cancela um adiantamento ainda não descontado. Se saiu dinheiro, a
     * baixa é estornada (o dinheiro volta à conta; no Caixa PDV, só com a
     * sessão ainda aberta) e o título fica cancelado.
     *
     * @throws PontoException
     */
    public function cancelar(Adiantamento $adiantamento, User $usuario, string $motivo): void
    {
        if (! $usuario->can('cancelar:adiantamento')) {
            throw new PontoException('Você não tem permissão para cancelar adiantamentos.');
        }

        if (mb_strlen(trim($motivo)) < self::MOTIVO_MINIMO) {
            throw new PontoException('Informe o motivo (mínimo de '.self::MOTIVO_MINIMO.' caracteres).');
        }

        DB::transaction(function () use ($adiantamento, $usuario, $motivo): void {
            $adiantamento = Adiantamento::query()->lockForUpdate()->findOrFail($adiantamento->id);

            if ($adiantamento->status !== StatusAdiantamento::Aberto) {
                throw new PontoException($adiantamento->status === StatusAdiantamento::Descontado
                    ? 'Este adiantamento já foi descontado num repasse. Cancele a aprovação do repasse antes.'
                    : 'Este adiantamento já está cancelado.');
            }

            $adiantamento->lancamentos()
                ->where('status', '!=', StatusLancamento::Cancelado)
                ->get()
                ->each(function (Lancamento $lancamento) use ($motivo): void {
                    $this->lancamentos->estornarPagamentos($lancamento, 'Adiantamento cancelado: '.trim($motivo));
                    $this->lancamentos->cancelar($lancamento->refresh(), $motivo);
                });

            $adiantamento->update(['status' => StatusAdiantamento::Cancelado]);
            PontoHistorico::registrar($adiantamento, 'cancelado', ['motivo' => trim($motivo)], $usuario->id);
        });
    }

    /** Caixa → dinheiro; banco/conta digital → PIX (só sugestão, a forma não trava a conta). */
    public static function formaSugerida(ContaFinanceira $conta): FormaPagamento
    {
        return in_array($conta->tipo, [TipoContaFinanceira::CaixaPdv, TipoContaFinanceira::CaixaInterno], true)
            ? FormaPagamento::Dinheiro
            : FormaPagamento::Pix;
    }
}
