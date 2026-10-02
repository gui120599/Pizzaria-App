<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'garcom') {
            return $this->hasAnyRole(self::GARCOM_PANEL_ROLES);
        }

        return $this->hasAnyRole(self::PANEL_ROLES);
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
}
