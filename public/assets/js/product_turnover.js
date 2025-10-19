// public/assets/js/product_turnover.js
$(document).ready(function() {
    const productId = new URLSearchParams(window.location.search).get('id');
    let chartInstance = null;

    // Carregar dados iniciais (últimos 6 meses)
    loadData();

    // Submeter formulário
    $('#period-form').submit(function(e) {
        e.preventDefault();
        loadData();
    });

    function loadData() {
        const startDate = $('#start_date').val();
        const endDate = $('#end_date').val();
        const url = `/api/product/turnover/${productId}${startDate ? `?start_date=${startDate}&end_date=${endDate || ''}` : ''}`;

        $.ajax({
            url: url,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                // Renderizar gráfico
                const ctx = document.getElementById('productTurnoverChart').getContext('2d');
                if (chartInstance) {
                    chartInstance.destroy();
                }
                chartInstance = new Chart(ctx, {
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

                // Popular tabela de entradas
                let entradasHtml = '';
                data.entradas_list.forEach(item => {
                    entradasHtml += `
                        <tr>
                            <td>${item.documento_numero || '-'}</td>
                            <td>${new Date(item.data_movimentacao).toLocaleDateString('pt-BR')}</td>
                            <td>${item.usuario}</td>
                            <td>${item.quantidade}</td>
                        </tr>`;
                });
                $('#entradas-table tbody').html(entradasHtml);

                // Popular tabela de saídas
                let saidasHtml = '';
                data.saidas_list.forEach(item => {
                    saidasHtml += `
                        <tr>
                            <td>${item.documento_numero || '-'}</td>
                            <td>${new Date(item.data_movimentacao).toLocaleDateString('pt-BR')}</td>
                            <td>${item.usuario}</td>
                            <td>${item.quantidade}</td>
                        </tr>`;
                });
                $('#saidas-table tbody').html(saidasHtml);
            },
            error: function(xhr) {
                console.error('Erro ao carregar dados:', xhr.responseJSON ? xhr.responseJSON.error : 'Erro desconhecido');
            }
        });
    }
});