// public/assets/js/product_turnover.js
$(document).ready(function() {
    const productId = new URLSearchParams(window.location.search).get('id');
    let chartInstance = null;
    let turnoverData = null;
    const priceToggle = document.getElementById('turnover-price-toggle');
    const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const quantity = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 });
    const formatValue = value => priceToggle.checked ? currency.format(value) : quantity.format(value);

    function updateTurnoverMetric() {
        if (!chartInstance || !turnoverData) return;
        const priceMode = priceToggle.checked;
        chartInstance.data.datasets[0].data = [...(priceMode ? turnoverData.entradas_valor : turnoverData.entradas)];
        chartInstance.data.datasets[1].data = [...(priceMode ? turnoverData.saidas_valor : turnoverData.saidas)];
        chartInstance.options.scales.y.title.text = priceMode ? 'Valor total (R$)' : 'Quantidade';
        chartInstance.update('none');
    }
    priceToggle.addEventListener('change', updateTurnoverMetric);

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
                turnoverData = data;
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
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: context => `${context.dataset.label}: ${formatValue(context.parsed.y)}`
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { callback: value => formatValue(value) },
                                title: { display: true, text: 'Quantidade' }
                            },
                            x: {
                                title: { display: true, text: 'Período (Ano-Mês)' }
                            }
                        }
                    }
                });

                updateTurnoverMetric();

                // Popular tabela de entradas
                let entradasHtml = '';
                data.entradas_list.forEach(item => {
                    entradasHtml += `
                        <tr>
                            <td>${item.documento_numero || '-'}</td>
                            <td>${new Date(item.data_movimentacao).toLocaleDateString('pt-BR')}</td>
                            <td>${item.usuario}</td>
                            <td>${item.quantidade}</td>
                            <td>${item.valor_unitario == null ? '-' : currency.format(Number(item.valor_unitario))}</td>
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
                            <td>${item.valor_unitario == null ? '-' : currency.format(Number(item.valor_unitario))}</td>
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