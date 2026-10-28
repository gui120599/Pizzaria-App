<?php

namespace App\Filament\Influenciador\Pages\Auth;

use App\Filament\Influenciador\Pages\Dashboard;
use App\Models\Colaborador;
use App\Models\Influenciador;
use App\Support\DocumentoFiscal;
use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Perfil do influenciador: dados de contato, CPF e chave Pix (necessários
 * para a equipe pagar um resgate em Pix) e troca de senha.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dados')
                ->columns(2)
                ->schema([
                    TextInput::make('influenciador_nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    $this->getEmailFormComponent(),
                    TextInput::make('influenciador_whatsapp')
                        ->label('WhatsApp')
                        ->tel()
                        ->maxLength(20)
                        ->helperText('Com DDD. É guardado só com números.'),
                    TextInput::make('influenciador_instagram')
                        ->label('Instagram')
                        ->maxLength(100),
                ]),

            Section::make('Para receber em Pix')
                ->description('O CPF e a chave Pix ficam criptografados e só a equipe usa, para pagar o seu resgate.')
                ->columns(2)
                ->schema([
                    TextInput::make('influenciador_cpf')
                        ->label('CPF')
                        ->mask('999.999.999-99')
                        ->placeholder('000.000.000-00')
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (filled($value) && ! DocumentoFiscal::cpfValido($value)) {
                                $fail('Informe um CPF válido.');
                            }
                        })
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $digitos = DocumentoFiscal::somenteDigitos($value);

                            if ($digitos !== '' && Influenciador::where('influenciador_cpf_hash', Colaborador::hashCpf($digitos))
                                ->whereKeyNot(Auth::guard('influenciador')->id())->exists()) {
                                $fail('Este CPF já está cadastrado.');
                            }
                        }),
                    TextInput::make('influenciador_chave_pix')
                        ->label('Chave Pix')
                        ->maxLength(120),
                ]),

            Section::make('Senha')
                ->columns(1)
                ->schema([
                    $this->getPasswordFormComponent(),
                    $this->getPasswordConfirmationFormComponent(),
                    $this->getCurrentPasswordFormComponent(),
                ]),
        ]);
    }

    /** Senha temporária: no primeiro acesso a nova senha é obrigatória e libera o painel. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['password'] ?? null) && $this->getUser()->influenciador_deve_trocar_senha) {
            Notification::make()->title('Defina uma nova senha')->body('A senha temporária precisa ser trocada para você continuar.')->danger()->send();

            $this->halt();
        }

        if (filled($data['password'] ?? null)) {
            $data['influenciador_deve_trocar_senha'] = false;
        }

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return Dashboard::getUrl(panel: 'influenciador');
    }
}
