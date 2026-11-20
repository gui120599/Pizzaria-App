/**
 * Dados do servidor para o cardápio, em blocos <script type="application/json">
 * (cardapio/script.blade.php). Null fora da página do cardápio.
 *
 * @returns {object|null}
 */
export function lerDados() {
    const ler = (id) => {
        const elemento = document.getElementById(id);

        return elemento ? JSON.parse(elemento.textContent) : null;
    };

    const dados = ler('cardapio-dados');

    return dados ? { ...dados, produtosTela: ler('cardapio-produtos') ?? [] } : null;
}
