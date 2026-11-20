<?php

namespace App\Models;

use App\Enums\TipoRelatorioClaude;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relatório gravado por uma rotina do Claude (registrar_relatorio). */
class ClaudeRelatorio extends Model
{
    protected $table = 'claude_relatorios';

    protected $fillable = [
        'tipo',
        'titulo',
        'conteudo',
        'referencia',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoRelatorioClaude::class,
            'referencia' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
