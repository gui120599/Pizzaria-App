<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Estados nomeados por role, pra testes que precisam de um usuário com
     * acesso real ao painel /admin (canAccessPanel() exige role operacional).
     */
    public function admin(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('Admin'));
    }

    public function gerente(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('Gerente'));
    }

    public function atendente(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('Atendente'));
    }

    public function caixa(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('Caixa'));
    }

    public function entregador(): static
    {
        return $this->afterCreating(fn ($user) => $user->assignRole('Entregador'));
    }
}
