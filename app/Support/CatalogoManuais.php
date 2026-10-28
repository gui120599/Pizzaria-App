<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Manuais em PDF da pasta docs/, listados na página "Manuais" do painel.
 * Basta salvar um novo PDF em docs/ que ele aparece; os títulos abaixo só
 * deixam o nome mais legível que o do arquivo.
 */
class CatalogoManuais
{
    /** @var array<string, array{titulo: string, descricao: string}> */
    private const TITULOS = [
        'Manual-Checklists-Equipe.pdf' => ['titulo' => 'Checklists — Equipe', 'descricao' => 'Como preencher os checklists do turno.'],
        'Manual-Checklists-Gerencia.pdf' => ['titulo' => 'Checklists — Gerência', 'descricao' => 'Modelos, aprovação e acompanhamento dos checklists.'],
        'Manual-Compras-Condicoes-Fornecedor.pdf' => ['titulo' => 'Compras — Condições do fornecedor', 'descricao' => 'Prazo, forma de pagamento e data de entrada pré-preenchidos no Confirmar compra.'],
        'Manual-Cozinha-Equipe.pdf' => ['titulo' => 'Cozinha (KDS) — Equipe', 'descricao' => 'Uso do tablet de produção pelos setores da cozinha.'],
        'Manual-Ponto-Equipe.pdf' => ['titulo' => 'Folha de Ponto — Equipe', 'descricao' => 'Como registrar entrada, intervalo e saída.'],
        'Manual-Ponto-Gerencia.pdf' => ['titulo' => 'Folha de Ponto — Gerência', 'descricao' => 'Espelho de ponto, correções e repasses.'],
        'Instrucoes-Integracao-Stone.pdf' => ['titulo' => 'Integração Connect Stone', 'descricao' => 'Cobrança na maquininha, estorno, cancelamento e conferência.'],
        'Roteiro-Video-Demo-Stone.pdf' => ['titulo' => 'Roteiro do vídeo — Stone Connect', 'descricao' => 'Cenas e narração do vídeo de demonstração para a homologação Stone.'],
        'Roteiro-Testes-Homologacao-Stone.pdf' => ['titulo' => 'Roteiro de testes — Stone Connect', 'descricao' => 'Testes 001 a 006 da homologação Stone, com execução e campos de evidência.'],
    ];

    /**
     * @return Collection<int, array{arquivo: string, titulo: string, descricao: string, tamanho: int}>
     */
    public static function todos(): Collection
    {
        return collect(glob(base_path('docs/*.pdf')) ?: [])
            ->map(function (string $caminho): array {
                $arquivo = basename($caminho);

                return [
                    'arquivo' => $arquivo,
                    'titulo' => self::TITULOS[$arquivo]['titulo'] ?? str_replace('-', ' ', pathinfo($arquivo, PATHINFO_FILENAME)),
                    'descricao' => self::TITULOS[$arquivo]['descricao'] ?? '',
                    'tamanho' => filesize($caminho),
                ];
            })
            ->sortBy('titulo')
            ->values();
    }

    /** Caminho absoluto do manual, ou null se o nome não for um PDF de docs/. */
    public static function caminho(string $arquivo): ?string
    {
        return self::todos()->contains('arquivo', $arquivo)
            ? base_path('docs/'.$arquivo)
            : null;
    }
}
