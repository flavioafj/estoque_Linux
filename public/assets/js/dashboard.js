// public/assets/js/dashboard.js
$(document).ready(function() {
    // Carregar resumo
    $.ajax({
        url: '/api/dashboard/summary',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            $('#total-estoque').text(data.total_estoque);
            $('#valor-fifo').text('R$ ' + data.valor_fifo);
        },
        error: function(xhr) {
            console.error('Erro ao carregar resumo:', xhr.responseJSON ? xhr.responseJSON.error : 'Erro desconhecido');
        }
    });

    // Carregar estoque baixo
    $.ajax({
        url: '/api/dashboard/low-stock',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            let html = '';
            data.forEach(item => {
                html += `
                    <tr>
                        <td>${item.codigo || '-'}</td>
                        <td>${item.nome}</td>
                        <td>${item.categoria || 'Sem Categoria'}</td>
                        <td>${item.fornecedor || 'Sem Fornecedor'}</td>
                        <td class="text-danger">${item.estoque_atual}</td>
                        <td>${item.estoque_minimo}</td>
                    </tr>`;
            });
            $('#low-stock-table tbody').html(html);
        },
        error: function(xhr) {
            console.error('Erro ao carregar estoque baixo:', xhr.responseJSON ? xhr.responseJSON.error : 'Erro desconhecido');
        }
    });

    // Carregar movimentações recentes
    $.ajax({
        url: '/api/dashboard/recent-movements',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            let html = '';
            data.forEach(item => {
                html += `
                    <tr>
                        <td>${item.tipo}</td>
                        <td>${item.documento_numero || '-'}</td>
                        <td>${new Date(item.data_movimentacao).toLocaleDateString('pt-BR')}</td>
                        <td>${item.fornecedor || '-'}</td>
                        <td>${item.usuario}</td>
                        <td>R$ ${parseFloat(item.valor_total || 0).toFixed(2)}</td>
                    </tr>`;
            });
            $('#recent-movements-table tbody').html(html);
        },
        error: function(xhr) {
            console.error('Erro ao carregar movimentações:', xhr.responseJSON ? xhr.responseJSON.error : 'Erro desconhecido');
        }
    });

    // Carregar gráfico de giro
    $.ajax({
        url: '/api/dashboard/stock-turnover',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            const ctx = document.getElementById('stockTurnoverChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Entradas',
                            data: data.entradas,
                            backgroundColor: 'rgba(54, 162, 235, 0.5)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Saídas',
                            data: data.saidas,
                            backgroundColor: 'rgba(255, 99, 132, 0.5)',
                            borderColor: 'rgba(255, 99, 132, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Quantidade' }
                        },
                        x: {
                            title: { display: true, text: 'Período (Ano-Mês)' }
                        }
                    }
                }
            });
        },
        error: function(xhr) {
            console.error('Erro ao carregar gráfico:', xhr.responseJSON ? xhr.responseJSON.error : 'Erro desconhecido');
        }
    });
});