<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Opção de resposta de uma pergunta, com o acréscimo por unidade do item
 * (zero quando a escolha não muda o preço, ex.: "Ao ponto").
 */
class PerguntaOpcao extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'pergunta_opcoes';

    protected $fillable = [
        'pergunta_opcao_pergunta_id',
        'pergunta_opcao_nome',
        'pergunta_opcao_valor',
        'pergunta_opcao_ordem',
        'pergunta_opcao_ativa',
    ];

    protected function casts(): array
    {
        return [
            'pergunta_opcao_valor' => 'decimal:2',
            'pergunta_opcao_ordem' => 'integer',
            'pergunta_opcao_ativa' => 'boolean',
        ];
    }

    public function pergunta(): BelongsTo
    {
        return $this->belongsTo(Pergunta::class, 'pergunta_opcao_pergunta_id');
    }
}
