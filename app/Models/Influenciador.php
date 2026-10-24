<?php

namespace App\Models;

use App\Enums\StatusComissaoEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class Influenciador extends Authenticatable implements FilamentUser, HasName
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * HasRoles não é usado para RBAC de verdade aqui (Influenciador nunca tem
     * role atribuída) — é só pra satisfazer o Gate::before global que o
     * FilamentShield registra (chama hasRole() incondicionalmente em
     * qualquer usuário autenticado, de qualquer guard). Sem essa trait,
     * qualquer ação autorizada via Gate no painel /influenciadores quebra
     * com "Call to undefined method hasRole()".
     */
    protected $guard_name = 'influenciador';

    protected $table = 'influenciadores';

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->influenciador_status === StatusInfluenciadorEnum::ATIVO;
    }

    public function getFilamentName(): string
    {
        return $this->influenciador_nome;
    }

    /**
     * influenciador_status é fillable aqui porque o único Resource que expõe
     * esse campo no form (InfluenciadorResource, painel /admin) é protegido
     * por InfluenciadorPolicy (só Admin/Gerente). O autocadastro público
     * (Filament\Influenciador\Pages\Auth\Register) NÃO passa esse campo pelo
     * form — ele força PENDENTE via forceFill() separado, então não reabre
     * brecha nenhuma pro autocadastro se autopromover.
     */
    protected $fillable = [
        'influenciador_nome',
        'email',
        'password',
        'influenciador_whatsapp',
        'influenciador_instagram',
        'influenciador_chave_pix',
        'influenciador_cpf',
        'influenciador_status',
        'influenciador_cupom_principal_id',
        'influenciador_deve_trocar_senha',
        'influenciador_tipo_comissao',
        'influenciador_valor_comissao',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'influenciador_status' => StatusInfluenciadorEnum::class,
            'influenciador_tipo_comissao' => TipoDescontoEnum::class,
            'influenciador_valor_comissao' => 'decimal:2',
            'influenciador_cpf' => 'encrypted',
            'influenciador_regulamento_aceito_em' => 'datetime',
            'influenciador_deve_trocar_senha' => 'boolean',
            'influenciador_chave_pix' => 'encrypted',
        ];
    }

    public function cupons()
    {
        return $this->hasMany(Cupom::class, 'cupom_influenciador_id');
    }

    public function cupomPrincipal()
    {
        return $this->belongsTo(Cupom::class, 'influenciador_cupom_principal_id');
    }

    public function comissoes()
    {
        return $this->hasMany(Comissao::class, 'comissao_influenciador_id');
    }

    public function movimentosCarteira()
    {
        return $this->hasMany(CarteiraMovimento::class, 'cm_influenciador_id');
    }

    public function resgates()
    {
        return $this->hasMany(ResgateCarteira::class, 'rc_influenciador_id');
    }

    public function prestador()
    {
        return $this->belongsTo(Prestador::class, 'influenciador_prestador_id');
    }

    /** CPF fica só criptografado; o hash (HMAC dos dígitos) dá unicidade e busca. */
    protected static function booted(): void
    {
        static::saving(function (Influenciador $influenciador): void {
            if ($influenciador->isDirty('influenciador_whatsapp')) {
                $influenciador->influenciador_whatsapp = self::normalizarWhatsapp($influenciador->influenciador_whatsapp);
            }

            if ($influenciador->isDirty('influenciador_cpf')) {
                $digitos = preg_replace('/\D/', '', (string) $influenciador->influenciador_cpf) ?: null;
                $influenciador->influenciador_cpf = $digitos;
                $influenciador->influenciador_cpf_hash = $digitos === null ? null : Colaborador::hashCpf($digitos);
            }
        });
    }

    /** Só dígitos, com DDI 55 quando vier só DDD + número: "(64) 98138-0071" → "5564981380071". */
    public static function normalizarWhatsapp(?string $numero): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $numero);

        if ($digitos === '') {
            return null;
        }

        return in_array(strlen($digitos), [10, 11], true) ? '55'.$digitos : $digitos;
    }

    /** O regulamento em vigor ainda não foi aceito (ou há versão mais nova que a aceita). */
    public function precisaAceitarRegulamento(): bool
    {
        $atual = RegulamentoInfluenciador::atual();

        return $atual !== null && (int) $this->influenciador_regulamento_versao < $atual->ri_versao;
    }

    /** Grava o aceite da versão atual, com data e IP. */
    public function aceitarRegulamento(?string $ip): void
    {
        $atual = RegulamentoInfluenciador::atual();

        if ($atual === null) {
            return;
        }

        $this->forceFill([
            'influenciador_regulamento_versao' => $atual->ri_versao,
            'influenciador_regulamento_aceito_em' => now(),
            'influenciador_regulamento_aceito_ip' => $ip,
        ])->save();
    }

    /** Saldo da carteira: soma do extrato (append-only). */
    public function saldoCarteira(): float
    {
        return round((float) $this->movimentosCarteira()->sum('cm_valor'), 2);
    }

    /**
     * Motivo pelo qual o cadastro não pode ser excluído, ou null se pode.
     * Bloqueia com comissão pendente (pedido ainda não pago) ou saldo diferente de zero.
     */
    public function motivoBloqueioExclusao(): ?string
    {
        if ($this->comissoes()->where('comissao_status', StatusComissaoEnum::PENDENTE)->exists()) {
            return 'O influenciador tem comissão pendente (pedido ainda não pago).';
        }

        if (abs($this->saldoCarteira()) >= 0.01) {
            return 'A carteira do influenciador tem saldo diferente de zero.';
        }

        return null;
    }
}
