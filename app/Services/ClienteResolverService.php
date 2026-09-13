<?php

namespace App\Services;

use App\Models\Cliente;

/**
 * Ponto único de busca-ou-criação de Cliente, usado por todos os fluxos que
 * cadastram cliente "no ato" (cardápio público, pedido de balcão, sessão de
 * mesa, atendimento de pedido no Filament). Antes desta classe, cada fluxo
 * reimplementava a mesma busca por celular (às vezes por LIKE, sem checar
 * CPF/CNPJ), o que deixava fácil duplicar cadastro. Não trata o caso de
 * "cliente já selecionado por id" — isso é seleção direta, não dedupe, e
 * continua sendo responsabilidade de cada chamador antes de cair aqui.
 */
class ClienteResolverService
{
    private const CAMPOS_TEXTO_LIVRE = [
        'email' => 'cliente_email',
        'endereco' => 'cliente_endereco',
        'numero_endereco' => 'cliente_numero_endereco',
        'bairro' => 'cliente_bairro',
        'cidade' => 'cliente_cidade',
        'uf_estado' => 'cliente_uf_estado',
    ];

    /**
     * @param  array{nome?: ?string, celular?: ?string, cpf?: ?string, cnpj?: ?string, email?: ?string, tipo?: ?string, endereco?: ?string, numero_endereco?: ?string, bairro?: ?string, cidade?: ?string, uf_estado?: ?string, cep?: ?string}  $dados
     */
    public function resolverOuCriar(array $dados): Cliente
    {
        $nome = trim((string) ($dados['nome'] ?? ''));
        $celular = $this->digitos($dados['celular'] ?? null);
        $cpf = $this->digitos($dados['cpf'] ?? null);
        $cnpj = $this->digitos($dados['cnpj'] ?? null);
        $cep = $this->digitos($dados['cep'] ?? null);

        $cliente = $this->buscar($cpf, $cnpj, $celular);

        $novosValores = array_filter(array_merge(
            [
                'cliente_celular' => $celular,
                'cliente_cpf' => $cpf,
                'cliente_cnpj' => $cnpj,
                'cliente_cep' => $cep,
            ],
            collect(self::CAMPOS_TEXTO_LIVRE)
                ->mapWithKeys(fn (string $coluna, string $chave) => [
                    $coluna => $this->presente($dados[$chave] ?? null),
                ])
                ->all(),
        ), fn ($v) => $v !== null);

        if ($cliente) {
            // Sobrescreve com o que veio informado agora (mesmo comportamento dos
            // fluxos legados: nome e endereço sempre refletem o que a pessoa acabou
            // de digitar); campo não informado nesta chamada nunca é apagado.
            $update = $novosValores;
            if ($nome !== '') {
                $update['cliente_nome'] = $nome;
            }
            if ($update !== []) {
                $cliente->update($update);
            }

            return $cliente;
        }

        if ($nome === '') {
            throw new \InvalidArgumentException('Nome é obrigatório para cadastrar um novo cliente.');
        }

        return Cliente::create(array_merge([
            'cliente_nome' => $nome,
            'cliente_tipo' => $this->normalizarTipo($dados['tipo'] ?? null, $cnpj),
        ], $novosValores));
    }

    private function buscar(?string $cpf, ?string $cnpj, ?string $celular): ?Cliente
    {
        if ($cpf && $cliente = Cliente::where('cliente_cpf', $cpf)->first()) {
            return $cliente;
        }
        if ($cnpj && $cliente = Cliente::where('cliente_cnpj', $cnpj)->first()) {
            return $cliente;
        }
        if ($celular) {
            return Cliente::where('cliente_celular', $celular)->first();
        }

        return null;
    }

    private function digitos(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $digitos = preg_replace('/\D/', '', $valor) ?? '';

        return $digitos !== '' ? $digitos : null;
    }

    private function presente(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor !== '' ? $valor : null;
    }

    private function normalizarTipo(?string $tipo, ?string $cnpj): string
    {
        if ($cnpj) {
            return 'Jurídica';
        }

        return ($tipo && str_starts_with(mb_strtoupper($tipo), 'J')) ? 'Jurídica' : 'Física';
    }
}
