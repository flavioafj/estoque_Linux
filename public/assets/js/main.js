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

    const productGrid = document.getElementById('product-grid');

    if (productGrid) {
        productGrid.addEventListener('click', (event) => {
            const input = event.target.closest('input[type="number"]');
            if (input) {
                input.value = '';
            }
        });
    }


});

