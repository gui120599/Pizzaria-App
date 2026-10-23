<?php

namespace App\Models;

use App\Enums\Financeiro\TipoContaFinanceira;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Enums\TipoChavePix;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * Onde a empresa guarda dinheiro. Toda entrada/saída passa pelo
 * MovimentacaoService; `saldo_atual` é cache reconstruível por saldoEm().
 *
 * O Caixa PDV é a conta do dinheiro das sessões de caixa. As vendas em
 * dinheiro entram nele na confirmação do fechamento (ContabilizacaoFechamentoService)
 * e o que sobra além do troco é recolhido por transferência, então o saldo
 * dele é o troco deixado na gaveta. Durante a sessão, o disponível para uma
 * saída do PDV continua sendo o dinheiro esperado da sessão escolhida
 * (CaixaPdvBridge), não este cache.
 */
class ContaFinanceira extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contas_financeiras';

    protected $fillable = [
        'nome',
        'tipo',
        'saldo_inicial',
        'data_saldo_inicial',
        'saldo_atual',
        'permite_saldo_negativo',
        'banco',
        'agencia',
        'conta',
        'tipo_chave_pix',
        'chave_pix',
        'cor',
        'ordem',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoContaFinanceira::class,
            'saldo_inicial' => 'decimal:2',
            'data_saldo_inicial' => 'date',
            'saldo_atual' => 'decimal:2',
            'permite_saldo_negativo' => 'boolean',
            'tipo_chave_pix' => TipoChavePix::class,
            'chave_pix' => 'encrypted',
            'ordem' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (ContaFinanceira $conta) => $conta->garantirCaixaPdvUnico());
        static::restoring(fn (ContaFinanceira $conta) => $conta->garantirCaixaPdvUnico());
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoFinanceira::class, 'conta_financeira_id');
    }

    public function scopeOrdenadas(Builder $query): Builder
    {
        return $query->orderBy('ordem')->orderBy('nome');
    }

    public function scopeDoTipo(Builder $query, TipoContaFinanceira $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public static function caixaPdv(): ?self
    {
        return static::query()->doTipo(TipoContaFinanceira::CaixaPdv)->first();
    }

    public function ehCaixaPdv(): bool
    {
        return $this->tipo === TipoContaFinanceira::CaixaPdv;
    }

    /**
     * Saldo ao fim do dia informado: saldo inicial + entradas − saídas
     * movimentadas a partir de data_saldo_inicial.
     */
    public function saldoEm(CarbonInterface $data): float
    {
        $totais = $this->movimentacoes()
            ->whereDate('data_movimento', '>=', $this->data_saldo_inicial->toDateString())
            ->whereDate('data_movimento', '<=', $data->toDateString())
            ->selectRaw('tipo, SUM(valor) AS total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $entradas = (float) ($totais[TipoMovimentacao::Entrada->value] ?? 0);
        $saidas = (float) ($totais[TipoMovimentacao::Saida->value] ?? 0);

        return round((float) $this->saldo_inicial + $entradas - $saidas, 2);
    }

    /** @throws ValidationException */
    private function garantirCaixaPdvUnico(): void
    {
        if (! $this->ehCaixaPdv()) {
            return;
        }

        $outra = static::query()
            ->doTipo(TipoContaFinanceira::CaixaPdv)
            ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->getKey()))
            ->exists();

        if ($outra) {
            throw ValidationException::withMessages([
                'tipo' => 'Já existe uma conta do tipo Caixa PDV. Só pode haver uma: é a gaveta das sessões de caixa.',
            ]);
        }
    }
}
