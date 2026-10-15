<?php

namespace App\Models;

use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Enums\TipoChavePix;
use App\Exceptions\FinanceiroException;
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
        'colaborador_id',
        'razao_social',
        'nome_fantasia',
        'nome',
        'cpf_cnpj',
        'inscricao_estadual',
        'email',
        'telefone',
        'celular',
        'tipo_chave_pix',
        'chave_pix',
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
        'tipo_chave_pix' => TipoChavePix::class,
        'chave_pix' => 'encrypted',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /** Ligado só enquanto o ColaboradorObserver sincroniza o espelho. */
    private static bool $sincronizandoEspelho = false;

    /**
     * Espelho de colaborador é somente leitura fora da sincronização do
     * ColaboradorObserver. Fica no model, não na Policy: o Admin passa em
     * qualquer Policy (Gate::before do Shield).
     */
    protected static function booted(): void
    {
        $somenteLeitura = function (Prestador $prestador): void {
            if (! self::$sincronizandoEspelho && $prestador->getOriginal('colaborador_id') !== null) {
                throw new FinanceiroException('Este favorecido espelha um colaborador do ponto: altere pelo cadastro do colaborador.');
            }
        };

        static::updating($somenteLeitura);
        static::deleting($somenteLeitura);
        static::restoring($somenteLeitura);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $sincronizacao
     * @return T
     */
    public static function sincronizandoEspelho(callable $sincronizacao): mixed
    {
        self::$sincronizandoEspelho = true;

        try {
            return $sincronizacao();
        } finally {
            self::$sincronizandoEspelho = false;
        }
    }

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

    /** Quem pode ser favorecido de um título a pagar: fornecedores e espelhos de colaborador. */
    public function scopeFavorecidos($query)
    {
        return $query->where(fn ($q) => $q
            ->where('categoria', PrestadorCategoriaEnum::FORNECEDOR)
            ->orWhereNotNull('colaborador_id'));
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

    /**
     * Colaborador da folha de ponto espelhado neste prestador (favorecido dos
     * repasses). Prestador vinculado é somente leitura no financeiro: a
     * edição é pelo cadastro do colaborador.
     */
    public function colaborador()
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id')->withTrashed();
    }

    public function ehEspelhoDeColaborador(): bool
    {
        return $this->colaborador_id !== null;
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

    /** Rótulo no Select de favorecido: espelho de colaborador vem marcado. */
    public function rotuloFavorecido(): string
    {
        return $this->ehEspelhoDeColaborador() ? "{$this->nome_exibicao} (Colaborador)" : $this->nome_exibicao;
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
