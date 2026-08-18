<?php

namespace App\Models;

use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prestador extends Model
{
    use SoftDeletes;

    protected $table = 'prestadores';

    protected $fillable = [
        'tipo',
        'categoria',
        'cliente_id',
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
        'tipo' => PrestadorTipoEnum::class,
        'categoria' => PrestadorCategoriaEnum::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ─────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────

    public function scopePessoaFisica($query)
    {
        return $query->where('tipo', PrestadorTipoEnum::PF);
    }

    public function scopePessoaJuridica($query)
    {
        return $query->where('tipo', PrestadorTipoEnum::PJ);
    }

    public function scopeDeCategoria($query, string $categoria)
    {
        return $query->where('categoria', $categoria);
    }

    public function scopeFornecedores($query)
    {
        return $query->where('categoria', PrestadorCategoriaEnum::FORNECEDOR);
    }

    // ─────────────────────────────────────────
    // Relacionamentos
    // ─────────────────────────────────────────

    /** De-para dos produtos deste fornecedor (código do fornecedor ↔ insumo). */
    public function fornecedorProdutos()
    {
        return $this->hasMany(FornecedorProduto::class, 'fp_prestador_id');
    }

    /** Cadastro de Cliente vinculado, quando este fornecedor também consome no PDV. */
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
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
