<template>
    <div class="p-4 bg-white rounded shadow">
      <h2 class="text-xl font-bold mb-4">Novo Pedido</h2>
  
      <div class="grid grid-cols-2 gap-4">
        <!-- Lista de produtos -->
        <div>
          <h3 class="font-semibold mb-2">Produtos</h3>
          <ul>
            <li v-for="produto in produtos" :key="produto.id" class="mb-2">
              {{ produto.produto_descricao }} - R$ {{ produto.produto_preco_venda.replace('.',',') }} 
              <button
                @click="adicionarProduto(produto)"
                class="ml-2 px-2 py-1 bg-green-500 text-white text-sm rounded"
              >
                Adicionar
              </button>
            </li>
          </ul>
        </div>
  
        <!-- Carrinho -->
        <div>
          <h3 class="font-semibold mb-2">Itens do Pedido</h3>
          <ul>
            <li v-for="(item, index) in pedido" :key="index" class="mb-2">
              {{ item.produto_descricao }} - R$ {{ item.preco.toFixed(2) }}
              <button
                @click="removerProduto(index)"
                class="ml-2 px-2 py-1 bg-red-500 text-white text-sm rounded"
              >
                Remover
              </button>
            </li>
          </ul>
  
          <p class="mt-4 font-bold">
            Total: R$ {{ totalPedido.toFixed(2) }}
          </p>
        </div>
      </div>
    </div>
  </template>
  
  <script setup>
  
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
  
  const produtos = ref([])
  const pedido = ref([])
  
  const totalPedido = computed(() =>
    pedido.value.reduce((total, p) => total + p.preco, 0)
  )
  
  function adicionarProduto(produto) {
    pedido.value.push({
        id: produto.id,
        produto_descricao: produto.produto_descricao,
        preco: Number(produto.produto_preco_venda),
    })
    console.log(pedido.value);
    
  }
  
  function removerProduto(index) {
    pedido.value.splice(index, 1)
  }
  
  // Simula chamada à API ou backend
  onMounted(async () => {
    try{
        const response = await axios.get('/api/produtos')
        produtos.value = response.data.produtos
        
    }catch (error){
        console.error('Erro ao carregar os produtos:', error)
    }
  })
  </script>
  