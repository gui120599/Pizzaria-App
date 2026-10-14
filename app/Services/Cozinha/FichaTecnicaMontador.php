<?php

namespace App\Services\Cozinha;

use App\Enums\Cozinha\EscopoIngrediente;
use App\Models\Adicional;
use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\FichaTecnicaPasso;
use App\Models\ProducaoItem;
use App\Models\Produto;
use App\Models\SetorProducao;
use App\Support\Cozinha\FichaTecnicaView;
use App\Support\Cozinha\IngredienteFicha;
use App\Support\Cozinha\PassoFicha;
use App\Support\FormatoQuantidade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Monta a ficha técnica viva de um item (spec §5.6): base uma vez (do 1º
 * sabor), cobertura e finalização multiplicadas pela fração de cada sabor,
 * "SEM …" da observação riscando a linha correspondente, adicionais com a
 * porção padrão e só os passos do setor do tablet (mais os gerais).
 */
class FichaTecnicaMontador
{
    public const GRUPO_BASE = 'Base';

    public function paraItem(ProducaoItem $item, ?SetorProducao $setor = null): FichaTecnicaView
    {
        $snapshot = (array) $item->snapshot;
        $setor ??= $item->setor;

        $sabores = $snapshot['sabores'] ?? [];
        $partes = $sabores === []
            ? [['produto_id' => (int) ($snapshot['produto_id'] ?? 0), 'percentual' => 100.0, 'nome' => null]]
            : array_map(fn (array $s) => ['produto_id' => (int) $s['produto_id'], 'percentual' => (float) $s['percentual'], 'nome' => $s['nome']], $sabores);

        $remocoes = array_column((array) ($snapshot['remocoes'] ?? []), 'opcional_ref');

        return $this->montar(
            titulo: (string) ($snapshot['produto'] ?? 'Item'),
            subtitulo: $sabores === [] ? ($snapshot['tamanho'] ?? null) : implode(' / ', array_map(fn (array $s) => trim(($s['rotulo'] ?? '').' '.$s['nome']), $sabores)),
            partes: $partes,
            setor: $setor,
            remocoes: $remocoes,
            adicionais: (array) ($snapshot['adicionais'] ?? []),
            respostas: (array) ($snapshot['respostas'] ?? []),
            observacao: $snapshot['observacao'] ?? null,
        );
    }

    /** Consulta livre (tablet ou pré-visualização do admin), sem pedido. */
    public function paraProduto(Produto $produto, ?SetorProducao $setor = null): FichaTecnicaView
    {
        return $this->paraSabores([$produto], $setor);
    }

    /**
     * Pré-visualização do admin: o produto puro ou meio a meio de exemplo
     * (frações iguais), com "SEM …" de exemplo.
     *
     * @param  list<Produto>  $sabores
     * @param  list<string>  $remocoes
     */
    public function paraSabores(array $sabores, ?SetorProducao $setor = null, array $remocoes = []): FichaTecnicaView
    {
        $principal = $sabores[0];
        $percentual = 100 / count($sabores);
        $rotulo = FormatoQuantidade::item(1 / count($sabores));

        return $this->montar(
            titulo: $principal->produto_descricao,
            subtitulo: count($sabores) > 1
                ? implode(' / ', array_map(fn (Produto $p) => "{$rotulo} {$p->produto_descricao}", $sabores))
                : $principal->categoria?->categoria_nome,
            partes: array_map(fn (Produto $p) => ['produto_id' => $p->id, 'percentual' => $percentual, 'nome' => $p->produto_descricao], $sabores),
            setor: $setor,
            remocoes: $remocoes,
            observacao: $remocoes === [] ? null : implode(', ', array_map(fn (string $r) => 'Sem '.$r, $remocoes)),
        );
    }

    /**
     * @param  list<array{produto_id: int, percentual: float, nome: ?string}>  $partes
     * @param  list<string>  $remocoes
     * @param  list<array<string, mixed>>  $adicionais
     * @param  list<string>  $respostas
     */
    private function montar(
        string $titulo,
        ?string $subtitulo,
        array $partes,
        ?SetorProducao $setor,
        array $remocoes = [],
        array $adicionais = [],
        array $respostas = [],
        ?string $observacao = null,
    ): FichaTecnicaView {
        $produtoIds = array_column($partes, 'produto_id');
        $fichas = FichaTecnica::ativas()->with(['passos.setor'])->whereIn('produto_id', $produtoIds)->get()->keyBy('produto_id');
        $linhas = FichaTecnicaItem::with('insumo.fichaItens')
            ->whereIn('fti_produto_id', $produtoIds)
            ->orderBy('fti_ordem')
            ->orderBy('id')
            ->get()
            ->groupBy('fti_produto_id');

        $casadas = [];
        $ingredientes = [];
        $multiSabor = count($partes) > 1;

        foreach ($partes as $indice => $parte) {
            $fracao = $parte['percentual'] / 100;
            $grupoSabor = $multiSabor ? FormatoQuantidade::item($fracao).' '.$parte['nome'] : 'Ingredientes';

            foreach ($linhas->get($parte['produto_id'], collect()) as $linha) {
                $escopo = $linha->fti_escopo ?? EscopoIngrediente::Cobertura;

                // Base (massa, molho, mussarela base) vem uma vez só, do 1º sabor.
                if ($escopo === EscopoIngrediente::Base && $indice > 0) {
                    continue;
                }

                $grupo = $escopo === EscopoIngrediente::Base && $multiSabor ? self::GRUPO_BASE : $grupoSabor;
                $quantidade = (float) $linha->fti_quantidade * ($escopo->multiplicaPelaFracao() ? $fracao : 1);
                $ref = $this->casaRemocao($linha, $remocoes);

                if ($ref !== null) {
                    $casadas[] = $ref;
                }

                $ingredientes[] = new IngredienteFicha(
                    grupo: $grupo,
                    nome: $linha->insumo?->produto_descricao ?? 'Ingrediente',
                    quantidade: $quantidade,
                    quantidadeFormatada: self::formatarQuantidade($quantidade, $linha->fti_unidade),
                    escopo: $escopo->value,
                    removido: $ref !== null,
                    fotoPorcao: $this->url($linha->fti_foto_porcao),
                    observacao: $linha->fti_observacao,
                    subfichaProdutoId: $linha->insumo?->fichaItens->isNotEmpty() ? $linha->fti_insumo_id : null,
                );
            }
        }

        // Base primeiro, depois os sabores na ordem do pedido.
        usort($ingredientes, fn (IngredienteFicha $a, IngredienteFicha $b) => ($b->grupo === self::GRUPO_BASE) <=> ($a->grupo === self::GRUPO_BASE));

        $principal = $fichas->get($partes[0]['produto_id']);

        return new FichaTecnicaView(
            titulo: $titulo,
            subtitulo: $subtitulo,
            temFicha: $principal !== null || $linhas->isNotEmpty(),
            ingredientes: $ingredientes,
            passos: $this->passos($principal, $setor),
            adicionais: $this->adicionais($adicionais),
            remocoesSemLinha: array_values(array_diff($remocoes, $casadas)),
            respostas: $respostas,
            observacao: $observacao,
            alergenos: $fichas->flatMap(fn (FichaTecnica $f) => (array) $f->alergenos)->unique()->values()->all(),
            fotoApresentacao: $this->url($principal?->foto_apresentacao),
            videoUrl: $principal?->video_url,
            pontosCriticos: $principal?->pontos_criticos,
            tempoPreparoMin: $principal?->tempo_preparo_min,
        );
    }

    /** @return list<PassoFicha> */
    private function passos(?FichaTecnica $ficha, ?SetorProducao $setor): array
    {
        if ($ficha === null) {
            return [];
        }

        return $ficha->passos
            ->filter(fn (FichaTecnicaPasso $p) => $p->setor_producao_id === null || $setor === null || $p->setor_producao_id === $setor->id)
            ->values()
            ->map(fn (FichaTecnicaPasso $p) => new PassoFicha(
                ordem: $p->ordem,
                titulo: $p->titulo,
                descricao: $p->descricao,
                foto: $this->url($p->foto),
                tempoSegundos: $p->tempo_segundos,
                temperaturaAlvo: $p->temperatura_alvo,
                pontoCritico: $p->ponto_critico,
            ))
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $adicionais
     * @return list<IngredienteFicha>
     */
    private function adicionais(array $adicionais): array
    {
        if ($adicionais === []) {
            return [];
        }

        $cadastro = Adicional::withTrashed()->whereKey(array_column($adicionais, 'adicional_id'))->get()->keyBy('id');

        return array_map(function (array $adicional) use ($cadastro): IngredienteFicha {
            $modelo = $cadastro->get($adicional['adicional_id'] ?? 0);
            $porcao = (float) ($modelo?->adicional_quantidade_porcao ?? 0) * max(1, (float) ($adicional['qtd'] ?? 1));

            return new IngredienteFicha(
                grupo: 'Adicionais',
                nome: '+ '.($adicional['nome'] ?? $modelo?->adicional_nome ?? 'Adicional'),
                quantidade: $porcao,
                quantidadeFormatada: $porcao > 0 ? self::formatarQuantidade($porcao, $modelo?->adicional_unidade_porcao) : '',
                escopo: null,
                observacao: $modelo?->adicional_instrucao_preparo,
            );
        }, $adicionais);
    }

    /**
     * A remoção casa com a linha pelo opcional_ref ou pela palavra no nome do
     * insumo ("cebola" × "Cebola Roxa"). Sem casamento, a observação só fica
     * destacada.
     *
     * @param  list<string>  $remocoes
     */
    private function casaRemocao(FichaTecnicaItem $linha, array $remocoes): ?string
    {
        $ref = self::normalizar((string) $linha->fti_opcional_ref);
        $nome = self::normalizar((string) $linha->insumo?->produto_descricao);

        foreach ($remocoes as $remocao) {
            $remocao = self::normalizar($remocao);

            if ($remocao === '') {
                continue;
            }

            if ($remocao === $ref || preg_match('/\b'.preg_quote($remocao, '/').'\b/', $nome) === 1) {
                return $remocao;
            }
        }

        return null;
    }

    /** 0,25 KG → "250 g", 0,05 L → "50 ml", 0,5 UN → "½ un"; no máximo 1 casa. */
    public static function formatarQuantidade(float $quantidade, ?string $unidade): string
    {
        $unidade = Str::lower(trim((string) $unidade));

        [$valor, $unidade] = match (true) {
            in_array($unidade, ['kg', 'kilo', 'quilo'], true) && $quantidade < 1 => [$quantidade * 1000, 'g'],
            in_array($unidade, ['l', 'lt', 'litro'], true) && $quantidade < 1 => [$quantidade * 1000, 'ml'],
            default => [$quantidade, $unidade === '' ? 'un' : $unidade],
        };

        $texto = in_array($unidade, ['un', 'und', 'unid', 'fatia', 'fatias'], true)
            ? FormatoQuantidade::item($valor)
            : rtrim(rtrim(number_format($valor, 1, ',', ''), '0'), ',');

        return "{$texto} {$unidade}";
    }

    private static function normalizar(string $texto): string
    {
        return trim(Str::lower(Str::ascii($texto)));
    }

    private function url(?string $caminho): ?string
    {
        return blank($caminho) ? null : Storage::disk((string) config('cozinha.disco_fotos'))->url($caminho);
    }
}
