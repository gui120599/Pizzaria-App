<?php

namespace App\Livewire;

use App\Models\Cliente;
use App\Services\IBGEServices;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Busca/seleção de cliente pro atendimento de pedido — telefone com busca
 * automática (mesma regra de PedidoController::resolverCliente()), CEP com
 * autopreenchimento (via IBGEServices::buscaCep(), mesmo padrão já usado em
 * ClienteForm), e opção de pedido sem cliente cadastrado (pedido_cliente_id
 * já é nullable, com withDefault() no relacionamento).
 *
 * Só entrega dados normalizados via evento — a resolução final (criar/
 * atualizar o Cliente de verdade) acontece no save() da Page pai, igual ao
 * resolverCliente() legado: achou por telefone -> atualiza nome/endereço
 * (nunca apaga endereço já salvo se vier em branco); não achou -> cria.
 */
class ClientePicker extends Component
{
    private const UFS = [
        'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
        'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
        'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
        'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
        'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
    ];

    public ?int $clienteId = null;

    public string $celular = '';

    public string $nome = '';

    public string $enderecoRua = '';

    public string $enderecoNumero = '';

    public string $enderecoBairro = '';

    public string $enderecoCidade = '';

    public string $enderecoUf = '';

    public string $enderecoCep = '';

    public bool $clienteEncontrado = false;

    public bool $cepNaoEncontrado = false;

    public bool $semCliente = false;

    public bool $buscaModalAberta = false;

    public string $buscaQuery = '';

    public function mount(array $inicial = []): void
    {
        $this->clienteId = $inicial['clienteId'] ?? null;
        $this->celular = $inicial['celular'] ?? '';
        $this->nome = $inicial['nome'] ?? '';
        $this->enderecoRua = $inicial['enderecoRua'] ?? '';
        $this->enderecoNumero = $inicial['enderecoNumero'] ?? '';
        $this->enderecoBairro = $inicial['enderecoBairro'] ?? '';
        $this->enderecoCidade = $inicial['enderecoCidade'] ?? '';
        $this->enderecoUf = $inicial['enderecoUf'] ?? '';
        $this->enderecoCep = $inicial['enderecoCep'] ?? '';
        $this->semCliente = $inicial['semCliente'] ?? false;
        $this->clienteEncontrado = (bool) $this->clienteId;
    }

    public function updatedCelular(): void
    {
        $normalizado = $this->normalizarTelefone($this->celular);

        if (strlen($normalizado) >= 10) {
            $this->buscarPorCelular($normalizado);
        } else {
            $this->clienteEncontrado = false;
            $this->clienteId = null;
        }

        $this->emitirMudanca();
    }

    private function buscarPorCelular(string $normalizado): void
    {
        $cliente = Cliente::where('cliente_celular', 'like', "%{$normalizado}%")->first();

        if (! $cliente) {
            $this->clienteEncontrado = false;
            $this->clienteId = null;

            return;
        }

        $this->preencherDoCliente($cliente);
    }

    private function preencherDoCliente(Cliente $cliente): void
    {
        $this->clienteId = $cliente->id;
        $this->clienteEncontrado = true;
        $this->nome = $cliente->cliente_nome;

        // Não sobrescreve o que o atendente já digitou nesta tela — só
        // preenche o que ainda está em branco (mesma regra de "não apagar
        // endereço já salvo" do resolverCliente() legado, na outra direção).
        $this->enderecoRua = $this->enderecoRua !== '' ? $this->enderecoRua : (string) ($cliente->cliente_endereco ?? '');
        $this->enderecoNumero = $this->enderecoNumero !== '' ? $this->enderecoNumero : (string) ($cliente->cliente_numero_endereco ?? '');
        $this->enderecoBairro = $this->enderecoBairro !== '' ? $this->enderecoBairro : (string) ($cliente->cliente_bairro ?? '');
        $this->enderecoCidade = $this->enderecoCidade !== '' ? $this->enderecoCidade : (string) ($cliente->cliente_cidade ?? '');
        $this->enderecoUf = $this->enderecoUf !== '' ? $this->enderecoUf : (string) ($cliente->cliente_uf_estado ?? '');
        $this->enderecoCep = $this->enderecoCep !== '' ? $this->enderecoCep : (string) ($cliente->cliente_cep ?? '');
    }

    public function selecionarDaBusca(int $clienteId): void
    {
        $cliente = Cliente::find($clienteId);

        if (! $cliente) {
            return;
        }

        $this->preencherDoCliente($cliente);
        $this->celular = (string) ($cliente->cliente_celular ?? '');
        $this->buscaModalAberta = false;
        $this->buscaQuery = '';
        $this->emitirMudanca();
    }

    #[Computed]
    public function resultadosBusca(): Collection
    {
        if (trim($this->buscaQuery) === '') {
            return collect();
        }

        $digitos = preg_replace('/\D/', '', $this->buscaQuery) ?? '';

        return Cliente::query()
            ->where('cliente_nome', 'like', "%{$this->buscaQuery}%")
            ->when($digitos !== '', fn ($query) => $query->orWhere('cliente_celular', 'like', "%{$digitos}%"))
            ->limit(10)
            ->get();
    }

    public function buscarPorCep(): void
    {
        $cep = preg_replace('/\D/', '', $this->enderecoCep) ?? '';
        $this->cepNaoEncontrado = false;

        if (strlen($cep) !== 8) {
            return;
        }

        $dados = IBGEServices::buscaCep($cep);

        if (empty($dados) || ! empty($dados['erro'])) {
            $this->cepNaoEncontrado = true;

            return;
        }

        $uf = strtoupper((string) ($dados['uf'] ?? ''));

        $this->enderecoRua = $this->enderecoRua !== '' ? $this->enderecoRua : (string) ($dados['logradouro'] ?? '');
        $this->enderecoBairro = $this->enderecoBairro !== '' ? $this->enderecoBairro : (string) ($dados['bairro'] ?? '');
        $this->enderecoCidade = $this->enderecoCidade !== '' ? $this->enderecoCidade : (string) ($dados['localidade'] ?? '');
        $this->enderecoUf = $this->enderecoUf !== '' ? $this->enderecoUf : $uf;

        $this->emitirMudanca();
    }

    public function updatedSemCliente(): void
    {
        if ($this->semCliente) {
            $this->clienteId = null;
            $this->clienteEncontrado = false;
        }

        $this->emitirMudanca();
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'endereco')) {
            $this->emitirMudanca();
        }
    }

    private function normalizarTelefone(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    /** Nome do estado a partir da UF, mesma tabela usada em ClienteForm — sem chamada de API a cada digitação. */
    public function nomeEstado(): ?string
    {
        return self::UFS[strtoupper($this->enderecoUf)] ?? null;
    }

    private function emitirMudanca(): void
    {
        $this->dispatch('pedido-cliente-atualizado', dados: [
            'clienteId' => $this->clienteId,
            'celular' => $this->celular,
            'nome' => $this->nome,
            'enderecoRua' => $this->enderecoRua,
            'enderecoNumero' => $this->enderecoNumero,
            'enderecoBairro' => $this->enderecoBairro,
            'enderecoCidade' => $this->enderecoCidade,
            'enderecoUf' => $this->enderecoUf,
            'enderecoCep' => $this->enderecoCep,
            'semCliente' => $this->semCliente,
        ]);
    }

    public function render()
    {
        return view('livewire.cliente-picker');
    }
}
