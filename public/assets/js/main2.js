// Funções JavaScript principais
document.addEventListener('DOMContentLoaded', function() {
    console.log('Sistema carregado!');
    
    // Auto-hide alerts após 5 segundos
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


    // Eventos para saída direta e adicionar ao carrinho  
    document.querySelectorAll('.btn-pegar').forEach(button => {  
        button.addEventListener('click', function(event) { // Adiciona o 'event'
            event.preventDefault(); // Impede o comportamento padrão
            event.stopPropagation(); // Impede a propagação do evento

             // Desabilita o botão para prevenir múltiplos cliques
            this.disabled = true;
            this.innerText = 'Processando...';

            const produtoId = this.dataset.produtoId;  
            const qtyInput = document.querySelector(`#qty-${produtoId}`);  
            const quantidade = parseFloat(qtyInput.value);  
            if (quantidade > 0) {  
                // AJAX para saída direta  
                fetch('/estoque-sorveteria/public/produtos.php?action=saida_direta', {  
                    method: 'POST',  
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },  
                    body: `produto_id=${produtoId}&quantidade=${quantidade}`  
                }).then(response => response.json())  
                .then(data => {  
                    if (data.success) {  
                        alert('Saída registrada com sucesso!');  
                        location.reload();  
                    } else {  
                        alert('Erro: ' + data.message);  
                         // Reabilita o botão em caso de erro
                        this.disabled = false;
                        this.innerText = 'Pegar';
                    }  
                });  
            }  else {
                // Reabilita o botão se a quantidade for inválida
                this.disabled = false;
                this.innerText = 'Pegar';
            }
        });  
    });  
  
    document.querySelectorAll('.btn-add-cart').forEach(button => {  
        button.addEventListener('click', function(event) { // Adiciona o 'event'
            event.preventDefault(); // Impede o comportamento padrão
            event.stopPropagation(); // Impede a propagação do evento
            
            const produtoId = this.dataset.produtoId;  
            const qtyInput = document.querySelector(`#qty-${produtoId}`);  
            const quantidade = parseFloat(qtyInput.value);  
            if (quantidade > 0) {  
                // AJAX para adicionar ao carrinho  
                fetch('/estoque-sorveteria/public/produtos.php?action=add_cart', {  
                    method: 'POST',  
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },  
                    body: `produto_id=${produtoId}&quantidade=${quantidade}`  
                }).then(response => response.json())  
                .then(data => {  
                    if (data.success) {  
                        alert('Adicionado ao carrinho!');  
                    } else {  
                        alert('Erro: ' + data.message);  
                    }  
                });  
            }  
        });  
    });  
});

