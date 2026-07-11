<?php

namespace App\Models;

use App\Enums\PromocaoStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromocaoRelampago extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'promocoes_relampago';

    protected $fillable = [
        'promocao_nome',
        'promocao_descricao',
        'promocao_ativa',
        'promocao_recorrente',
        'promocao_inicio',
        'promocao_fim',
        'promocao_dias_semana',
        'promocao_hora_inicio',
        'promocao_hora_fim',
        'promocao_data_final_recorrencia',
        'promocao_ultimo_reset_em',
        'promocao_qtd_total',
        'promocao_qtd_vendida',
        'promocao_limite_por_pedido',
        'promocao_exibe_contador',
        'promocao_limiar_escassez',
        'promocao_permite_sabores',
        'promocao_max_sabores',
        'promocao_ordem',
    ];

    protected function casts(): array
    {
        return [
            'promocao_ativa' => 'boolean',
            'promocao_recorrente' => 'boolean',
            'promocao_exibe_contador' => 'boolean',
            'promocao_permite_sabores' => 'boolean',
            'promocao_inicio' => 'datetime',
            'promocao_fim' => 'datetime',
            'promocao_dias_semana' => 'array',
            'promocao_hora_inicio' => 'datetime:H:i:s',
            'promocao_hora_fim' => 'datetime:H:i:s',
            'promocao_data_final_recorrencia' => 'date',
            'promocao_ultimo_reset_em' => 'datetime',
            'promocao_qtd_total' => 'integer',
            'promocao_qtd_vendida' => 'decimal:2',
            'promocao_limite_por_pedido' => 'integer',
            'promocao_limiar_escassez' => 'integer',
            'promocao_max_sabores' => 'integer',
        ];
    }

    public function promocaoProdutos(): HasMany
    {
        return $this->hasMany(PromocaoRelampagoProduto::class, 'prp_promocao_id');
    }

    public function produtos(): BelongsToMany
    {
        return $this->belongsToMany(
            Produto::class,
            'promocao_relampago_produtos',
            'prp_promocao_id',
            'prp_produto_id'
        )->withPivot(['id', 'prp_preco_promocional', 'prp_qtd_total', 'prp_qtd_vendida']);
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(PromocaoConsumo::class, 'consumo_promocao_id');
    }

    /**
     * Ativa e dentro da janela de vigência. Não considera saldo.
     *
     * Recorrência (dia da semana + hora do dia) não é trivial de expressar em
     * SQL portável, e o número de promoções cadastradas é pequeno — então o
     * scope filtra grosso no banco (só `promocao_ativa`) e refina com precisão
     * em PHP via `vigente()`, sem custo relevante.
     */
    public function scopeVigente(Builder $query, ?CarbonInterface $momento = null): Builder
    {
        $momento ??= now();

        $ids = (clone $query)->where('promocao_ativa', true)
            ->get()
            ->filter(fn (self $promocao) => $promocao->vigente($momento))
            ->pluck('id');

        return $query->whereIn('promocoes_relampago.id', $ids);
    }

    /** Ainda tem unidades no pool. Promoção sem teto sempre passa. */
    public function scopeComSaldo(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('promocao_qtd_total')
                ->orWhereColumn('promocao_qtd_vendida', '<', 'promocao_qtd_total')
        );
    }

    /** Unidades restantes no pool; null quando a promoção não tem teto. */
    public function saldoDisponivel(): ?float
    {
        if ($this->promocao_qtd_total === null) {
            return null;
        }

        return max(0.0, (float) $this->promocao_qtd_total - (float) $this->promocao_qtd_vendida);
    }

    public function esgotada(): bool
    {
        $saldo = $this->saldoDisponivel();

        return $saldo !== null && $saldo <= 0;
    }

    public function vigente(?CarbonInterface $momento = null): bool
    {
        $momento ??= now();

        if (! $this->promocao_ativa) {
            return false;
        }

        if ($this->promocao_recorrente) {
            return $this->vigenteRecorrente($momento);
        }

        return $this->promocao_inicio <= $momento && $this->promocao_fim >= $momento;
    }

    public function status(?CarbonInterface $momento = null): PromocaoStatusEnum
    {
        $momento ??= now();

        if (! $this->promocao_ativa) {
            return PromocaoStatusEnum::Inativa;
        }

        if ($this->promocao_recorrente) {
            return $this->statusRecorrente($momento);
        }

        return match (true) {
            $this->promocao_fim < $momento => PromocaoStatusEnum::Encerrada,
            $this->promocao_inicio > $momento => PromocaoStatusEnum::Agendada,
            $this->esgotada() => PromocaoStatusEnum::Esgotada,
            default => PromocaoStatusEnum::Ativa,
        };
    }

    /**
     * Ocorrência atual: dentro do intervalo de datas da recorrência, no dia da
     * semana certo e (se houver janela) dentro do horário. Sem hora_inicio/fim
     * configurados, vale o dia inteiro nos dias marcados.
     *
     * Janela que atravessa a meia-noite (ex.: 22:00–02:00) tem um cuidado: às
     * 01:30 de quarta a ocorrência ainda é a de TERÇA (que começou ontem) — o
     * dia da semana é checado contra ontem, não contra hoje, nesse trecho.
     */
    private function vigenteRecorrente(CarbonInterface $momento): bool
    {
        if (! $this->dentroDoIntervaloDeDatas($momento)) {
            return false;
        }

        if (! $this->promocao_hora_inicio || ! $this->promocao_hora_fim) {
            return $this->diaDaSemanaBate($momento);
        }

        $hora = $momento->format('H:i:s');
        $inicio = $this->promocao_hora_inicio->format('H:i:s');
        $fim = $this->promocao_hora_fim->format('H:i:s');

        if ($inicio <= $fim) {
            return $this->diaDaSemanaBate($momento) && $hora >= $inicio && $hora <= $fim;
        }

        // Atravessa meia-noite: madrugada (hora <= fim) é ocorrência de ontem;
        // noite (hora >= início) é a ocorrência de hoje.
        if ($hora <= $fim) {
            return $this->diaDaSemanaBate($momento->copy()->subDay());
        }

        return $hora >= $inicio && $this->diaDaSemanaBate($momento);
    }

    private function statusRecorrente(CarbonInterface $momento): PromocaoStatusEnum
    {
        if ($this->promocao_inicio && $momento->lt($this->promocao_inicio)) {
            return PromocaoStatusEnum::Agendada;
        }

        if ($this->promocao_data_final_recorrencia && $momento->toDateString() > $this->promocao_data_final_recorrencia->toDateString()) {
            return PromocaoStatusEnum::Encerrada;
        }

        if (! $this->vigenteRecorrente($momento)) {
            return PromocaoStatusEnum::AguardandoJanela;
        }

        if ($this->esgotada()) {
            return PromocaoStatusEnum::Esgotada;
        }

        return PromocaoStatusEnum::Ativa;
    }

    /** Início/fim da recorrência (datas), sem considerar dia da semana ou hora. */
    private function dentroDoIntervaloDeDatas(CarbonInterface $momento): bool
    {
        if ($this->promocao_inicio && $momento->lt($this->promocao_inicio)) {
            return false;
        }

        if ($this->promocao_data_final_recorrencia && $momento->toDateString() > $this->promocao_data_final_recorrencia->toDateString()) {
            return false;
        }

        return true;
    }

    /** Vazio = todos os dias. */
    private function diaDaSemanaBate(CarbonInterface $momento): bool
    {
        $dias = $this->promocao_dias_semana ?? [];

        return empty($dias) || in_array($momento->dayOfWeek, $dias, false);
    }

    /**
     * A recorrência acabou de abrir uma nova ocorrência e ainda não resetou o
     * contador hoje? Comparação por dia (não por horário exato) para tolerar
     * atraso do scheduler: assim que rodar depois do horário de início, reseta.
     */
    public function deveResetarAgora(?CarbonInterface $momento = null): bool
    {
        $momento ??= now();

        if (! $this->promocao_ativa || ! $this->promocao_recorrente) {
            return false;
        }

        if ($this->promocao_ultimo_reset_em?->isSameDay($momento)) {
            return false;
        }

        if (! $this->dentroDoIntervaloDeDatas($momento) || ! $this->diaDaSemanaBate($momento)) {
            return false;
        }

        if ($this->promocao_hora_inicio && $momento->format('H:i:s') < $this->promocao_hora_inicio->format('H:i:s')) {
            return false;
        }

        return true;
    }

    /**
     * Só mostra o contador ao cliente quando o admin pediu, a promoção tem teto
     * e o saldo já entrou no limiar de escassez. Sem limiar, mostra sempre —
     * "restam 38 de 40" não cria urgência nenhuma.
     */
    public function deveExibirContador(): bool
    {
        if (! $this->promocao_exibe_contador || $this->promocao_qtd_total === null) {
            return false;
        }

        if ($this->promocao_limiar_escassez === null) {
            return true;
        }

        return $this->saldoDisponivel() <= $this->promocao_limiar_escassez;
    }

    /**
     * Teto de sabores para um combo desta promoção. O limite da promoção nunca
     * pode exceder o da categoria, e produtos de categorias diferentes valem
     * pelo menor teto entre elas.
     *
     * @param  iterable<Produto>  $produtos
     */
    public function maxSaboresEfetivo(iterable $produtos): int
    {
        $tetos = [];

        foreach ($produtos as $produto) {
            $tetos[] = (int) ($produto->categoria?->categoria_max_sabores ?? 1);
        }

        if ($tetos === []) {
            return 1;
        }

        $tetoCategoria = min($tetos);

        return $this->promocao_max_sabores === null
            ? $tetoCategoria
            : min($this->promocao_max_sabores, $tetoCategoria);
    }
}
