<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Roles operacionais que dão acesso ao painel /admin. Cliente fica de fora
     * de propósito: hoje é role vestigial do cadastro público no cardápio, não
     * um perfil que deveria entrar no back office.
     */
    private const PANEL_ROLES = ['Admin', 'Gerente', 'Atendente', 'Caixa', 'Entregador'];

    /** Roles que operam o salão pelo Painel do Garçom (/garcom). */
    public const GARCOM_PANEL_ROLES = ['Admin', 'Gerente', 'Atendente', 'Garcom'];

    /** Roles que entram no /garcom só para executar checklists, sem as telas de mesa. */
    public const CHECKLIST_PANEL_ROLES = ['Cozinha', 'Caixa'];

    /** Roles do painel da cozinha (/cozinha): tablets (EstacaoCozinha) e quem produz/despacha. */
    public const COZINHA_PANEL_ROLES = ['Admin', 'Gerente', 'Cozinha', 'Expedicao', 'EstacaoCozinha'];

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'cozinha') {
            return $this->hasAnyRole(self::COZINHA_PANEL_ROLES);
        }

        if ($panel->getId() === 'garcom') {
            // Colaborador da folha entra para bater ponto, ver espelho e repasses, mesmo sem papel do salão.
            return $this->hasAnyRole([...self::GARCOM_PANEL_ROLES, ...self::CHECKLIST_PANEL_ROLES])
                || $this->colaborador()->exists();
        }

        return $this->hasAnyRole(self::PANEL_ROLES);
    }

    /** Opera mesas/comandas/retiradas no /garcom (e não só checklists). */
    public function atendeSalao(): bool
    {
        return $this->hasAnyRole(self::GARCOM_PANEL_ROLES);
    }

    /** Entra no /garcom só como colaborador da folha (ponto, espelho, repasses). */
    public function soBatePonto(): bool
    {
        return ! $this->hasAnyRole([...self::GARCOM_PANEL_ROLES, ...self::CHECKLIST_PANEL_ROLES]);
    }

    public function temPin(): bool
    {
        return filled($this->user_pin);
    }

    public function conferePin(string $pin): bool
    {
        return $this->temPin() && Hash::check($pin, $this->user_pin);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'name_first',
        'email',
        'password',
        'name_first',
        'avatar',
        'user_pin',
    ];

    /**
     * Determine if the user can access Filament.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar
            ? Storage::url($this->avatar)
            : null;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'user_pin',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'user_pin' => 'hashed',
        'preferencias' => 'array',
    ];

    public function balancos()
    {
        return $this->hasMany(MovimentacaoBalanco::class, 'mbal_usuario_id');
    }

    /** Cadastro de colaborador da folha de ponto vinculado a este login. */
    public function colaborador(): HasOne
    {
        return $this->hasOne(Colaborador::class, 'user_id');
    }
}
