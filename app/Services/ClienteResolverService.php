<?php

namespace App\Services;

use App\Models\Cliente;
use App\Support\Telefone;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Ponto único de busca-ou-criação de Cliente, usado por todos os fluxos que
 * cadastram cliente "no ato" (cardápio público, pedido de balcão, sessão de
 * mesa, atendimento de pedido no Filament). Antes desta classe, cada fluxo
 * reimplementava a mesma busca por celular (às vezes por LIKE, sem checar
 * CPF/CNPJ), o que deixava fácil duplicar cadastro. Não trata o caso de
 * "cliente já selecionado por id" — isso é seleção direta, não dedupe, e
 * continua sendo responsabilidade de cada chamador antes de cair aqui.
 *
 * O celular é comparado pela chave normalizada (App\Support\Telefone): o
 * mesmo número com máscara, +55, zero do DDD ou sem o nono dígito encontra o
 * mesmo cadastro. A busca + criação roda sob lock da chave, para dois envios
 * simultâneos do mesmo número novo não criarem dois clientes.
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
        $celular = Telefone::normalizar($dados['celular'] ?? null);
        $cpf = $this->digitos($dados['cpf'] ?? null);
        $cnpj = $this->digitos($dados['cnpj'] ?? null);
        $cep = $this->digitos($dados['cep'] ?? null);

        return $this->comLockDoCelular(
            $celular,
            fn (): Cliente => $this->resolverComChaves($dados, $nome, $cpf, $cnpj, $celular, $cep),
        );
    }

    /**
     * Roda a busca + criação de cliente com exclusividade para o celular
     * (qualquer formato equivalente): um segundo envio simultâneo do mesmo
     * número espera o primeiro e encontra o cadastro já criado.
     *
     * @template T
     *
     * @param  Closure(): T  $acao
     * @return T
     */
    public function comLockDoCelular(?string $celular, Closure $acao): mixed
    {
        $chave = Telefone::normalizar($celular);

        if ($chave === null) {
            return $acao();
        }

        try {
            return Cache::lock('cliente-celular:'.$chave, 10)->block(5, $acao);
        } catch (LockTimeoutException) {
            // Melhor atender com risco mínimo de duplicar do que travar o atendimento.
            return $acao();
        }
    }

    /**
     * Cliente (não avulso) com este celular em qualquer formato equivalente, ou
     * null. Se houver mais de um, fica o cadastro mais antigo — juntar os demais
     * é decisão do administrador (Clientes › Duplicidades por celular).
     */
    public function buscarPorCelular(?string $celular): ?Cliente
    {
        $chave = Telefone::normalizar($celular);

        if ($chave === null) {
            return null;
        }

        return Cliente::naoAvulsos()
            ->where('cliente_celular_normalizado', $chave)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function resolverComChaves(array $dados, string $nome, ?string $cpf, ?string $cnpj, ?string $celular, ?string $cep): Cliente
    {
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
            return $this->buscarPorCelular($celular);
        }

        return null;
    }

    private function digitos(?string $valor): ?string
    {
        return Telefone::digitos($valor);
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
