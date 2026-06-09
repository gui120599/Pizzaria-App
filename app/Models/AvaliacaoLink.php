<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AvaliacaoLink extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'avaliacao_links';

    protected $fillable = [
        'avaliacao_link_nome',
        'avaliacao_link_url',
        'avaliacao_link_logo_url',
        'avaliacao_link_ativo',
        'avaliacao_link_ordem',
    ];

    protected $casts = [
        'avaliacao_link_ativo' => 'boolean',
        'avaliacao_link_ordem' => 'integer',
    ];
}
