<?php

namespace App\Models;

use App\Enums\Checklist\ExigenciaFoto;
use App\Enums\Checklist\TipoResposta;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChecklistModeloItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'checklist_modelo_itens';

    protected $fillable = [
        'checklist_modelo_id',
        'checklist_modelo_secao_id',
        'titulo',
        'instrucao',
        'tipo_resposta',
        'opcoes',
        'resposta_esperada',
        'valor_minimo',
        'valor_maximo',
        'unidade',
        'obrigatorio',
        'critico',
        'exige_foto',
        'exige_observacao_nao_conforme',
        'condicao',
        'ordem',
    ];

    /**
     * O Repeater de itens do Filament é aninhado no de seções e só preenche
     * checklist_modelo_secao_id — o modelo vem da seção. Opções de seleção sem
     * `valor` (recém-criadas no formulário) ganham um a partir do rótulo; o
     * valor de opção existente é mantido mesmo se o rótulo mudar.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if ($item->checklist_modelo_id === null && $item->checklist_modelo_secao_id !== null) {
                $item->checklist_modelo_id = ChecklistModeloSecao::whereKey($item->checklist_modelo_secao_id)->value('checklist_modelo_id');
            }
        });

        static::saving(function (self $item): void {
            if (! is_array($item->opcoes)) {
                return;
            }

            $item->opcoes = array_values(array_map(fn (array $opcao): array => [
                'valor' => filled($opcao['valor'] ?? null) ? (string) $opcao['valor'] : Str::slug((string) ($opcao['rotulo'] ?? ''), '_'),
                'rotulo' => (string) ($opcao['rotulo'] ?? ''),
                'conforme' => (bool) ($opcao['conforme'] ?? false),
            ], $item->opcoes));
        });
    }

    protected function casts(): array
    {
        return [
            'tipo_resposta' => TipoResposta::class,
            'opcoes' => 'array',
            'valor_minimo' => 'decimal:2',
            'valor_maximo' => 'decimal:2',
            'obrigatorio' => 'boolean',
            'critico' => 'boolean',
            'exige_foto' => ExigenciaFoto::class,
            'exige_observacao_nao_conforme' => 'boolean',
            'condicao' => 'array',
            'ordem' => 'integer',
        ];
    }

    public function modelo(): BelongsTo
    {
        return $this->belongsTo(ChecklistModelo::class, 'checklist_modelo_id');
    }

    public function secao(): BelongsTo
    {
        return $this->belongsTo(ChecklistModeloSecao::class, 'checklist_modelo_secao_id');
    }

    public function execucaoItens(): HasMany
    {
        return $this->hasMany(ChecklistExecucaoItem::class, 'checklist_modelo_item_id');
    }

    /**
     * Atributos de checklist_execucao_itens copiados deste item ao gerar uma
     * execução. As regras de conformidade vão para `config`, congeladas.
     *
     * @return array{checklist_modelo_item_id: int, secao_titulo: ?string, titulo: string, instrucao: ?string, tipo_resposta: string, ordem: int, config: array<string, mixed>}
     */
    public function toSnapshot(): array
    {
        return [
            'checklist_modelo_item_id' => $this->id,
            'secao_titulo' => $this->secao?->titulo,
            'titulo' => $this->titulo,
            'instrucao' => $this->instrucao,
            'tipo_resposta' => $this->tipo_resposta->value,
            'ordem' => $this->ordem,
            'config' => [
                'opcoes' => $this->opcoes,
                'resposta_esperada' => $this->resposta_esperada,
                'valor_minimo' => $this->valor_minimo,
                'valor_maximo' => $this->valor_maximo,
                'unidade' => $this->unidade,
                'obrigatorio' => $this->obrigatorio,
                'critico' => $this->critico,
                'exige_foto' => $this->exige_foto->value,
                'exige_observacao_nao_conforme' => $this->exige_observacao_nao_conforme,
            ],
        ];
    }
}
