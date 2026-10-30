import './bootstrap';

// O Alpine é fornecido pelo Livewire (v3, via Filament) através de @livewireScripts.
// NÃO importar/iniciar um segundo Alpine aqui — isso causava dupla inicialização
// de cada x-data em páginas com componentes Livewire (ex.: create/edit pedido),
// duplicando modais e listas. Registre plugins/stores via o evento 'alpine:init'.

// Desenho da pizza de sabores usado pelo cardápio (módulo compartilhado com os painéis).
import { desenharPizza } from './pizza-svg';

window.desenharPizza = desenharPizza;
