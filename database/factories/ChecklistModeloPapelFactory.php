<?php

namespace Database\Factories;

use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloPapel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<ChecklistModeloPapel>
 */
class ChecklistModeloPapelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_modelo_id' => ChecklistModelo::factory(),
            'role_id' => fn () => Role::firstOrCreate(['name' => 'Garcom', 'guard_name' => 'web'])->id,
        ];
    }
}
