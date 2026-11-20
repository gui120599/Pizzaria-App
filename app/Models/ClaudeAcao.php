<?php

namespace App\Models;

use App\Enums\Gestao\StatusAcaoClaude;
use App\Enums\Gestao\TipoAcaoClaude;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ação pedida pelo chat do Claude (G3): `entrada` é o que o Claude enviou,
 * `previa` o que o sistema calculou e mostrou ao usuário, `resultado` o que
 * foi gravado na confirmação (App\Services\Gestao\Acoes\AcoesClaudeService).
 */
class ClaudeAcao extends Model
{
    protected $table = 'claude_acoes';

    protected $fillable = [
        'uuid',
        'user_id',
        'tipo',
        'entrada',
        'previa',
        'status',
        'expira_em',
        'confirmada_em',
        'resultado',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoAcaoClaude::class,
            'status' => StatusAcaoClaude::class,
            'entrada' => 'array',
            'previa' => 'array',
            'resultado' => 'array',
            'expira_em' => 'datetime',
            'confirmada_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function expirou(): bool
    {
        return $this->expira_em->isPast();
    }
}
