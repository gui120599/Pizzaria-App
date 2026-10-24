<?php

namespace App\Services\Compras;

use App\Enums\CompraStatusEnum;
use App\Models\Compra;
use App\Models\CompraDevolucao;
use App\Models\CompraDevolucaoItem;
use App\Models\CompraItem;
use App\Services\CompraDevolucaoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra a devolução ao fornecedor a partir da NF-e que a documenta: a Compra
 * importada do XML deixa de ser uma entrada e passa a ser o documento fiscal de
 * uma devolução da compra original (saída de estoque + crédito com o
 * fornecedor, via CompraDevolucaoService — sem pipeline paralelo).
 * Ver docs/planos/operacoes-fiscais-entrada-nfe.md (Fase 4).
 */
class DevolucaoPorNotaService
{
    public function __construct(
        private CompraDevolucaoService $devolucoes,
        private CompraEstornoService $estorno,
        private CompraFiscalService $fiscal,
    ) {}

    /** Compra original apontada pelas NFref da nota, quando for compra nossa já confirmada. */
    public function sugerirOriginal(Compra $nota): ?Compra
    {
        $chaves = $nota->compra_notas_referenciadas ?? [];
        if ($chaves === []) {
            return null;
        }

        return Compra::whereIn('compra_chave_nfe', $chaves)
            ->where('compra_status', CompraStatusEnum::CONFIRMADA)
            ->first();
    }

    /**
     * Compras confirmadas do mesmo fornecedor, para escolher a original
     * quando a NFref não existir ou não casar.
     *
     * @return array<int, string>
     */
    public function opcoesDeOriginal(Compra $nota): array
    {
        $sugerida = $this->sugerirOriginal($nota);

        return Compra::where('compra_prestador_id', $nota->compra_prestador_id)
            ->where('compra_status', CompraStatusEnum::CONFIRMADA)
            ->whereKeyNot($nota->id)
            ->orderByDesc('compra_data_entrada')
            ->limit(100)
            ->get()
            ->when($sugerida !== null, fn (Collection $c) => $c->push($sugerida)->unique('id'))
            ->mapWithKeys(fn (Compra $c): array => [
                $c->id => 'NF '.($c->compra_numero ?: 's/n').' — '.$c->compra_data_entrada?->format('d/m/Y').' — R$ '.number_format((float) $c->compra_valor_total, 2, ',', '.'),
            ])
            ->all();
    }

    /**
     * Devoluções manuais da compra original que ainda não têm nota fiscal.
     *
     * @return array<int, string>
     */
    public function opcoesDeDevolucaoExistente(Compra $original): array
    {
        return CompraDevolucao::where('compra_id', $original->id)
            ->whereNull('compra_nota_id')
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (CompraDevolucao $d): array => [
                $d->id => '#'.$d->id.' — '.$d->created_at?->format('d/m/Y').' — R$ '.number_format((float) $d->valor_total, 2, ',', '.'),
            ])
            ->all();
    }

    /**
     * Casa cada item da nota com um item da compra original (código do
     * fornecedor, depois produto) e calcula quanto seria devolvido e creditado.
     *
     * @return array<int, array{descricao: string, quantidade: float, unidade: string, compra_item_id: ?int, disponivel: float, valor: float, problema: ?string}>
     */
    public function linhas(Compra $nota, Compra $original): array
    {
        $nota->loadMissing('itens');
        $acumulado = [];
        $linhas = [];

        foreach ($nota->itens as $item) {
            $alvo = $this->itemCorrespondente($original, $item);

            $linha = [
                'descricao' => (string) $item->ci_descricao_fornecedor,
                'quantidade' => (float) $item->ci_quantidade_compra,
                'unidade' => (string) $item->ci_unidade_compra,
                'compra_item_id' => $alvo?->id,
                'disponivel' => 0.0,
                'valor' => 0.0,
                'problema' => null,
            ];

            if ($alvo === null) {
                $linha['problema'] = 'Sem item correspondente na compra original.';
            } else {
                $jaDevolvida = (float) CompraDevolucaoItem::where('compra_item_id', $alvo->id)->sum('quantidade')
                    + ($acumulado[$alvo->id] ?? 0.0);
                $linha['disponivel'] = round((float) $alvo->ci_quantidade_compra - $jaDevolvida, 4);
                $acumulado[$alvo->id] = ($acumulado[$alvo->id] ?? 0.0) + $linha['quantidade'];

                if (mb_strtoupper(trim((string) $alvo->ci_unidade_compra)) !== mb_strtoupper(trim((string) $item->ci_unidade_compra))) {
                    $linha['problema'] = "Unidade diferente da compra original ({$alvo->ci_unidade_compra}).";
                } elseif ($linha['quantidade'] > $linha['disponivel'] + 0.0001) {
                    $linha['problema'] = 'Quantidade maior que a disponível para devolução ('.rtrim(rtrim(number_format(max($linha['disponivel'], 0), 4, ',', '.'), '0'), ',').').';
                } else {
                    $fator = (float) $alvo->ci_fator_conversao ?: 1.0;
                    $linha['valor'] = round($linha['quantidade'] * $alvo->custoUnitarioEstoque() * $fator, 2);
                }
            }

            $linhas[] = $linha;
        }

        return $linhas;
    }

    /**
     * Saída de estoque + crédito com o fornecedor, vinculados à nota. A nota
     * (rascunho, ou já estornada) passa a SEM_EFEITO.
     */
    public function registrar(Compra $nota, Compra $original, ?string $motivo = null): CompraDevolucao
    {
        return DB::transaction(function () use ($nota, $original, $motivo): CompraDevolucao {
            [$nota, $original] = $this->travar($nota, $original);
            $this->validar($nota, $original);

            $linhas = $this->linhas($nota, $original);
            $this->exigirLinhasValidas($linhas);

            $devolucao = $this->devolucoes->registrar(
                $original,
                array_map(fn (array $l): array => ['compra_item_id' => $l['compra_item_id'], 'quantidade' => $l['quantidade']], $linhas),
                $motivo ?: 'Devolução documentada pela NF '.($nota->compra_numero ?: $nota->compra_chave_nfe),
                $nota,
            );

            $this->marcarNotaComoSemEfeito($nota, $devolucao);

            return $devolucao;
        });
    }

    /** A devolução já tinha sido registrada à mão: só liga a nota, sem nova movimentação. */
    public function vincularADevolucaoExistente(Compra $nota, CompraDevolucao $devolucao): CompraDevolucao
    {
        return DB::transaction(function () use ($nota, $devolucao): CompraDevolucao {
            $devolucao = CompraDevolucao::lockForUpdate()->findOrFail($devolucao->id);
            [$nota, $original] = $this->travar($nota, $devolucao->compra);
            $this->validar($nota, $original);

            if ($devolucao->compra_nota_id !== null) {
                throw ValidationException::withMessages(['devolucao' => 'Esta devolução já está vinculada a uma nota fiscal.']);
            }

            $devolucao->update(['compra_nota_id' => $nota->id, 'chave_nfe' => $nota->compra_chave_nfe]);
            $this->marcarNotaComoSemEfeito($nota, $devolucao);

            return $devolucao->refresh();
        });
    }

    /**
     * Corrige uma nota de devolução que foi confirmada como entrada: estorna a
     * entrada e os títulos e registra a devolução, tudo na mesma transação.
     */
    public function converterCompraConfirmada(Compra $nota, Compra $original, string $motivo): CompraDevolucao
    {
        return DB::transaction(function () use ($nota, $original, $motivo): CompraDevolucao {
            $this->fiscal->classificarSeFaltar($nota);
            $nota->refresh();

            if (! $nota->ehNotaDeDevolucao()) {
                throw ValidationException::withMessages(['compra' => 'A nota não foi classificada como devolução ao fornecedor.']);
            }

            $this->estorno->aplicar($nota, $motivo);

            return $this->registrar($nota->refresh(), $original, $motivo);
        });
    }

    /** @return array{0: Compra, 1: Compra} */
    private function travar(Compra $nota, Compra $original): array
    {
        $ids = array_unique([$nota->id, $original->id]);
        sort($ids);

        $travadas = Compra::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$travadas[$nota->id], $travadas[$original->id]];
    }

    private function validar(Compra $nota, Compra $original): void
    {
        $this->fiscal->classificarSeFaltar($nota);
        $nota->refresh();

        // SEM_EFEITO só chega aqui vindo do estorno; se já tem devolução ligada, a checagem abaixo barra.
        if (! in_array($nota->compra_status, [CompraStatusEnum::RASCUNHO, CompraStatusEnum::SEM_EFEITO], true)) {
            throw ValidationException::withMessages(['compra' => 'A nota precisa estar em rascunho (ou estornada) para registrar a devolução.']);
        }

        if (! $nota->ehNotaDeDevolucao()) {
            throw ValidationException::withMessages(['compra' => 'A nota não foi classificada como devolução ao fornecedor.']);
        }

        if ($nota->devolucaoPorNota()->exists() || CompraDevolucao::where('chave_nfe', $nota->compra_chave_nfe)->exists()) {
            throw ValidationException::withMessages(['compra' => 'Esta nota fiscal já está vinculada a uma devolução.']);
        }

        if ($original->compra_status !== CompraStatusEnum::CONFIRMADA) {
            throw ValidationException::withMessages(['original' => 'A compra original precisa estar confirmada.']);
        }

        if ($original->id === $nota->id || (int) $original->compra_prestador_id !== (int) $nota->compra_prestador_id) {
            throw ValidationException::withMessages(['original' => 'A compra original precisa ser do mesmo fornecedor da nota.']);
        }
    }

    /** @param  array<int, array{descricao: string, problema: ?string}>  $linhas */
    private function exigirLinhasValidas(array $linhas): void
    {
        if ($linhas === []) {
            throw ValidationException::withMessages(['itens' => 'A nota não possui itens.']);
        }

        foreach ($linhas as $linha) {
            if ($linha['problema'] !== null) {
                throw ValidationException::withMessages(['itens' => "{$linha['descricao']}: {$linha['problema']}"]);
            }
        }
    }

    private function marcarNotaComoSemEfeito(Compra $nota, CompraDevolucao $devolucao): void
    {
        $nota->forceFill([
            'compra_status' => CompraStatusEnum::SEM_EFEITO,
            'compra_observacao' => trim(($nota->compra_observacao ? $nota->compra_observacao."\n" : '')."Devolução #{$devolucao->id} registrada a partir desta nota em ".now()->format('d/m/Y H:i').'.'),
        ])->save();
    }

    private function itemCorrespondente(Compra $original, CompraItem $item): ?CompraItem
    {
        $original->loadMissing('itens');

        $candidatos = $original->itens;
        $alvo = null;

        if (filled($item->ci_codigo_fornecedor)) {
            $alvo = $candidatos->first(fn (CompraItem $c): bool => (string) $c->ci_codigo_fornecedor === (string) $item->ci_codigo_fornecedor);
        }

        if ($alvo === null && $item->ci_produto_id) {
            $alvo = $candidatos->first(fn (CompraItem $c): bool => (int) $c->ci_produto_id === (int) $item->ci_produto_id);
        }

        return $alvo;
    }
}
