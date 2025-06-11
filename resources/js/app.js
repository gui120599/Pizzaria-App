import './bootstrap';

import Alpine from 'alpinejs';

import { createApp } from 'vue'
import TelaPedido from './components/TelaPedido.vue'



window.Alpine = Alpine;

Alpine.start();


const app = createApp({})
//app.component('tela-pedido', TelaPedido)
app.mount('#app')