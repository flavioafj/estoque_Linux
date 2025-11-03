// Funções JavaScript principais
document.addEventListener('DOMContentLoaded', function() {
    console.log('Sistema carregado!');
    
    // ... (código dos alerts permanece o mesmo) ...
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.display = 'none';
        }, 5000);
    });

    const closeButtons = document.querySelectorAll('.close-button');
    closeButtons.forEach(button => {
        button.addEventListener('click', () => {
            const alertDiv = button.parentElement;
            alertDiv.style.display = 'none';
        });
    });

    // --- INÍCIO DA NOVA LÓGICA COM DELEGAÇÃO DE EVENTOS ---

    // Seleciona o container pai dos produtos
    const productContainer = document.querySelector('.product-grid');

    if (productContainer) {
        productContainer.addEventListener('click', function(event) {
            
            // Verifica se o clique foi no botão 'Pegar'
            const pegarButton = event.target.closest('.btn-pegar');
            if (pegarButton && !pegarButton.disabled) {
                event.preventDefault();
                event.stopPropagation();

                pegarButton.disabled = true;
                pegarButton.innerText = 'Processando...';

                const produtoId = pegarButton.dataset.produtoId;
                const qtyInput = document.querySelector(`#qty-${produtoId}`);
                const quantidade = parseFloat(qtyInput.value);

                if (quantidade > 0) {
                    fetch('/produtos.php?action=saida_direta', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `produto_id=${produtoId}&quantidade=${quantidade}`
                    }).then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // <<< ALTERAÇÃO CRÍTICA >>>
                            if (data.redirect) {
                                window.location.href = data.redirect; // Redireciona para pos_saida.php
                            } else {
                                alert('Saída registrada com sucesso!');
                                location.reload();
                            }
                        } else {
                            alert('Erro: ' + data.message);
                            pegarButton.disabled = false;
                            pegarButton.innerText = 'Pegar';
                        }
                    }).catch(() => {
                        alert('Erro de comunicação.');
                        pegarButton.disabled = false;
                        pegarButton.innerText = 'Pegar';
                    });
                } else {
                    pegarButton.disabled = false;
                    pegarButton.innerText = 'Pegar';
                }
            }

            // Verifica se o clique foi no botão 'Adicionar ao Carrinho'
            const addCartButton = event.target.closest('.btn-add-cart');
            if (addCartButton && !addCartButton.disabled) {
                event.preventDefault();
                event.stopPropagation();

                addCartButton.disabled = true;

                const produtoId = addCartButton.dataset.produtoId;
                const qtyInput = document.querySelector(`#qty-${produtoId}`);
                const quantidade = parseFloat(qtyInput.value);

                if (quantidade > 0) {
                    fetch('/produtos.php?action=add_cart', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `produto_id=${produtoId}&quantidade=${quantidade}`
                    }).then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Adicionado ao carrinho!');
                            addCartButton.disabled = false; // Reabilita após sucesso
                            const badge = document.querySelector('.cart-badge');
                            let count = (parseInt(badge?.textContent) || 0) + 1;
                            if (!badge) {
                                const newBadge = document.createElement('span');
                                newBadge.className = 'cart-badge';
                                newBadge.textContent = '1';
                                document.querySelector('.cart-link').appendChild(newBadge);
                            } else {
                                badge.textContent = count;
                            }
                        } else {
                            alert('Erro: ' + data.message);
                            addCartButton.disabled = false;
                        }
                    }).catch(() => {
                        alert('Erro de comunicação.');
                        addCartButton.disabled = false;
                    });
                } else {
                    addCartButton.disabled = false;
                }
            }
        });
    }
});