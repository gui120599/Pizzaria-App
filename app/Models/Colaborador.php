<?php

namespace App\Models;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Enums\Ponto\VinculoColaborador;
use App\Enums\TipoChavePix;
use App\Models\Concerns\TemHistoricoPonto;
use App\Observers\ColaboradorObserver;
use App\Observers\PontoAuditoriaObserver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

/**
 * Colaborador da folha de ponto. "Ativo" é derivado de data_desligamento.
 * O CPF fica só criptografado; cpf_hash (HMAC dos dígitos) dá busca e
 * unicidade sem decriptar.
 */
#[ObservedBy([PontoAuditoriaObserver::class, ColaboradorObserver::class])]
class Colaborador extends Model
{
    use HasFactory, SoftDeletes, TemHistoricoPonto;

    protected $table = 'colaboradores';

    protected $fillable = [
        'user_id',
        'nome',
        'cpf',
        'cargo',
        'setor',
        'vinculo',
        'periodicidade_repasse',
        'data_admissao',
        'data_desligamento',
        'tipo_chave_pix',
        'chave_pix',
        'pin_quiosque',
        'entregador',
    ];

    protected $hidden = [
        'cpf',
        'cpf_hash',
        'chave_pix',
        'pin_quiosque',
        'pin_lookup',
    ];

    protected function casts(): array
    {
        return [
            'cpf' => 'encrypted',
            'vinculo' => VinculoColaborador::class,
            'periodicidade_repasse' => PeriodicidadeRepasse::class,
            'data_admissao' => 'date',
            'data_desligamento' => 'date',
            'tipo_chave_pix' => TipoChavePix::class,
            'chave_pix' => 'encrypted',
            'entregador' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Colaborador $colaborador): void {
            if ($colaborador->isDirty('cpf')) {
                $digitos = self::somenteDigitos($colaborador->cpf);
                $colaborador->cpf = $digitos;
                $colaborador->cpf_hash = $digitos === null ? null : self::hashCpf($digitos);
            }
        });
    }

    /**
     * PIN do quiosque: grava o bcrypt (conferência) e o HMAC (busca pelo PIN
     * digitado no tablet e unicidade). Nulo apaga os dois.
     */
    protected function pinQuiosque(): Attribute
    {
        return Attribute::make(
            set: fn (?string $pin): array => blank($pin)
                ? ['pin_quiosque' => null, 'pin_lookup' => null]
                : ['pin_quiosque' => Hash::make($pin), 'pin_lookup' => self::hashPin($pin)],
        );
    }

    public static function hashPin(string $pin): string
    {
        return hash_hmac('sha256', 'pin:'.$pin, (string) config('app.key'));
    }

    /** HMAC-SHA256 dos dígitos do CPF com a APP_KEY (CPF puro em SHA-256 seria reversível por força bruta). */
    public static function hashCpf(string $cpf): string
    {
        return hash_hmac('sha256', (string) self::somenteDigitos($cpf), (string) config('app.key'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Favorecido espelho no contas a pagar (mantido pelo observer do colaborador). */
    public function prestador(): HasOne
    {
        return $this->hasOne(Prestador::class, 'colaborador_id');
    }

    public function remuneracoes(): HasMany
    {
        return $this->hasMany(ColaboradorRemuneracao::class, 'colaborador_id')->orderByDesc('vigente_desde');
    }

    public function escalas(): HasMany
    {
        return $this->hasMany(ColaboradorEscala::class, 'colaborador_id')->orderByDesc('vigente_desde');
    }

    public function marcacoes(): HasMany
    {
        return $this->hasMany(PontoMarcacao::class, 'colaborador_id');
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(PontoAjuste::class, 'colaborador_id');
    }

    public function dias(): HasMany
    {
        return $this->hasMany(PontoDia::class, 'colaborador_id');
    }

    public function repasses(): HasMany
    {
        return $this->hasMany(Repasse::class, 'colaborador_id');
    }

    public function adiantamentos(): HasMany
    {
        return $this->hasMany(Adiantamento::class, 'colaborador_id');
    }

    /** Sem desligamento, ou desligado depois da data (padrão: hoje). */
    public function scopeAtivos(Builder $query, ?CarbonInterface $data = null): Builder
    {
        $dia = ($data ?? now())->toDateString();

        return $query->where(fn (Builder $q) => $q
            ->whereNull('data_desligamento')
            ->orWhereDate('data_desligamento', '>=', $dia));
    }

    /** Da periodicidade do período e ativos em algum dia dele (admitidos até o fim, não desligados antes do início). */
    public function scopeDoPeriodo(Builder $query, PontoPeriodo $periodo): Builder
    {
        return $query
            ->where('periodicidade_repasse', $periodo->periodicidade)
            ->whereDate('data_admissao', '<=', $periodo->data_fim->toDateString())
            ->where(fn (Builder $q) => $q
                ->whereNull('data_desligamento')
                ->orWhereDate('data_desligamento', '>=', $periodo->data_inicio->toDateString()));
    }

    public function estaAtivoEm(?CarbonInterface $data = null): bool
    {
        $dia = ($data ?? now())->copy()->startOfDay();

        return $this->data_admissao->lte($dia)
            && ($this->data_desligamento === null || $this->data_desligamento->gte($dia));
    }

    public function remuneracaoVigente(CarbonInterface $data): ?ColaboradorRemuneracao
    {
        return $this->remuneracoes()->vigenteEm($data)->first();
    }

    public function escalaVigente(CarbonInterface $data): ?Escala
    {
        return $this->escalas()->vigenteEm($data)->with('escala.dias')->first()?->escala;
    }

    /** Colaborador ativo dono do PIN (quiosque). */
    public static function doPin(string $pin): ?self
    {
        $colaborador = self::query()->ativos()->where('pin_lookup', self::hashPin($pin))->first();

        return $colaborador?->conferePin($pin) ? $colaborador : null;
    }

    public function conferePin(string $pin): bool
    {
        return filled($this->pin_quiosque) && Hash::check($pin, $this->pin_quiosque);
    }

    private static function somenteDigitos(?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        return $digitos === '' ? null : $digitos;
    }
}
