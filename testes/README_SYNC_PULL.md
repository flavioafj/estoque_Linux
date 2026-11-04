# Confirmação do recebimento (PULL), protocolo 2

A API e `scripts/sync2.php` devem ser atualizados juntos. O cliente verifica
`GET /api/sync.php?protocol=2` antes de iniciar qualquer envio ou aplicação.
Cliente e servidor antigos/incompatíveis falham explicitamente.

O Raspberry cria `sync_recebimentos` automaticamente, antes das transações.
O usuário MySQL precisa de permissão CREATE para essa primeira inicialização.
Cada recibo usa a origem (URL da API), o ID do evento remoto e o hash do conteúdo.
A reserva do recibo, a movimentação e a marca de aplicação pertencem à mesma
transação InnoDB. Se houver rollback, nenhum recibo de sucesso persiste.
Uma chave primária impede reaplicações em processos concorrentes.

Após o commit local, o cliente envia `POST /api/sync.php?ack=1` com os IDs e hashes.
A API autentica pelo token, verifica o conteúdo e confirma a fila em transação.
O ACK é idempotente: se a resposta se perder, pode ser repetido. Os recibos locais
não confirmados são reenviados no início e ao fim de cada execução, sem nova baixa.
Não apague `sync_recebimentos` nem troque a URL configurada sem reconciliar a fila.

Não há filtro por relógio/data no protocolo 2. A API retorna eventos suportados,
pendentes, com menos de cinco tentativas, ordenados por ID. Registros de teste com
ação desconhecida e itens com limite de tentativas atingido ficam fora do lote;
exigem investigação antes de qualquer liberação. Eventos novos também seguem esse
limite: uma falha de aplicação não confirma o evento e aparece no terminal.

A confirmação global na tabela `sincronizacao` mantém o contrato existente de um
único Raspberry consumidor. Vários consumidores exigem confirmações por dispositivo.
O recibo deste protocolo protege o PULL. Os eventos antigos de saída enviados por
PUSH mantêm seu contrato atual; o transporte agora exige HTTP 200 e JSON com
`success: true`, e registra falhas de conexão ou de resposta.

## Saída histórica já aplicada

O evento remoto 388 (`SAIDA_DIRETA_ADM`, movimento 231) continha uma unidade de cada
produto 2 e 6. O usuário confirmou os itens no movimento 202 do Raspberry.
Esse evento foi confirmado no servidor sem reaplicar a saída. Essa reconciliação
é uma operação única baseada nessa confirmação, não uma regra automática do código.
Outros eventos anteriores ao protocolo 2 precisam da mesma conferência quando já
aplicados. O protocolo não pode inferir recibos históricos pela quantidade de estoque.

## Validação

```sh
php testes/test_sync_pull.php
php testes/test_sync_api.php
php testes/test_entrada_manual.php
```

Os testes usam SQLite em memória e não alteram bancos reais. Cobrem baixa única,
reenvio, perda da resposta do ACK, rollback, validação de conteúdo/origem, eventos
antigos, bloqueio por tentativas e respostas HTTP 200 sem confirmação JSON.
O teste de API usa um servidor PHP temporário em 127.0.0.1 e testa a rota real,
com SQLite e token de teste. Requer proc_open e sockets locais habilitados.
A sintaxe MySQL/InnoDB e as operações de recibo também foram verificadas com tabelas
TEMPORARY que sombreiam a fila somente na conexão do teste. A concorrência entre
conexões não é simulada pelos testes SQLite.

Depois de atualizar ambos os ambientes, execute o sincronizador uma vez. O resumo
mostra separadamente enviados (PUSH), aplicados, já recebidos, confirmados e falhas.
Em falhas o processo termina com código 1. Para testar repetição, preserve os recibos:
uma execução posterior não deve gerar nova movimentação para o mesmo evento.
