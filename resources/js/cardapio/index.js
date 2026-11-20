import { registrarCarrinho } from './carrinho';
import { lerDados } from './dados';
import { registrarPagina } from './pagina';

// O Alpine é o do Livewire (ver app.js): registra no alpine:init, antes de ele iniciar.
document.addEventListener('alpine:init', () => {
    const dados = lerDados();

    if (!dados) {
        return;
    }

    registrarCarrinho(window.Alpine, dados);
    registrarPagina(window.Alpine);
});
