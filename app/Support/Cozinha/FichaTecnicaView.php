<?php

namespace App\Support\Cozinha;

/**
 * Ficha técnica montada para um item específico (ou para consulta de um
 * produto): o mesmo DTO alimenta o modal do tablet, a pré-visualização do
 * admin e, no futuro, o PDF. Montado pelo FichaTecnicaMontador.
 */
final readonly class FichaTecnicaView
{
    /**
     * @param  list<IngredienteFicha>  $ingredientes
     * @param  list<PassoFicha>  $passos
     * @param  list<IngredienteFicha>  $adicionais
     * @param  list<string>  $remocoesSemLinha  "SEM …" da observação que não casaram com nenhuma linha
     * @param  list<string>  $respostas  "Borda: Catupiry"
     * @param  list<string>  $alergenos
     */
    public function __construct(
        public string $titulo,
        public ?string $subtitulo,
        public bool $temFicha,
        public array $ingredientes,
        public array $passos,
        public array $adicionais,
        public array $remocoesSemLinha,
        public array $respostas,
        public ?string $observacao,
        public array $alergenos,
        public ?string $fotoApresentacao,
        public ?string $videoUrl,
        public ?string $pontosCriticos,
        public ?int $tempoPreparoMin,
    ) {}

    /** Ingredientes agrupados pelo rótulo ("Base", "½ Calabresa"…), na ordem de montagem. */
    public function ingredientesPorGrupo(): array
    {
        $grupos = [];

        foreach ($this->ingredientes as $ingrediente) {
            $grupos[$ingrediente->grupo][] = $ingrediente;
        }

        return $grupos;
    }
}
