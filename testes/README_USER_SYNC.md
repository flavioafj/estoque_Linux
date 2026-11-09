# Sincronização de usuários

Cadastro e exclusão na tela administrativa gravam a conta, a auditoria e o evento
na mesma transação. Falha na fila desfaz a operação inteira. Cadastros usam
`usuarios/INSERT`; exclusões usam `usuarios/UPDATE` com `ativo = 0`.
Cada evento tem um `evento_id` aleatório e leva o ID e a identificação da conta.

A API (`SyncController`) e o recebimento em `scripts/sync2.php` chamam o mesmo
`UserSyncService`. O serviço valida os campos, preserva IDs e grava um recibo já
sincronizado na fila, na transação do receptor. Esse recibo impede duplicação
inclusive no PUSH quando a resposta HTTP se perde. No PULL, os recibos e ACKs do
protocolo 2 continuam sendo usados. Recebimentos não criam novas pendências.
Não apague os recibos de usuários ao limpar a fila.

Somente operadores (perfil 2) podem ser criados por esse fluxo. Senhas, hashes e
tokens de recuperação não são transportados. Cada destino preenche a senha
obrigatória com um hash aleatório; o login de operador continua sem pedir senha.
A exclusão pode desativar contas existentes de outros perfis, respeitando a
proteção do último administrador ativo no destino. Sessões cadastradas são
removidas, e o middleware atualizado bloqueia a conta na próxima requisição
protegida. Os vínculos com movimentações permanecem no banco.

## Atualização de web e local

1. Atualize os arquivos da funcionalidade nos dois ambientes: `AdminUser`,
   `UserSyncService`, `SyncController`, `SyncPullService`, middleware `Auth`,
   tela/menu de usuários e `scripts/sync.php` / `scripts/sync2.php`.
2. Aplique em cada banco a migração
   `database/migrations/20261109_optional_user_email.sql`, caso ainda não aplicada.
   Ela permite vários usuários com e-mail `NULL`, mantendo a unicidade dos e-mails
   informados. Não há nova tabela de sincronização de usuários.
3. Preserve `SYNC_URL`, `SYNC_TOKEN`, os recibos existentes e os IDs de perfis
   (1 = administrador, 2 = operador, 3 = inventariador).
4. Execute o sincronizador no local:

   ```sh
   php scripts/sync2.php
   ```

   O ponto de entrada antigo `scripts/sync.php` agora chama `sync2.php`; um cron
   que usa o nome antigo passa a usar o protocolo 2 também.
5. Confira o resumo de envios, aplicações, confirmações e falhas. Uma falha retorna
   código 1; a pendência não é confirmada. PUSH com cinco tentativas exige revisão
   antes de liberar nova tentativa, conforme o contrato existente.

O contrato atual compartilha IDs entre as bases e atende um único consumidor
local. Se os dois sistemas criarem contas diferentes com o mesmo ID enquanto
estiverem desconectados, a sincronização aponta conflito e mantém o evento
pendente, sem sobrescrever contas nem trocar referências das movimentações.
A reconciliação de IDs existentes precisa ser feita explicitamente.

As alterações passam a ser enfileiradas após a atualização. Contas criadas ou
excluídas antes dela não são reenfileiradas automaticamente.

## Teste automatizado

Na raiz do projeto:

```sh
php testes/test_user_sync.php
```

Requisitos: PHP 8+, PDO SQLite, mbstring, cURL, `proc_open` e sockets em
`127.0.0.1`. O teste executa a API e o sincronizador reais, substituindo apenas o
bootstrap de produção por duas bases SQLite temporárias e um token de teste.
Não carrega `.env`, não acessa sistemas externos e não altera bancos reais.
Os arquivos temporários e o servidor são removidos ao terminar.

Cobre cadastro e exclusão nos dois sentidos, filas e confirmação, repetição do
sincronizador e de requisições, cadastro atrasado após exclusão, e-mail opcional,
login sem senha, sessões, histórico, proteção de administrador, conflitos de IDs,
validação de campos/credenciais e rollback de conta/auditoria/fila/recibos.
Concorrência entre conexões InnoDB não é simulada por SQLite.

Regressões relacionadas:

```sh
php testes/test_sync_pull.php
php testes/test_sync_api.php
php testes/test_product_sync.php
php testes/test_inventory_sync.php
```
