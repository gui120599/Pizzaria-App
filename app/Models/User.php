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

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(self::PANEL_ROLES);
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
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function balancos()
    {
        return $this->hasMany(MovimentacaoBalanco::class, 'mbal_usuario_id');
    }
}
