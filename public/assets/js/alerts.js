document.addEventListener('DOMContentLoaded', function() {
    // Carrega alertas inicialmente
    fetch('api/alerts')
        .then(response => response.json())
        .then(data => {
            console.log('Alertas pendentes:', data);
        })
        .catch(error => console.error('Erro ao carregar alertas:', error));

    // Atualização periódica (cada 60s)
    setInterval(() => {
        fetch('api/alerts')
            .then(response => response.json())
            .then(data => {
                console.log('Atualização de alertas:', data);
            })
            .catch(error => console.error('Erro ao atualizar alertas:', error));
    }, 60000);

    // Evento para marcar como lido
    document.querySelectorAll('.mark-read').forEach(button => {
        button.addEventListener('click', function() {
            const alertId = this.dataset.alertId;
            fetch('api/alerts/mark-read', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `alert_id=${alertId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.closest('tr').remove();
                } else {
                    alert('Erro ao marcar alerta como lido.');
                }
            })
            .catch(error => console.error('Erro ao marcar como lido:', error));
        });
    });

    
    
});