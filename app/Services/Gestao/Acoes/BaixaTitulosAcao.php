<?php

namespace App\Services\Gestao\Acoes;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\User;
use App\Services\Compras\PendenciaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Paguei o boleto do Moinho" / "o cliente X pagou": registra o pagamento
 * (ou recebimento) de títulos já lançados — o mesmo "Registrar pagamento"
 * de Contas a pagar/receber, com a movimentação na conta escolhida. Vários
 * títulos de uma vez, mesma data, forma e conta. Título de compra resolve
 * as pendências de pagamento a confirmar. Permissão: approve:lancamento.
 */
class BaixaTitulosAcao extends AcaoBase
{
    use ConfereTitulosFinanceiros;

    public function __construct(private readonly PendenciaService $pendencias) {}

    public function permitido(User $usuario): bool
    {
        return $this->pode($usuario, 'approve:lancamento');
    }

    public function preparar(array $entrada, User $usuario): array
    {
        $dados = Validator::make($entrada, [
            'titulos' => ['required', 'array', 'min:1', 'max:20'],
            'titulos.*.lancamento_id' => ['required', 'integer', 'distinct'],
            'titulos.*.valor' => ['nullable', 'numeric', 'gt:0'],
            'data' => ['nullable', 'date_format:Y-m-d'],
            'forma_pagamento' => ['nullable', Rule::in(self::formasAceitas())],
            'conta_financeira_id' => ['required', 'integer'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ], [
            'titulos.*.lancamento_id.distinct' => 'O mesmo título aparece duas vezes.',
            'conta_financeira_id.required' => 'Informe a conta de onde saiu (ou onde entrou) o dinheiro.',
        ])->validate();

        $data = Carbon::parse($dados['data'] ?? today()->toDateString());
        $conta = ContaFinanceira::query()->find($dados['conta_financeira_id']);
        $encontrados = Lancamento::query()->whereIn('id', array_column($dados['titulos'], 'lancamento_id'))
            ->with(['favorecido', 'cliente'])->get()->keyBy('id');
        $alertas = [];
        $linhas = [];

        if ($data->gt(today())) {
            $alertas[] = $this->alerta('data_futura', 'Data do pagamento no futuro.', bloqueia: true);
        } elseif ($data->lt(today()->subDays(60))) {
            $alertas[] = $this->alerta('data_antiga', 'Data do pagamento com mais de 60 dias: confira.');
        }

        foreach ($dados['titulos'] as $item) {
            $titulo = $encontrados->get($item['lancamento_id']);
            if ($titulo === null) {
                $alertas[] = $this->alerta('titulo_nao_encontrado', "Título #{$item['lancamento_id']} não encontrado: use buscar_titulos.", bloqueia: true);

                continue;
            }

            $restante = round((float) ($titulo->valorRestante > 0 ? $titulo->valorRestante : $titulo->valor), 2);
            $valor = round((float) ($item['valor'] ?? $restante), 2);
            $pagar = $titulo->tipo === TipoLancamento::Pagar;

            if (! in_array($titulo->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true)) {
                $alertas[] = $this->alerta('titulo_fechado', "#{$titulo->id} já está {$titulo->status->getLabel()}.", bloqueia: true);
            } elseif ($titulo->maquininha_id !== null) {
                $alertas[] = $this->alerta('recebivel_maquininha', "#{$titulo->id} é recebível de maquininha: a baixa é pela liquidação em Contas a receber.", bloqueia: true);
            } elseif ($valor > $restante + 0.009) {
                $alertas[] = $pagar
                    ? $this->alerta('juros_multa', "#{$titulo->id}: paga {$this->moeda($valor)}, {$this->moeda($valor - $restante)} acima do que falta (juros/multa).")
                    : $this->alerta('acima_do_restante', "#{$titulo->id}: o valor passa do que falta receber ({$this->moeda($restante)}).", bloqueia: true);
            }

            $linhas[] = [
                'lancamento_id' => $titulo->id,
                'tipo' => $pagar ? 'pagar' : 'receber',
                'descricao' => $titulo->descricao,
                'pessoa' => $pagar ? $titulo->favorecido?->nome_exibicao : $titulo->cliente?->cliente_nome,
                'vencimento' => $titulo->vencimento?->toDateString(),
                'valor_titulo' => (float) $titulo->valor,
                'falta' => $restante,
                'valor_baixa' => $valor,
                'fica' => $valor + 0.009 >= $restante ? 'quitado' : 'parcial (falta '.$this->moeda(round($restante - $valor, 2)).')',
            ];
        }

        $saida = collect($linhas)->where('tipo', 'pagar')->sum('valor_baixa');
        $sessaoId = $this->conferirConta($conta, round($saida, 2), $data, $alertas);

        $previa = [
            'data' => $data->toDateString(),
            'forma_pagamento' => $dados['forma_pagamento'] ?? null,
            'conta_financeira_id' => $conta?->id,
            'conta' => $conta?->nome,
            'sessao_caixa_id' => $sessaoId,
            'titulos' => $linhas,
            'total_pago' => round($saida, 2),
            'total_recebido' => round(collect($linhas)->where('tipo', 'receber')->sum('valor_baixa'), 2),
            'alertas' => $alertas,
        ];
        $previa['resumo_texto'] = $this->resumo($previa);

        return ['entrada' => $dados, 'previa' => $previa];
    }

    public function executar(array $entrada, array $previa, User $usuario): array
    {
        $forma = filled($previa['forma_pagamento']) ? FormaPagamento::from($previa['forma_pagamento']) : null;
        $observacoes = trim(($entrada['observacao'] ?? '').' Baixa pelo Claude (chat).');
        $feitos = [];

        try {
            foreach ($previa['titulos'] as $linha) {
                $titulo = Lancamento::query()->lockForUpdate()->findOrFail($linha['lancamento_id']);

                if (! in_array($titulo->status, [StatusLancamento::Pendente, StatusLancamento::Parcial], true)) {
                    throw ValidationException::withMessages(['titulos' => "#{$titulo->id} já foi baixado ou cancelado desde a prévia. Prepare de novo."]);
                }

                $titulo->registrarPagamento(
                    valor: $linha['valor_baixa'],
                    data: Carbon::parse($previa['data']),
                    forma: $forma,
                    observacoes: $observacoes,
                    sessaoCaixaId: $previa['sessao_caixa_id'],
                    contaFinanceiraId: $previa['conta_financeira_id'],
                );
                $this->pendencias->resolverDoLancamento($titulo, $usuario);

                $titulo->refresh();
                $feitos[] = ['lancamento_id' => $titulo->id, 'valor' => $linha['valor_baixa'], 'status' => $titulo->status->getLabel()];
            }
        } catch (FinanceiroException $e) {
            throw ValidationException::withMessages(['titulos' => $e->getMessage()]);
        }

        return ['baixas' => $feitos];
    }

    /** @param  array<string, mixed>  $previa */
    private function resumo(array $previa): string
    {
        $totais = array_filter([
            $previa['total_pago'] > 0 ? 'pagando '.$this->moeda($previa['total_pago']) : null,
            $previa['total_recebido'] > 0 ? 'recebendo '.$this->moeda($previa['total_recebido']) : null,
        ]);
        $linhas = ['Baixa em '.Carbon::parse($previa['data'])->format('d/m/Y').($previa['conta'] ? " na conta {$previa['conta']}" : '')
            .$this->rotuloForma($previa['forma_pagamento']).($totais ? ' — '.implode(' e ', $totais) : '').':'];

        foreach ($previa['titulos'] as $t) {
            $linhas[] = "- #{$t['lancamento_id']} ".($t['tipo'] === 'pagar' ? 'a pagar' : 'a receber')." · {$t['descricao']}"
                .($t['pessoa'] ? " ({$t['pessoa']})" : '').' · '.$this->moeda($t['valor_baixa'])." → {$t['fica']}";
        }

        foreach ($previa['alertas'] as $alerta) {
            $linhas[] = ($alerta['bloqueia'] ? '⛔ ' : '⚠️ ').$alerta['mensagem'];
        }

        return implode("\n", $linhas);
    }
}
