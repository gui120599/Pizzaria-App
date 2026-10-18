<?php

namespace App\Models;

use App\Enums\Checklist\ExigenciaFoto;
use App\Enums\Checklist\RespostaProducao;
use App\Enums\Checklist\TipoResposta;
use App\Models\Concerns\TemHistoricoChecklist;
use App\Observers\ChecklistAuditoriaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Item de uma execução: snapshot do item do modelo (título, tipo e regras em
 * `config`) + a resposta dada.
 */
#[ObservedBy([ChecklistAuditoriaObserver::class])]
class ChecklistExecucaoItem extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoChecklist;

    protected $table = 'checklist_execucao_itens';

    protected $fillable = [
        'checklist_execucao_id',
        'checklist_modelo_item_id',
        'secao_titulo',
        'titulo',
        'instrucao',
        'tipo_resposta',
        'config',
        'valor_booleano',
        'valor_numero',
        'valor_texto',
        'valor_opcao',
        'conforme',
        'observacao',
        'respondido_por',
        'respondido_em',
        'ordem',
        'operador_id',
        'operador_nome',
    ];

    protected function casts(): array
    {
        return [
            'tipo_resposta' => TipoResposta::class,
            'config' => 'array',
            'valor_booleano' => 'boolean',
            'valor_numero' => 'decimal:2',
            'conforme' => 'boolean',
            'respondido_em' => 'datetime',
            'ordem' => 'integer',
        ];
    }

    public function execucao(): BelongsTo
    {
        return $this->belongsTo(ChecklistExecucao::class, 'checklist_execucao_id');
    }

    public function modeloItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistModeloItem::class, 'checklist_modelo_item_id')->withTrashed();
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(ChecklistAnexo::class, 'checklist_execucao_item_id');
    }

    public function naoConformidade(): HasOne
    {
        return $this->hasOne(ChecklistNaoConformidade::class, 'checklist_execucao_item_id');
    }

    /** Pessoa que respondeu no tablet compartilhado (módulo de cozinha). */
    public function operador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operador_id');
    }

    public function respondidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondido_por');
    }

    /** Valor de uma regra congelada no snapshot (`config`). */
    public function regra(string $chave, mixed $padrao = null): mixed
    {
        return data_get($this->config, $chave, $padrao);
    }

    public function obrigatorio(): bool
    {
        return (bool) $this->regra('obrigatorio', true);
    }

    public function critico(): bool
    {
        return (bool) $this->regra('critico', false);
    }

    public function exigenciaFoto(): ExigenciaFoto
    {
        return ExigenciaFoto::tryFrom((string) $this->regra('exige_foto')) ?? ExigenciaFoto::Nunca;
    }

    public function exigeObservacaoNaoConforme(): bool
    {
        return (bool) $this->regra('exige_observacao_nao_conforme', true);
    }

    public function exigeFotoEstoque(): bool
    {
        return (bool) $this->regra('exige_foto_estoque', false);
    }

    public function criterio(): ?string
    {
        $criterio = $this->regra('criterio');

        return filled($criterio) ? (string) $criterio : null;
    }

    public function respostaProducao(): ?RespostaProducao
    {
        return $this->tipo_resposta === TipoResposta::Producao
            ? RespostaProducao::tryFrom((string) $this->valor_opcao)
            : null;
    }

    /** Item "fazer se precisar" respondido com "Tinha estoque". */
    public function foiDispensado(): bool
    {
        return $this->respostaProducao() === RespostaProducao::TinhaEstoque;
    }

    public function foiRespondido(): bool
    {
        return $this->respondido_em !== null;
    }

    /** Resposta legível ("Sim", "-12,00 °C", rótulo da opção…). */
    public function respostaFormatada(): ?string
    {
        if (! $this->foiRespondido()) {
            return null;
        }

        return match ($this->tipo_resposta) {
            TipoResposta::Check => $this->valor_booleano ? 'Feito' : 'Não feito',
            TipoResposta::SimNao => $this->valor_booleano ? 'Sim' : 'Não',
            TipoResposta::Numero, TipoResposta::Temperatura => $this->valor_numero === null
                ? null
                : trim(number_format((float) $this->valor_numero, 2, ',', '.').' '.($this->regra('unidade') ?? '')),
            TipoResposta::Texto => $this->valor_texto,
            TipoResposta::Selecao => collect($this->regra('opcoes', []))
                ->firstWhere('valor', $this->valor_opcao)['rotulo'] ?? $this->valor_opcao,
            TipoResposta::Foto => 'Foto enviada',
            TipoResposta::Producao => $this->respostaProducao()?->getLabel() ?? $this->valor_opcao,
        };
    }
}
