<?php

namespace App\Console\Commands;

use App\Models\Produto;
use App\Services\MiniaturaProdutoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Gera as miniaturas WebP das fotos de produto que ainda não têm (fotos
 * antigas, anteriores à geração automática no upload). Não altera nem apaga
 * nenhuma foto original. Seguro para rodar de novo: só gera o que falta.
 */
class GerarMiniaturasProdutos extends Command
{
    protected $signature = 'produtos:miniaturas
        {--dry-run : Só lista quantas fotos seriam processadas, sem gravar}
        {--forcar : Regera mesmo as miniaturas que já existem}';

    protected $description = 'Gera miniaturas WebP das fotos de produto (cardápio e seletores)';

    public function handle(MiniaturaProdutoService $miniaturas): int
    {
        $fotos = Produto::withTrashed()
            ->whereNotNull('produto_foto')
            ->where('produto_foto', '!=', '')
            ->distinct()
            ->pluck('produto_foto');

        $existentes = $fotos->filter(fn (string $foto) => Storage::disk('public')->exists($foto));
        $pendentes = $this->option('forcar')
            ? $existentes
            : $existentes->reject(fn (string $foto) => collect(MiniaturaProdutoService::LADOS)
                ->every(fn (int $lado) => $miniaturas->existe($foto, $lado)));

        $this->info(sprintf(
            '%d fotos cadastradas, %d encontradas no disco, %d a processar.',
            $fotos->count(),
            $existentes->count(),
            $pendentes->count(),
        ));

        if ($this->option('dry-run') || $pendentes->isEmpty()) {
            return self::SUCCESS;
        }

        $falhas = 0;

        $this->withProgressBar($pendentes, function (string $foto) use ($miniaturas, &$falhas): void {
            try {
                $miniaturas->gerar($foto, (bool) $this->option('forcar'));
            } catch (\Throwable $e) {
                $falhas++;
                report($e);
            }
        });

        $this->newLine();

        if ($falhas > 0) {
            $this->warn("{$falhas} foto(s) não puderam ser processadas (ver log). O cardápio usa a original nesses casos.");
        }

        return self::SUCCESS;
    }
}
