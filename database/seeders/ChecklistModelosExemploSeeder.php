<?php

namespace Database\Seeders;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExigenciaFoto;
use App\Enums\Checklist\TipoResposta;
use App\Models\ChecklistModelo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Modelos de exemplo da pizzaria (docs/modulo-checklists.md §8.1): abertura
 * e fechamento de cozinha e salão. Idempotente: modelo com o mesmo nome
 * (mesmo excluído) não é recriado nem sobrescrito, para não desfazer ajustes
 * feitos pelo gestor.
 *
 *   php artisan db:seed --class=ChecklistModelosExemploSeeder
 */
class ChecklistModelosExemploSeeder extends Seeder
{
    private const TODOS_OS_DIAS = [1, 2, 3, 4, 5, 6, 7];

    public function run(): void
    {
        foreach ($this->modelos() as $definicao) {
            if (ChecklistModelo::withTrashed()->where('nome', $definicao['nome'])->exists()) {
                continue;
            }

            DB::transaction(fn () => $this->criar($definicao));
        }
    }

    /** @param  array<string, mixed>  $definicao */
    private function criar(array $definicao): void
    {
        $modelo = ChecklistModelo::create([
            'nome' => $definicao['nome'],
            'tipo' => $definicao['tipo'],
            'setor' => $definicao['setor'],
            'dias_semana' => self::TODOS_OS_DIAS,
            'janela_inicio' => $definicao['janela'][0],
            'janela_fim' => $definicao['janela'][1],
            'tolerancia_minutos' => (int) config('checklists.tolerancia_padrao'),
            'exige_aprovacao' => $definicao['exige_aprovacao'] ?? false,
            'ativo' => true,
        ]);

        foreach ($definicao['papeis'] as $papel) {
            $modelo->papeis()->create([
                'role_id' => Role::firstOrCreate(['name' => $papel, 'guard_name' => 'web'])->id,
            ]);
        }

        $ordemSecao = 0;

        foreach ($definicao['secoes'] as $titulo => $itens) {
            $secao = $modelo->secoes()->create(['titulo' => $titulo, 'ordem' => ++$ordemSecao]);

            foreach (array_values($itens) as $indice => $item) {
                $secao->itens()->create([
                    'checklist_modelo_id' => $modelo->id,
                    'titulo' => $item['titulo'],
                    'tipo_resposta' => $item['tipo'] ?? TipoResposta::Check,
                    'opcoes' => $item['opcoes'] ?? null,
                    'resposta_esperada' => $item['esperada'] ?? null,
                    'valor_minimo' => $item['minimo'] ?? null,
                    'valor_maximo' => $item['maximo'] ?? null,
                    'unidade' => $item['unidade'] ?? null,
                    'obrigatorio' => $item['obrigatorio'] ?? true,
                    'critico' => $item['critico'] ?? false,
                    'exige_foto' => $item['foto'] ?? ExigenciaFoto::Nunca,
                    'exige_observacao_nao_conforme' => true,
                    'ordem' => $indice + 1,
                ]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function modelos(): array
    {
        $camaraFria = ['titulo' => 'Temperatura da câmara fria', 'tipo' => TipoResposta::Temperatura, 'minimo' => 0, 'maximo' => 5, 'unidade' => '°C', 'critico' => true, 'foto' => ExigenciaFoto::SeNaoConforme];
        $freezer = ['titulo' => 'Temperatura do freezer', 'tipo' => TipoResposta::Temperatura, 'minimo' => -25, 'maximo' => -18, 'unidade' => '°C', 'critico' => true];
        $maquinasDeCartao = [
            ['valor' => 'ok', 'rotulo' => 'OK', 'conforme' => true],
            ['valor' => 'bateria_baixa', 'rotulo' => 'Bateria baixa', 'conforme' => false],
            ['valor' => 'sem_sinal', 'rotulo' => 'Sem sinal', 'conforme' => false],
        ];

        return [
            [
                'nome' => 'Abertura — Cozinha',
                'tipo' => ChecklistTipo::Abertura,
                'setor' => ChecklistSetor::Cozinha,
                'janela' => ['16:00', '18:30'],
                'papeis' => ['Cozinha', 'Gerente'],
                'secoes' => [
                    'Equipamentos' => [
                        ['titulo' => 'Forno ligado e pré-aquecido', 'critico' => true],
                        $camaraFria,
                        $freezer,
                        ['titulo' => 'Gás aberto e sem vazamento', 'tipo' => TipoResposta::SimNao, 'esperada' => 'sim', 'critico' => true],
                    ],
                    'Pré-preparo' => [
                        ['titulo' => 'Massas porcionadas para o dia', 'tipo' => TipoResposta::Numero, 'minimo' => 40, 'unidade' => 'un'],
                        ['titulo' => 'Molho de tomate pronto'],
                        ['titulo' => 'Insumos com etiqueta de validade', 'tipo' => TipoResposta::SimNao, 'esperada' => 'sim'],
                    ],
                    'Higiene' => [
                        ['titulo' => 'Bancadas higienizadas', 'foto' => ExigenciaFoto::Sempre],
                        ['titulo' => 'Uniforme e touca da equipe'],
                    ],
                ],
            ],
            [
                'nome' => 'Abertura — Salão',
                'tipo' => ChecklistTipo::Abertura,
                'setor' => ChecklistSetor::Salao,
                'janela' => ['17:00', '18:45'],
                'papeis' => ['Garcom', 'Gerente'],
                'secoes' => [
                    'Salão' => [
                        ['titulo' => 'Mesas e cadeiras limpas e alinhadas'],
                        ['titulo' => 'Banheiros abastecidos (papel, sabão)'],
                        ['titulo' => 'Cardápios e QR Codes nas mesas'],
                        ['titulo' => 'Máquinas de cartão carregadas', 'tipo' => TipoResposta::Selecao, 'opcoes' => $maquinasDeCartao],
                        ['titulo' => 'Iluminação e som ligados'],
                        ['titulo' => 'Bebidas geladas repostas'],
                    ],
                ],
            ],
            [
                'nome' => 'Fechamento — Cozinha',
                'tipo' => ChecklistTipo::Fechamento,
                'setor' => ChecklistSetor::Cozinha,
                'janela' => ['22:30', '01:30'],
                'papeis' => ['Cozinha', 'Gerente'],
                'exige_aprovacao' => true,
                'secoes' => [
                    'Equipamentos' => [
                        ['titulo' => 'Forno desligado', 'critico' => true],
                        ['titulo' => 'Gás fechado', 'tipo' => TipoResposta::SimNao, 'esperada' => 'sim', 'critico' => true, 'foto' => ExigenciaFoto::Sempre],
                        $camaraFria,
                        $freezer,
                    ],
                    'Limpeza e armazenamento' => [
                        ['titulo' => 'Sobras armazenadas e etiquetadas'],
                        ['titulo' => 'Lixo retirado'],
                        ['titulo' => 'Piso e coifa limpos', 'foto' => ExigenciaFoto::Sempre],
                    ],
                    'Passagem de turno' => [
                        ['titulo' => 'Observações para a abertura', 'tipo' => TipoResposta::Texto, 'obrigatorio' => false],
                    ],
                ],
            ],
            [
                'nome' => 'Fechamento — Salão',
                'tipo' => ChecklistTipo::Fechamento,
                'setor' => ChecklistSetor::Salao,
                'janela' => ['22:30', '01:30'],
                'papeis' => ['Garcom', 'Gerente'],
                'secoes' => [
                    'Salão' => [
                        ['titulo' => 'Mesas limpas'],
                        ['titulo' => 'Ar-condicionado e luzes desligados'],
                        ['titulo' => 'Portas e janelas trancadas', 'critico' => true],
                        ['titulo' => 'Máquinas de cartão no carregador'],
                    ],
                ],
            ],
        ];
    }
}
