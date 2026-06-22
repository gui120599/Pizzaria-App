<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prestador extends Model
{
    use SoftDeletes;

    protected $table = 'prestadores';

    protected $fillable = [
        'tipo',
        'categoria',
        'razao_social',
        'nome_fantasia',
        'nome',
        'cpf_cnpj',
        'inscricao_estadual',
        'email',
        'telefone',
        'celular',
        'cep',
        'endereco',
        'complemento',
        'numero',
        'bairro',
        'cidade',
        'uf',
        'observacoes',
    ];

    protected $casts = [
        'tipo' => \App\Enums\PrestadorTipoEnum::class,
        'categoria' => \App\Enums\PrestadorCategoriaEnum::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ─────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────

    public function scopePessoaFisica($query)
    {
        return $query->where('tipo', \App\Enums\PrestadorTipoEnum::PF);
    }

    public function scopePessoaJuridica($query)
    {
        return $query->where('tipo', \App\Enums\PrestadorTipoEnum::PJ);
    }

    public function scopeDeCategoria($query, string $categoria)
    {
        return $query->where('categoria', $categoria);
    }

    public function scopeFornecedores($query)
    {
        return $query->where('categoria', \App\Enums\PrestadorCategoriaEnum::FORNECEDOR);
    }

    // ─────────────────────────────────────────
    // Relacionamentos
    // ─────────────────────────────────────────

    /** De-para dos produtos deste fornecedor (código do fornecedor ↔ insumo). */
    public function fornecedorProdutos()
    {
        return $this->hasMany(FornecedorProduto::class, 'fp_prestador_id');
    }

    // ─────────────────────────────────────────
    // Accessors
    // ─────────────────────────────────────────

    public function getNomeExibicaoAttribute(): string
    {
        return $this->nome_fantasia
            ?? $this->razao_social
            ?? $this->nome
            ?? '—';
    }

    public function getEnderecoCompletoAttribute(): string
    {
        return collect([
            $this->endereco,
            $this->numero,
            $this->complemento,
            $this->bairro,
            $this->cidade,
            $this->uf,
            $this->cep,
        ])->filter()->implode(', ');
    }
}