<?php

namespace App\Services\Cozinha;

use App\Exceptions\ProducaoException;
use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\Produto;
use Illuminate\Support\Facades\DB;

/**
 * Ações da ficha técnica de preparo no admin. O tamanho da pizza é a
 * categoria (Pizza Grande × Pizza Média), então "duplicar para outro
 * tamanho" é copiar a ficha para o produto do mesmo sabor na outra categoria,
 * com as quantidades escaladas.
 */
class FichaTecnicaService
{
    /**
     * Copia cabeçalho, passos e ingredientes (quantidade × fator). Se o
     * destino já tem ficha de custo, os ingredientes dele ficam como estão
     * (ingredientesMantidos = true).
     *
     * @return array{ficha: FichaTecnica, ingredientes: int, ingredientesMantidos: bool}
     */
    public function duplicar(FichaTecnica $origem, Produto $destino, float $fator, int $userId): array
    {
        if ($fator <= 0) {
            throw new ProducaoException('O fator de escala precisa ser maior que zero.');
        }

        if ($destino->is($origem->produto)) {
            throw new ProducaoException('Escolha um produto diferente do original.');
        }

        if (FichaTecnica::withTrashed()->where('produto_id', $destino->id)->exists()) {
            throw new ProducaoException("{$destino->produto_descricao} já tem ficha de preparo (confira também a lixeira).");
        }

        return DB::transaction(function () use ($origem, $destino, $fator, $userId): array {
            $ficha = $origem->replicate(['versao', 'criado_por', 'produto_id']);
            $ficha->fill(['produto_id' => $destino->id, 'versao' => 1, 'criado_por' => $userId])->save();

            foreach ($origem->passos as $passo) {
                $copia = $passo->replicate(['ficha_tecnica_id']);
                $copia->ficha_tecnica_id = $ficha->id;
                $copia->save();
            }

            // Ficha de custo já cadastrada no destino é do estoque/CMV: não sobrescreve.
            $mantidos = FichaTecnicaItem::where('fti_produto_id', $destino->id)->exists();
            $copiados = 0;

            if (! $mantidos) {
                foreach ($origem->ingredientes as $linha) {
                    $copia = $linha->replicate(['fti_produto_id']);
                    $copia->fti_produto_id = $destino->id;
                    $copia->fti_quantidade = round((float) $linha->fti_quantidade * $fator, 4);
                    $copia->save();
                    $copiados++;
                }
            }

            return ['ficha' => $ficha, 'ingredientes' => $copiados, 'ingredientesMantidos' => $mantidos];
        });
    }

    /** Revisão manual: a produção passa a gravar a nova versão no item. */
    public function novaVersao(FichaTecnica $ficha): FichaTecnica
    {
        $ficha->forceFill(['versao' => $ficha->versao + 1])->save();

        return $ficha;
    }
}
