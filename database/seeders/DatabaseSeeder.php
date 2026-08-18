<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        $this->call([
            RoleSeeder::class,
            // Snapshot gerado por `php artisan shield:seeder --force` — permissions de
            // CRUD por Resource/Page/Widget do Filament + o que cada role tinha no
            // momento da geração. Regenerar sempre que Resources mudarem.
            ShieldSeeder::class,
            // PermissionSeeder roda por último: usa givePermissionTo (aditivo), garante
            // que as permissions de negócio mais atuais fiquem valendo mesmo que o
            // snapshot do ShieldSeeder esteja desatualizado.
            PermissionSeeder::class,
            // Permissions de CRUD de Resource/Page/Widget por Role (view_any/create/
            // update/delete de cada módulo) — sem isso o painel Filament fica vazio
            // pra todo mundo menos Admin. Também aditivo (givePermissionTo).
            ResourcePermissionSeeder::class,
            PlanoDespesaSeeder::class,
            PlanoReceitaSeeder::class,
            NotaMoedaSeeder::class,
        ]);
    }
}
