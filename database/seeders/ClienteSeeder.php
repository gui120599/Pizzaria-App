<?php

namespace Database\Seeders;

use App\Models\Cliente;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ClienteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Criar alguns clientes de exemplo
        Cliente::create([
            'cliente_nome' => 'João Silva',
            'cliente_data_nascimento' => '1990-05-15',
            'cliente_tipo' => 'Pessoa Física',
            'cliente_cpf' => '123.456.789-00',
            'cliente_rg' => '1234567',
            'cliente_celular' => '(12) 3456-7890',
            'cliente_email' => 'joao@example.com',
            'cliente_endereco' => 'Rua Principal, 123',
            'cliente_bairro' => 'Centro',
            'cliente_cidade' => 'São Paulo',
            'cliente_estado' => 'São Paulo',
            'cliente_uf_estado' => 'SP',
            'cliente_cep' => '12345-678',
        ]);

        Cliente::create([
            'cliente_nome' => 'Maria Oliveira',
            'cliente_data_nascimento' => '1985-08-20',
            'cliente_tipo' => 'Pessoa Física',
            'cliente_cpf' => '987.654.321-00',
            'cliente_rg' => '9876543',
            'cliente_celular' => '(11) 9876-5432',
            'cliente_email' => 'maria@example.com',
            'cliente_endereco' => 'Avenida Principal, 456',
            'cliente_bairro' => 'Centro',
            'cliente_cidade' => 'Rio de Janeiro',
            'cliente_estado' => 'Rio de Janeiro',
            'cliente_uf_estado' => 'RJ',
            'cliente_cep' => '54321-987',
        ]);
    }
}
