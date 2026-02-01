$(document).ready(function() {
    // Manipula o envio do formulário
    $('#report-form').on('submit', function(e) {
        e.preventDefault();
        generateReport();
    });

    // Manipula o clique no botão de exportação CSV
    $('#export-csv').on('click', function(e) {
        e.preventDefault(); // Impede o comportamento padrão do clique

        // Coleta os dados do formulário
        const formData = $('#report-form').serializeArray();
        
        // Adiciona o parâmetro format=csv ao formData
        formData.push({ name: 'format', value: 'csv' });

        // Envia a requisição POST via AJAX
        $.ajax({
            url: '/api/reports/custom',
            type: 'POST',
            data: formData,
            xhrFields: {
                responseType: 'blob' // Define que a resposta é um arquivo binário (CSV)
            },
            success: function(data, status, xhr) {
                // Obtém o nome do arquivo do cabeçalho Content-Disposition
                const disposition = xhr.getResponseHeader('Content-Disposition');
                let filename = 'relatorio_estoque.csv';
                if (disposition && disposition.indexOf('attachment') !== -1) {
                    const matches = /filename="([^"]*)"/.exec(disposition);
                    if (matches != null && matches[1]) {
                        filename = matches[1];
                    }
                }

                // Cria um link temporário para download do arquivo
                const url = window.URL.createObjectURL(new Blob([data]));
                const link = document.createElement('a');
                link.href = url;
                link.setAttribute('download', filename);
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url); // Libera o objeto URL
            },
            error: function(xhr, status, error) {
                console.error('Erro ao gerar CSV:', error);
                alert('Erro ao gerar o relatório CSV. Tente novamente.');
            }
        });
    });

    // Função para gerar o relatório
    function generateReport() {
        $.ajax({
            url: '/api/reports/custom',
            type: 'POST',
            data: $('#report-form').serialize(),
            dataType: 'json',
            success: function(response) {
                renderTable(response.data);
            },
            error: function(xhr) {
                alert('Erro ao gerar relatório: ' + xhr.responseJSON?.error || 'Tente novamente.');
            }
        });
    }

    // Renderiza a tabela com os resultados
    function renderTable(data) {
        const tbody = $('#report-table tbody');
        tbody.empty();
        if (data.length === 0) {
            tbody.append('<tr><td colspan="6">Nenhum resultado encontrado.</td></tr>');
            return;
        }
        data.forEach(row => {
            tbody.append(`
                <tr>
                    <td>${row.id}</td>
                    <td>${row.nome}</td>
                    <td>${row.categoria || 'Sem Categoria'}</td>
                    <td>${row.fornecedor || 'Sem Fornecedor'}</td>
                    <td>${row.estoque_atual}</td>
                    <td>R$ ${parseFloat(row.valor_estoque).toFixed(2).replace('.', ',')}</td>
                </tr>
            `);
        });
    }
});