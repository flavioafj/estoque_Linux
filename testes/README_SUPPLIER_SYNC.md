# Gestão e sincronização de fornecedores

Acesse **Administrador → Gerenciar Fornecedores** (`/admin/suppliers.php`).
A lista exibe fornecedores ativos, com os dados completos em “Ver dados”.

A razão social (ou nome, para pessoa física) e o tipo são obrigatórios. O tipo
padrão é PJ. Nome fantasia, CNPJ, CPF, e-mail, telefones, endereço, cidade, UF,
CEP e observações são opcionais; campos vazios são salvos como NULL. Não se exige
CNPJ para PJ nem CPF para PF. Os limites de tamanho seguem o esquema informado;
e-mail e UF são validados quando preenchidos. CNPJ/CPF informados não podem
repetir documentos já cadastrados, inclusive inativos; a busca desconsidera
pontuação usual. Não há consulta externa nem validação de dígitos verificadores.

ID, autor do cadastro, status ativo e datas são preenchidos automaticamente.
A exclusão desativa o fornecedor, sem apagar notas ou movimentações. A tela exige
administrador no servidor e token CSRF para inclusão/exclusão.

## Sincronização

`AdminFornecedor` mantém conta de fornecedor, auditoria e evento na mesma
transação. `INSERT` leva todos os campos e metadados; `UPDATE` de exclusão leva
`ativo = 0` e a identificação do fornecedor. Falha na fila reverte a operação.

A API e o PULL de `scripts/sync2.php` encaminham os eventos administrativos,
identificados por `evento_id`, para `SupplierSyncService`. Ele preserva os IDs,
registra um recibo já sincronizado e não cria novas pendências. O UUID impede
reaplicação de PUSH após perda de resposta; no PULL também são usados os recibos
e ACKs do protocolo 2. Repetir um cadastro não reativa um fornecedor excluído.
Não apague esses recibos ao limpar a fila.

A inclusão de NF-e e o botão AJAX de cadastro usam `NfeSupplierService`: novos
fornecedores geram `INSERT`; dados diferentes de fornecedores existentes geram
`UPDATE` com `antes` e `alteracoes`. A NF-e referencia o evento de fornecedor e
só pode ser recebida depois dele. Dados ausentes/vazios no XML não apagam contatos
ou endereço já cadastrados. Uma nova inclusão de NF-e (ou seu botão de cadastro)
reativa o fornecedor encontrado pelo CNPJ, preservando o ID, e enfileira um UPDATE
explícito com `ativo = 1`. Reenvios de eventos antigos não reativam o fornecedor.
O fluxo de conclusão da entrada confirma fornecedor, auditoria, mapeamentos de
produtos, movimentação, estoque, nota e fila na mesma transação. O AJAX confirma
seu cadastro e evento independentemente da conclusão posterior da nota.

Eventos legados de fornecedores sem `evento_id` e notas antigas sem referência
continuam usando o caminho anterior. A atualização por NF-e confere o valor
anterior de cada campo e deixa conflitos pendentes para reconciliação.

Para ativar no equipamento local, atualize os dois ambientes com estes arquivos:

- `public/admin/suppliers.php` e `templates/navigation.php`;
- `src/Models/AdminFornecedor.php`;
- `src/Services/SupplierData.php`, `src/Services/SupplierSyncService.php` e `src/Services/NfeSupplierService.php`;
- `src/Helpers/NFeProcessor.php` e os arquivos `public/movimentacao/processar_nfe.php`,
  `public/movimentacao/processa_cadastro.php` e `public/movimentacao/registrar_entrada_nfe.php`;
- `src/Controllers/SyncController.php` e `scripts/sync2.php`.

Não é necessária migração de banco para o esquema de fornecedores informado.
Mantenha as atualizações anteriores de usuários e do protocolo 2. O campo
`criado_por` exige que o usuário autor exista no destino com o mesmo ID; sincronize
seu cadastro antes do fornecedor. O sistema continua usando IDs compartilhados e
um consumidor local. Colisões de IDs ou documentos deixam o evento sem confirmação
para reconciliação, sem sobrescrever contas existentes. Alterações anteriores à
atualização não são reenfileiradas automaticamente.

Após atualizar o local:

```sh
php scripts/sync2.php
```

## Teste automatizado

Na raiz do projeto:

```sh
php testes/test_supplier_sync.php
```

O teste usa duas bases SQLite temporárias, a página administrativa real, a API
real e o sincronizador real, substituindo apenas o bootstrap e a sessão por dados
de teste. Não lê `.env`, não consulta serviços externos e não altera bancos reais.
Requer PHP 8+, PDO SQLite, mbstring, cURL, proc_open e sockets em 127.0.0.1.

Cobre permissões/CSRF, HTML escapado, cadastro mínimo/completo/PF, opcionais e
limites, documentos duplicados, inclusão/exclusão nos dois sentidos, preservação
de campos/metadados/histórico, ACK, reenvio, ausência de eco, conflitos e rollback
de fornecedor/auditoria/fila/recibos. SQLite não simula concorrência InnoDB.

Para validar especificamente a NF-e, execute:

```sh
php testes/test_nfe_supplier_sync.php
```

Esse teste executa os endpoints reais de AJAX e conclusão da entrada, além da
API e do sincronizador, em duas bases SQLite temporárias. Cobre INSERT/UPDATE de
fornecedores, cadastro direto sem AJAX, dependência fornecedor → nota, preservação
de opcionais, reenvios, ausência de eco, conflitos e rollback integral quando a
fila do fornecedor ou da nota falha. Verifica também a extração de dados/acento do
XML; este teste requer a extensão SimpleXML, além dos requisitos anteriores.
