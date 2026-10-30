<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Miniaturas WebP das fotos de produto, para as telas que mostram muitas
 * fotos pequenas (cardápio, seletor do garçom/balcão). A foto original nunca
 * é alterada: as miniaturas são arquivos à parte em
 * fotos_produtos/miniaturas/{lado}/{nome}.webp, e quem não achar a miniatura
 * usa a original.
 */
class MiniaturaProdutoService
{
    /** Lado menor de cada miniatura, em pixels. */
    public const LADOS = [160, 320, 640];

    private const DIRETORIO = 'fotos_produtos/miniaturas';

    private const QUALIDADE = 80;

    /** Caminho (no disk public) da miniatura de uma foto. */
    public function caminho(string $foto, int $lado): string
    {
        return self::DIRETORIO.'/'.$lado.'/'.pathinfo($foto, PATHINFO_FILENAME).'.webp';
    }

    /**
     * Menor miniatura que cobre a largura exibida em tela de densidade 2x.
     * Acima da maior, devolve a maior (a foto original seria pesada demais).
     */
    public function ladoPara(int $larguraExibida): int
    {
        foreach (self::LADOS as $lado) {
            if ($lado >= $larguraExibida * 2) {
                return $lado;
            }
        }

        return self::LADOS[array_key_last(self::LADOS)];
    }

    public function existe(string $foto, int $lado): bool
    {
        return Storage::disk('public')->exists($this->caminho($foto, $lado));
    }

    /**
     * Gera as miniaturas que faltam (ou todas, com $forcar). A foto é reduzida
     * pelo lado menor, sem ampliar foto pequena; o recorte fica com o CSS
     * (object-fit), que conhece a proporção de cada tela.
     *
     * @return list<string> caminhos gravados
     */
    public function gerar(string $foto, bool $forcar = false): array
    {
        $disco = Storage::disk('public');

        if (! $disco->exists($foto)) {
            return [];
        }

        $pendentes = array_values(array_filter(
            self::LADOS,
            fn (int $lado) => $forcar || ! $disco->exists($this->caminho($foto, $lado)),
        ));

        if ($pendentes === []) {
            return [];
        }

        $gravados = [];

        foreach ($pendentes as $lado) {
            $imagem = ImageManager::gd()->read($disco->path($foto));

            if ($imagem->width() <= $imagem->height()) {
                $imagem->scaleDown(width: $lado);
            } else {
                $imagem->scaleDown(height: $lado);
            }

            $caminho = $this->caminho($foto, $lado);
            $disco->put($caminho, (string) $imagem->toWebp(quality: self::QUALIDADE));
            $gravados[] = $caminho;
        }

        return $gravados;
    }
}
