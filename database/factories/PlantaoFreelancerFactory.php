<?php

namespace Database\Factories;

use App\Models\PlantaoFreelancer;
use App\Models\User;
use App\Support\DiaOperacional;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantaoFreelancer>
 */
class PlantaoFreelancerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pl_user_id' => User::factory(),
            'pl_dia' => DiaOperacional::de()->toDateString(),
        ];
    }
}
