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
        'influenciador_status',
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
        ];
    }

    public function cupons()
    {
        return $this->hasMany(Cupom::class, 'cupom_influenciador_id');
    }

    public function comissoes()
    {
        return $this->hasMany(Comissao::class, 'comissao_influenciador_id');
    }

    public function pagamentosComissao()
    {
        return $this->hasMany(PagamentoComissao::class, 'pgcom_influenciador_id');
    }

    /**
     * Motivo pelo qual o cadastro não pode ser excluído, ou null se pode.
     * Hoje: comissão ainda não paga. A Fase 2 acrescenta saldo de carteira.
     */
    public function motivoBloqueioExclusao(): ?string
    {
        $temComissaoEmAberto = $this->comissoes()
            ->whereIn('comissao_status', [StatusComissaoEnum::PENDENTE, StatusComissaoEnum::APROVADA])
            ->exists();

        return $temComissaoEmAberto
            ? 'O influenciador tem comissão pendente ou aprovada ainda não paga.'
            : null;
    }
}
