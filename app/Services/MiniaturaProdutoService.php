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
 *
 * As fotos de adicional (modal de montagem do cardápio) usam o mesmo
 * processo, com um tamanho só, em fotos_adicionais/miniaturas/{lado}/.
 */
class MiniaturaProdutoService
{
    /** Lado menor de cada miniatura, em pixels. */
    public const LADOS = [160, 320, 640];

    /** Adicional aparece só como ícone (56 px) no modal de montagem. */
    public const LADO_ADICIONAL = 160;

    private const DIRETORIO = 'fotos_produtos/miniaturas';

    private const DIRETORIO_ADICIONAIS = 'fotos_adicionais/miniaturas';

    private const QUALIDADE = 80;

    /** Caminho (no disk public) da miniatura de uma foto. */
    public function caminho(string $foto, int $lado): string
    {
        return self::DIRETORIO.'/'.$lado.'/'.pathinfo($foto, PATHINFO_FILENAME).'.webp';
    }

    /** Caminho (no disk public) da miniatura de uma foto de adicional. */
    public function caminhoAdicional(string $foto): string
    {
        return self::DIRETORIO_ADICIONAIS.'/'.self::LADO_ADICIONAL.'/'.pathinfo($foto, PATHINFO_FILENAME).'.webp';
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

        $gravados = [];

        foreach (self::LADOS as $lado) {
            $caminho = $this->caminho($foto, $lado);

            if ($forcar || ! $disco->exists($caminho)) {
                $this->gravar($disco->path($foto), $caminho, $lado);
                $gravados[] = $caminho;
            }
        }

        return $gravados;
    }

    /**
     * Miniatura de uma foto de adicional. A origem vem resolvida pelo model
     * (fotos novas no disk public, antigas em public/img/fotos_adicionais).
     *
     * @return string|null caminho gravado, ou null se já existia
     */
    public function gerarAdicional(string $origemAbsoluta, string $foto, bool $forcar = false): ?string
    {
        $caminho = $this->caminhoAdicional($foto);

        if (! $forcar && Storage::disk('public')->exists($caminho)) {
            return null;
        }

        $this->gravar($origemAbsoluta, $caminho, self::LADO_ADICIONAL);

        return $caminho;
    }

    private function gravar(string $origemAbsoluta, string $caminho, int $lado): void
    {
        $imagem = ImageManager::gd()->read($origemAbsoluta);

        if ($imagem->width() <= $imagem->height()) {
            $imagem->scaleDown(width: $lado);
        } else {
            $imagem->scaleDown(height: $lado);
        }

        Storage::disk('public')->put($caminho, (string) $imagem->toWebp(quality: self::QUALIDADE));
    }
}
