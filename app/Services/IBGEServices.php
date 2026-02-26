<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class IBGEServices
{
    public static function buscaCep(string $search): array
    {
        if (empty($search)) {
            return ['error' => 'CEP obrigatorio'];
        }
        $reponse = Http::withHeaders([
            'Accept' => 'application/json',
        ])
            ->get('https://viacep.com.br/ws/' . $search . '/json/');
        if ($reponse->failed()) {
            return [];
        }
        return $reponse->json() ?? [];
    }

    public static function ufs(): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
        ])
            ->get('https://servicodados.ibge.gov.br/api/v1/localidades/estados');

        // Verifica se a requisição falhou e retorna um array vazio.
        if ($response->failed()) {
            return [];
        }

        // Decodifica a resposta JSON para um array PHP.
        $estados = $response->json();

        // Prepara o array no formato esperado pelo Filament: ['sigla' => 'nome']
        $opcoes = [];

        if (is_array($estados)) {
            foreach ($estados as $estado) {
                // Usa 'sigla' como chave (o valor que será salvo) e 'nome' como label.
                $opcoes[$estado['sigla']] = $estado['nome'];
            }
        }

        // Retorna o array formatado (ex: ['SP' => 'São Paulo', 'RJ' => 'Rio de Janeiro'])
        return $opcoes;
    }

    public static function cidadesPorUf(string $uf): array
    {
        // A API do IBGE para cidades exige a sigla da UF na URL.
        $response = Http::withHeaders([
            'Accept' => 'application/json',
        ])
            // Note o uso da variável $uf na URL (interpolação de string)
            ->get("https://servicodados.ibge.gov.br/api/v1/localidades/estados/{$uf}/municipios?orderBy=nome");

        if ($response->failed()) {
            return [];
        }

        $cidades = $response->json();
        $opcoes = [];

        if (is_array($cidades)) {
            foreach ($cidades as $cidade) {
                // Usamos o 'id' do município como valor (chave) e o 'nome' como label de exibição.
                $opcoes[$cidade['nome']] = $cidade['nome'];
            }
        }

        // Retorna o array formatado (ex: [1234567 => 'São Gonçalo'])
        return $opcoes;
    }
}