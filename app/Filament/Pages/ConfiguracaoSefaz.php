<?php

namespace App\Filament\Pages;

use App\Exceptions\SefazAutenticacaoException;
use App\Models\Empresa;
use App\Services\Sefaz\SefazCertificadoService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Página singleton (não é Resource — não há lista/múltiplos registros) pra
 * configurar o certificado digital A1 usado nas consultas à SEFAZ. Sempre
 * opera sobre Empresa::firstOrFail(), o mesmo padrão de singleton já usado
 * em todo o resto do sistema.
 */
class ConfiguracaoSefaz extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Certificado SEFAZ';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?string $title = 'Certificado digital SEFAZ';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $empresa = Empresa::firstOrFail();

        $this->form->fill([
            'empresa_sefaz_ambiente' => $empresa->empresa_sefaz_ambiente,
            'empresa_sefaz_auto_importacao_ativa' => $empresa->empresa_sefaz_auto_importacao_ativa,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Certificado digital (A1)')
                    ->description('Necessário pra autenticar as consultas de NF-e na SEFAZ.')
                    ->columns(1)
                    ->schema([
                        Placeholder::make('status_certificado')
                            ->label('Situação atual')
                            ->content(fn () => $this->descricaoStatusCertificado(Empresa::firstOrFail())),

                        FileUpload::make('novo_certificado')
                            ->label('Novo arquivo do certificado (.pfx)')
                            ->disk('local')
                            ->directory('certificados/tmp')
                            ->acceptedFileTypes(['application/x-pkcs12', '.pfx', '.p12'])
                            ->helperText('Deixe em branco para manter o certificado já cadastrado.'),

                        TextInput::make('nova_senha')
                            ->label('Senha do certificado')
                            ->password()
                            ->revealable()
                            ->helperText('Só preencha se estiver enviando um certificado novo.'),
                    ]),

                Section::make('Consulta automática')
                    ->columns(1)
                    ->schema([
                        Select::make('empresa_sefaz_ambiente')
                            ->label('Ambiente')
                            ->options([
                                'homologacao' => 'Homologação',
                                'producao' => 'Produção',
                            ])
                            ->required(),

                        Toggle::make('empresa_sefaz_auto_importacao_ativa')
                            ->label('Buscar novas notas automaticamente')
                            ->helperText('Consulta a SEFAZ a cada hora e importa como rascunho de compra as notas novas emitidas contra o CNPJ da empresa.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SefazCertificadoService $certificados): void
    {
        $empresa = Empresa::firstOrFail();
        $state = $this->form->getState();

        $atualizacoes = [
            'empresa_sefaz_ambiente' => $state['empresa_sefaz_ambiente'],
            'empresa_sefaz_auto_importacao_ativa' => (bool) $state['empresa_sefaz_auto_importacao_ativa'],
        ];

        $novoArquivo = $state['novo_certificado'] ?? null;
        $novaSenha = $state['nova_senha'] ?? null;

        if (filled($novoArquivo)) {
            if (blank($novaSenha)) {
                Notification::make()
                    ->title('Informe a senha do certificado')
                    ->body('Pra trocar o certificado, preencha a senha correspondente.')
                    ->danger()
                    ->send();

                return;
            }

            $conteudo = Storage::disk('local')->get($novoArquivo);

            try {
                $metadados = $certificados->validarEExtrairMetadados($conteudo, $novaSenha);
            } catch (SefazAutenticacaoException) {
                Storage::disk('local')->delete($novoArquivo);

                Notification::make()
                    ->title('Não foi possível abrir o certificado')
                    ->body('Confira a senha e o arquivo enviado.')
                    ->danger()
                    ->send();

                return;
            }

            if ($empresa->empresa_certificado_path) {
                Storage::disk('local')->delete($empresa->empresa_certificado_path);
            }

            $caminhoDefinitivo = "certificados/{$empresa->id}.pfx";
            Storage::disk('local')->put($caminhoDefinitivo, $conteudo);
            Storage::disk('local')->delete($novoArquivo);

            $atualizacoes = [
                ...$atualizacoes,
                'empresa_certificado_path' => $caminhoDefinitivo,
                'empresa_certificado_senha' => $novaSenha,
                'empresa_certificado_titular_cnpj' => $metadados->cnpj,
                'empresa_certificado_titular_nome' => $metadados->nomeTitular,
                'empresa_certificado_validade' => $metadados->validade,
                'empresa_certificado_atualizado_em' => now(),
            ];

            $cnpjEmpresa = preg_replace('/\D/', '', (string) $empresa->empresa_cnpj);
            if ($metadados->cnpj && $cnpjEmpresa && $metadados->cnpj !== $cnpjEmpresa) {
                Notification::make()
                    ->title('Atenção: CNPJ diferente')
                    ->body('O CNPJ do certificado não é o mesmo cadastrado na empresa — confirme se é intencional (ex.: certificado de contador/procurador).')
                    ->warning()
                    ->persistent()
                    ->send();
            }
        }

        $empresa->update($atualizacoes);

        Notification::make()
            ->title('Configuração salva')
            ->success()
            ->send();

        $this->form->fill([
            'empresa_sefaz_ambiente' => $atualizacoes['empresa_sefaz_ambiente'],
            'empresa_sefaz_auto_importacao_ativa' => $atualizacoes['empresa_sefaz_auto_importacao_ativa'],
        ]);
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar')
                ->submit('save'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->alignment(Alignment::Start)
                        ->key('form-actions'),
                ]),
        ]);
    }

    private function descricaoStatusCertificado(Empresa $empresa): string
    {
        if (! $empresa->certificadoConfigurado()) {
            return 'Nenhum certificado cadastrado ainda.';
        }

        $validade = $empresa->empresa_certificado_validade;

        if ($empresa->certificadoVencido()) {
            return "Certificado VENCIDO em {$validade->format('d/m/Y')}.";
        }

        $dias = (int) now()->diffInDays($validade);
        $aviso = $dias <= 30 ? " — atenção, vence em {$dias} dia(s)." : '';
        $titular = $empresa->empresa_certificado_titular_nome
            ? " (titular: {$empresa->empresa_certificado_titular_nome})"
            : '';

        return "Válido até {$validade->format('d/m/Y')}{$aviso}{$titular}";
    }
}
