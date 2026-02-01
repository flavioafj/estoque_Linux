// assets/js/audit.js
document.getElementById('filterForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {};
    formData.forEach((value, key) => { data[key] = value; });
    
    fetch('api/audit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(logs => {
        const tbody = document.querySelector('#auditTable tbody');
        tbody.innerHTML = '';
        
        logs.forEach(log => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${log.id}</td>
                <td>${log.tabela}</td>
                <td>${log.registro_id}</td>
                <td>${log.acao}</td>
                <td>${log.usuario || 'Sistema'}</td>
                <td>${log.ip_address}</td>
                <td>${log.user_agent}</td>
                <td>${log.criado_em}</td>
                <td>${log.dados_anteriores ? JSON.stringify(JSON.parse(log.dados_anteriores), null, 2) : ''}</td>
                <td>${log.dados_novos ? JSON.stringify(JSON.parse(log.dados_novos), null, 2) : ''}</td>
            `;
            tbody.appendChild(row);
        });
    })
    .catch(error => console.error('Erro ao carregar logs:', error));
});