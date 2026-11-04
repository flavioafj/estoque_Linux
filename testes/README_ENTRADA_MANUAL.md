# Sincronização de entrada manual

O cadastro manual produz um evento `ENTRADA_MANUAL` na tabela `sincronizacao`, com
`evento_id` aleatório, tipo de entrada, usuário, observação, itens, valores e total.
O serviço `src/Services/EntradaManualService.php` grava a movimentação, os itens,
as unidades de estoque, o saldo e o evento na mesma transação.

O recebimento pela API (`SyncController`) e pelo PULL (`scripts/sync2.php`) usa o
mesmo serviço. Um recibo confirmado na tabela `sincronizacao` impede reaplicações
e não gera outro envio. O evento original também impede a aplicação de um eco.
Para serializar recebimentos concorrentes no MySQL/InnoDB, o serviço bloqueia a
primeira linha de tipo ENTRADA com `FOR UPDATE` até o commit; a busca do recibo
também usa uma leitura com bloqueio. Não remova eventos/recibos de entrada manual
enquanto houver possibilidade de reenvio, pois eles são a evidência de deduplicação.

O protocolo de confirmação do PULL cria a tabela local `sync_recebimentos`,
conforme `README_SYNC_PULL.md`. Atualize os arquivos tanto no servidor web quanto
na instalação local antes de gerar eventos novos. O fluxo considerado é o de
`sync2.php`. Entradas antigas não são reenfileiradas automaticamente; devem ser
reconciliadas antes de qualquer reenvio para evitar duplicar estoque no destino.
Os IDs de produtos, usuários e tipos de movimentação devem corresponder entre
as instalações, como nos demais eventos existentes.

## Teste isolado

Na raiz do sistema:

```sh
php testes/test_entrada_manual.php
```

Requer PHP com PDO SQLite. O teste usa os modelos reais, bancos em memória e logs
em diretório temporário. Não carrega `.env`, não conecta a bancos reais e não envia
e-mails. A adaptação SQLite remove apenas `FOR UPDATE` e fornece as funções SQL
necessárias; portanto, o teste não valida concorrência/bloqueios reais do MySQL.

Cobre entrada com dois produtos, valores e total, observação personalizada/padrão,
valor zero, evento pendente, recibo confirmado, reenvio/eco, transação externa,
validações e rollback em falhas na fila, no estoque e no recibo.

## Conferência entre instalações

1. Registre uma entrada manual com dois produtos e uma observação.
2. Confira um evento pendente `ENTRADA_MANUAL` com apenas os produtos positivos.
3. Execute `scripts/sync2.php` e confira quantidades, unidades, valores e observação
   no destino, além da confirmação do evento na origem.
4. Reenvie o mesmo payload, preservando `evento_id`: não deve criar outra entrada.
5. Confira também uma entrada iniciada no servidor e recebida pelo PULL local.

A validação entre instalações não faz parte do teste isolado e altera o estoque;
use dados e instalações de teste para executá-la.
