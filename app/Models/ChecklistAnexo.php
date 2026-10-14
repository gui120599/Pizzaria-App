<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ChecklistAnexo extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'checklist_anexos';

    protected $fillable = [
        'checklist_execucao_item_id',
        'disco',
        'caminho',
        'tamanho_bytes',
        'enviado_por',
        'operador_id',
        'operador_nome',
    ];

    protected function casts(): array
    {
        return [
            'tamanho_bytes' => 'integer',
        ];
    }

    public function execucaoItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistExecucaoItem::class, 'checklist_execucao_item_id');
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function url(): string
    {
        return Storage::disk($this->disco)->url($this->caminho);
    }
}
