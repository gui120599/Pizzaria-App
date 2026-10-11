<?php

namespace App\Services;

use App\Enums\FormaPagamento;
use App\Enums\FormaTrocoEnum;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\TipoCorrecaoPagamentoEnum;
use App\Enums\TipoPagamentoMaquininhaEnum;
use App\Exceptions\CorrecaoPagamentoException;
use App\Models\CorrecaoPagamento;
use App\Models\FechamentoCaixa;
use App\Models\LancamentoPagamento;
use App\Models\NfEmissao;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\Venda;
use Illuminate\Support\Facades\DB;

/**
 * Correções de lançamento depois da venda finalizada, feitas pelo Relatório
 * de Sessão de Caixa: trocar a forma de um pagamento, dividir um pagamento em
 * formas diferentes, trocar a forma do troco e a forma de um recebimento de
 * fiado. Nunca muda valor nem total da venda — só onde o dinheiro entrou.
 *
 * Bloqueios (motivoBloqueio*): fechamento da sessão confirmado; NFC-e
 * autorizada ou em andamento (a nota precisa ser cancelada antes e reemitida
 * depois — decisão do usuário: cancelamento recusado = correção bloqueada);
 * pagamento integrado à Stone; forma com acréscimo/desconto (mudaria o total).
 *
 * Cada correção grava um CorrecaoPagamento (de → para) e atualiza o rascunho
 * do fechamento da sessão.
 */
class CorrecaoPagamentoService
{
    /** Status da NFC-e que permitem corrigir: sem nota, nota cancelada ou envio que falhou. */
    private const STATUS_NFE_LIBERADOS = ['Cancelled', NfEmissao::STATUS_FALHA_ENVIO, 'Error', 'IssueFailed'];

    public function __construct(
        private readonly TaxaMaquininhaService $taxas,
        private readonly FechamentoCaixaService $fechamentos,
    ) {}

    public function motivoBloqueioVenda(Venda $venda): ?string
    {
        if ($venda->venda_status !== 'FINALIZADA') {
            return 'Só vendas finalizadas são corrigidas por aqui.';
        }

        if ($this->fechamentoConfirmado($venda->venda_sessao_caixa_id)) {
            return 'O fechamento desta sessão já foi confirmado. Reabra o fechamento para corrigir.';
        }

        $statusNfe = $venda->venda_status_nfe;

        if (blank($statusNfe) || in_array($statusNfe, self::STATUS_NFE_LIBERADOS, true)) {
            return null;
        }

        return match ($statusNfe) {
            NfEmissao::STATUS_AUTORIZADA => 'A NFC-e está autorizada. Cancele a nota antes de corrigir; depois da correção ela é reemitida.',
            'CancelamentoSolicitado' => 'O cancelamento da NFC-e foi solicitado. Aguarde a confirmação da SEFAZ.',
            'CancelFailed' => 'A SEFAZ recusou o cancelamento da NFC-e — a correção fica bloqueada.',
            default => "A NFC-e está em processamento ({$statusNfe}). Aguarde a resposta da SEFAZ.",
        };
    }

    public function motivoBloqueioPagamento(PagamentosVenda $pagamento): ?string
    {
        $venda = $pagamento->venda;

        if (! $venda) {
            return 'Pagamento sem venda.';
        }

        if ($motivo = $this->motivoBloqueioVenda($venda)) {
            return $motivo;
        }

        if ($pagamento->pg_venda_tipo_integracao === 'integrated') {
            return 'Pagamento recebido pela integração Stone — a forma vem da maquininha.';
        }

        if ((float) $pagamento->pg_venda_valor_acrescimo != 0.0 || (float) $pagamento->pg_venda_valor_desconto != 0.0) {
            return 'Pagamento com acréscimo/desconto da forma — corrigir mudaria o total da venda.';
        }

        return null;
    }

    public function motivoBloqueioRecebimento(LancamentoPagamento $recebimento): ?string
    {
        if (! $recebimento->sessao_caixa_id) {
            return 'Recebimento feito fora do PDV (sem sessão de caixa) — corrija no Contas a Receber.';
        }

        if ($this->fechamentoConfirmado($recebimento->sessao_caixa_id)) {
            return 'O fechamento desta sessão já foi confirmado. Reabra o fechamento para corrigir.';
        }

        return null;
    }

    public function trocarForma(PagamentosVenda $pagamento, int $opcaoId, ?int $maquininhaId = null, ?int $cartaoId = null, ?string $justificativa = null): PagamentosVenda
    {
        return DB::transaction(function () use ($pagamento, $opcaoId, $maquininhaId, $cartaoId, $justificativa): PagamentosVenda {
            $pagamento = $this->travarPagamento($pagamento);
            $opcao = $this->opcaoSemTaxa($opcaoId);
            $antes = $this->retratoPagamento($pagamento);

            $this->aplicarForma($pagamento, $opcao, $maquininhaId, $cartaoId);
            $pagamento->save();

            $this->registrar(TipoCorrecaoPagamentoEnum::Forma, $pagamento->venda, $antes, $this->retratoPagamento($pagamento), $justificativa, pagamentoId: $pagamento->id);

            return $pagamento;
        });
    }

    /**
     * Divide um pagamento em duas ou mais formas, mantendo a soma. A 1ª parte
     * fica no registro original (vínculo com o pedido e troco); as demais viram
     * pagamentos novos sem troco.
     *
     * @param  array<int, array{opcao_id: int|string, maquininha_id?: int|string|null, cartao_id?: int|string|null, valor: float|int|string}>  $partes
     * @return array<int, PagamentosVenda>
     */
    public function dividir(PagamentosVenda $pagamento, array $partes, ?string $justificativa = null): array
    {
        return DB::transaction(function () use ($pagamento, $partes, $justificativa): array {
            $pagamento = $this->travarPagamento($pagamento);
            $partes = array_values($partes);

            if (count($partes) < 2) {
                throw new CorrecaoPagamentoException('Informe pelo menos duas partes para dividir o pagamento.');
            }

            $valores = array_map(fn (array $parte): float => round((float) $parte['valor'], 2), $partes);

            if (min($valores) <= 0) {
                throw new CorrecaoPagamentoException('Todas as partes precisam ter valor maior que zero.');
            }

            if (abs(array_sum($valores) - (float) $pagamento->pg_venda_valor_pagamento) >= 0.005) {
                throw new CorrecaoPagamentoException('A soma das partes (R$ '.number_format(array_sum($valores), 2, ',', '.')
                    .') precisa ser igual ao pagamento (R$ '.number_format((float) $pagamento->pg_venda_valor_pagamento, 2, ',', '.').').');
            }

            $opcoes = array_map(fn (array $parte): OpcoesPagamento => $this->opcaoSemTaxa((int) $parte['opcao_id']), $partes);
            $antes = $this->retratoPagamento($pagamento);
            $troco = (float) $pagamento->pg_venda_valor_troco;

            $pagamento->pg_venda_valor_pagamento = $valores[0];
            $pagamento->pg_venda_valor_recebido = $valores[0];
            $pagamento->pg_venda_valor_pago_pelo_cliente = round($valores[0] + $troco, 2);
            $this->aplicarForma($pagamento, $opcoes[0], self::idOuNulo($partes[0]['maquininha_id'] ?? null), self::idOuNulo($partes[0]['cartao_id'] ?? null));
            $pagamento->save();

            $resultado = [$pagamento];

            foreach (array_slice($partes, 1, preserve_keys: true) as $indice => $parte) {
                $novo = new PagamentosVenda([
                    'pg_venda_venda_id' => $pagamento->pg_venda_venda_id,
                    'pg_venda_tipo_integracao' => $pagamento->pg_venda_tipo_integracao,
                    'pg_venda_valor_pagamento' => $valores[$indice],
                    'pg_venda_valor_recebido' => $valores[$indice],
                    'pg_venda_valor_pago_pelo_cliente' => $valores[$indice],
                    'pg_venda_valor_troco' => 0,
                    'pg_venda_valor_acrescimo' => 0,
                    'pg_venda_valor_desconto' => 0,
                ]);
                $this->aplicarForma($novo, $opcoes[$indice], self::idOuNulo($parte['maquininha_id'] ?? null), self::idOuNulo($parte['cartao_id'] ?? null));
                $novo->save();

                $resultado[] = $novo;
            }

            $this->registrar(
                TipoCorrecaoPagamentoEnum::Divisao,
                $pagamento->venda,
                $antes,
                ['partes' => array_map(fn (PagamentosVenda $parte): array => $this->retratoPagamento($parte), $resultado)],
                $justificativa,
                pagamentoId: $pagamento->id,
            );

            return $resultado;
        });
    }

    public function trocarFormaTroco(PagamentosVenda $pagamento, FormaTrocoEnum $forma, ?string $justificativa = null): PagamentosVenda
    {
        return DB::transaction(function () use ($pagamento, $forma, $justificativa): PagamentosVenda {
            $pagamento = $this->travarPagamento($pagamento);

            if ((float) $pagamento->pg_venda_valor_troco <= 0) {
                throw new CorrecaoPagamentoException('Este pagamento não tem troco.');
            }

            $antes = $this->retratoPagamento($pagamento);
            $pagamento->pg_venda_forma_troco = $forma;
            $pagamento->save();

            $this->registrar(TipoCorrecaoPagamentoEnum::FormaTroco, $pagamento->venda, $antes, $this->retratoPagamento($pagamento), $justificativa, pagamentoId: $pagamento->id);

            return $pagamento;
        });
    }

    public function trocarFormaFiado(LancamentoPagamento $recebimento, FormaPagamento $forma, ?int $maquininhaId = null, ?int $cartaoId = null, ?string $justificativa = null): LancamentoPagamento
    {
        return DB::transaction(function () use ($recebimento, $forma, $maquininhaId, $cartaoId, $justificativa): LancamentoPagamento {
            $recebimento = LancamentoPagamento::query()->lockForUpdate()->findOrFail($recebimento->id);

            if ($motivo = $this->motivoBloqueioRecebimento($recebimento)) {
                throw new CorrecaoPagamentoException($motivo);
            }

            $antes = $this->retratoRecebimento($recebimento);
            $tipo = $forma->tipoMaquininha();

            $recebimento->forma_pagamento = $forma;
            $recebimento->maquininha_id = $tipo ? $maquininhaId : null;
            $recebimento->cartao_id = $tipo && $tipo !== TipoPagamentoMaquininhaEnum::Pix ? $cartaoId : null;
            $recebimento->taxa_percentual = null;
            $recebimento->taxa_valor = null;
            $this->taxas->gravarRetratoRecebimento($recebimento);
            $recebimento->save();

            CorrecaoPagamento::create([
                'tipo' => TipoCorrecaoPagamentoEnum::FormaFiado,
                'sessao_caixa_id' => $recebimento->sessao_caixa_id,
                'venda_id' => $recebimento->lancamento?->venda_id,
                'lancamento_pagamento_id' => $recebimento->id,
                'antes' => $antes,
                'depois' => $this->retratoRecebimento($recebimento),
                'justificativa' => $justificativa,
                'user_id' => auth()->id(),
            ]);

            $this->atualizarRascunho($recebimento->sessao_caixa_id);

            return $recebimento;
        });
    }

    /** Recarrega com lock (venda e pagamento) e valida os bloqueios. */
    private function travarPagamento(PagamentosVenda $pagamento): PagamentosVenda
    {
        Venda::query()->lockForUpdate()->find($pagamento->pg_venda_venda_id);
        $pagamento = PagamentosVenda::query()->with('venda')->lockForUpdate()->findOrFail($pagamento->id);

        if ($motivo = $this->motivoBloqueioPagamento($pagamento)) {
            throw new CorrecaoPagamentoException($motivo);
        }

        return $pagamento;
    }

    private function opcaoSemTaxa(int $opcaoId): OpcoesPagamento
    {
        $opcao = OpcoesPagamento::find($opcaoId);

        if (! $opcao) {
            throw new CorrecaoPagamentoException('Forma de pagamento não encontrada.');
        }

        if (in_array($opcao->opcaopag_tipo_taxa, ['ACRESCENTAR', 'DESCONTAR'], true) && (float) $opcao->opcaopag_valor_percentual_taxa > 0) {
            throw new CorrecaoPagamentoException("A forma \"{$opcao->opcaopag_nome}\" tem acréscimo/desconto — usar ela mudaria o total da venda.");
        }

        return $opcao;
    }

    /** Forma, maquininha, cartão e o retrato da taxa da maquininha para a forma nova. */
    private function aplicarForma(PagamentosVenda $pagamento, OpcoesPagamento $opcao, ?int $maquininhaId, ?int $cartaoId): void
    {
        $tipo = $opcao->tipoMaquininha();

        $pagamento->pg_venda_opcaopagamento_id = $opcao->id;
        $pagamento->pg_venda_maquininha_id = $tipo ? $maquininhaId : null;
        $pagamento->pg_venda_cartao_id = $tipo && $tipo !== TipoPagamentoMaquininhaEnum::Pix ? $cartaoId : null;
        $pagamento->setRelation('opcaoPagamento', $opcao);
        $this->taxas->gravarRetrato($pagamento);
    }

    /** @param  array<string, mixed>  $antes @param  array<string, mixed>  $depois */
    private function registrar(TipoCorrecaoPagamentoEnum $tipo, Venda $venda, array $antes, array $depois, ?string $justificativa, ?int $pagamentoId = null): void
    {
        CorrecaoPagamento::create([
            'tipo' => $tipo,
            'sessao_caixa_id' => $venda->venda_sessao_caixa_id,
            'venda_id' => $venda->id,
            'pagamento_venda_id' => $pagamentoId,
            'antes' => $antes,
            'depois' => $depois,
            'justificativa' => $justificativa,
            'user_id' => auth()->id(),
        ]);

        $this->atualizarRascunho($venda->venda_sessao_caixa_id);
    }

    /** @return array<string, mixed> */
    private function retratoPagamento(PagamentosVenda $pagamento): array
    {
        $pagamento->loadMissing(['opcaoPagamento' => fn ($query) => $query->withTrashed()]);

        return [
            'pagamento_id' => $pagamento->id,
            'opcao_id' => $pagamento->pg_venda_opcaopagamento_id,
            'forma' => $pagamento->opcaoPagamento?->opcaopag_nome,
            'maquininha_id' => $pagamento->pg_venda_maquininha_id,
            'cartao_id' => $pagamento->pg_venda_cartao_id,
            'valor' => (float) $pagamento->pg_venda_valor_pagamento,
            'troco' => (float) $pagamento->pg_venda_valor_troco,
            'forma_troco' => $pagamento->pg_venda_forma_troco?->value,
        ];
    }

    /** @return array<string, mixed> */
    private function retratoRecebimento(LancamentoPagamento $recebimento): array
    {
        return [
            'recebimento_id' => $recebimento->id,
            'forma' => $recebimento->forma_pagamento?->value,
            'maquininha_id' => $recebimento->maquininha_id,
            'cartao_id' => $recebimento->cartao_id,
            'valor' => (float) $recebimento->valor,
        ];
    }

    private function fechamentoConfirmado(?int $sessaoId): bool
    {
        return $sessaoId !== null && FechamentoCaixa::query()
            ->where('sessao_caixa_id', $sessaoId)
            ->where('status', StatusFechamentoCaixa::Confirmado)
            ->exists();
    }

    /** Só atualiza o snapshot se a sessão já tem fechamento em rascunho — não cria fechamento novo. */
    private function atualizarRascunho(?int $sessaoId): void
    {
        $fechamento = $sessaoId ? FechamentoCaixa::query()->where('sessao_caixa_id', $sessaoId)->first() : null;
        $sessao = $sessaoId ? SessaoCaixa::find($sessaoId) : null;

        if ($fechamento && $sessao) {
            $this->fechamentos->criarOuAtualizarRascunho($sessao, $fechamento);
        }
    }

    private static function idOuNulo(int|string|null $id): ?int
    {
        return filled($id) ? (int) $id : null;
    }
}
