# Produtos e fornecedores

A tabela `produto_fornecedores` registra o par produto/fornecedor com unicidade,
status ativo, autor e datas. Os IDs internos dos vínculos podem ser diferentes nas
duas bases; o contrato de sincronização usa os IDs dos produtos e fornecedores.
Não há exclusão física dos vínculos nem de históricos.

## Interface

- Na gestão de produtos, o cadastro oferece seleção múltipla opcional de
  fornecedores. O fornecedor principal selecionado também é associado.
- Clique no nome de um produto na lista para abrir sua página de giro de estoque:
  o card **Fornecedores deste produto** permite buscar/adicionar fornecedores,
  desvincular e definir o principal.
- Na gestão de fornecedores, o cadastro oferece seleção opcional de produtos.
  O botão **Produtos** abre um modal para listar, buscar/adicionar ou desvincular
  produtos e definir esse fornecedor como principal do produto escolhido.
- Os vínculos com produto ou fornecedor inativo ficam ocultos. Seus registros são
  preservados. Desvincular o fornecedor principal limpa a indicação sem escolher
  outro automaticamente. A reativação dos pais torna visíveis os vínculos que
  continuam ativos.
- Na conclusão da NF-e, todos os produtos confirmados são associados ao fornecedor
  da nota; vínculos removidos são reativados. Cancelar a importação antes da
  conclusão não cria associações.

As operações exigem administrador e token CSRF. Os nomes são renderizados como
texto e as seleções permitem buscar por nome. Na tabela de fornecedores, editar
os dados cadastrais continua separado das ações do modal Produtos.

## Implantação

A migração foi aplicada na base configurada nesta implementação. Antes de usar no
sistema local, atualize os arquivos e execute na raiz do projeto:

```sh
php scripts/migrate_product_suppliers.php
```

O script CLI cria a tabela se ainda não existir, sem preencher dados históricos.
O esquema completo em `database_schema.sql` também inclui a nova tabela.

Atualize os dois sistemas com os novos serviços/scripts e com as integrações:
`ProductSupplierService`, endpoint `public/admin/product_suppliers.php`, JavaScript
`public/assets/js/product_suppliers.js`, telas de produtos/fornecedores e giro do
produto, modelos `Product` e `AdminFornecedor`, rota `public/product/index.php`,
conclusão da NF-e, `SyncController`, `SyncPullService` e `scripts/sync2.php`.
Mantenha as versões anteriores de sincronização de fornecedores e usuários.

## Carga inicial para conferência

```sh
php scripts/backfill_product_suppliers.php
```

Por padrão, somente lista os pares candidatos: fornecedor principal atual ou
fornecedor de uma movimentação de entrada não cancelada. Produtos e fornecedores
inativos são excluídos da prévia. Vínculos já existentes, inclusive desativados,
não são recriados/reativados pela carga. Após conferir a prévia, aplique em **uma
única origem**:

```sh
php scripts/backfill_product_suppliers.php --apply
php scripts/sync2.php
```

A carga usa uma transação e enfileira os vínculos para o outro sistema. Ela não foi
aplicada automaticamente. O comando de sincronização é executado no equipamento
local; se a carga for feita no web, ele recebe os eventos pelo PULL.

## Sincronização e atomicidade

Os vínculos usam eventos `produto_fornecedores/UPDATE` com UUID, IDs dos pais,
status e intenção sobre o fornecedor principal. Cadastro dos pais precede o
evento de vínculo. O receptor compartilhado da API e do PULL grava um recibo já
sincronizado; não gera eco. Reenvios do mesmo UUID não repetem alterações e não
reativam um vínculo removido posteriormente. Recibos devem ser preservados.
O principal não é substituído silenciosamente se divergir no destino.

Nos cadastros, pai e vínculos são gravados na mesma transação. Na NF-e, fornecedor,
associações, estoque, nota e fila pertencem à mesma transação. A nota referencia
os eventos dos vínculos: se um deles não tiver sido recebido, a nota fica pendente.
Notas antigas, sem referências de vínculos, mantêm o contrato anterior.

O contrato continua considerando um consumidor local e IDs compartilhados de
produtos/fornecedores. Conflitos ou pais ausentes exigem reconciliação; o evento
não recebe confirmação de sucesso.

## Testes

```sh
php testes/test_product_supplier_sync.php
php testes/test_nfe_supplier_sync.php
php testes/test_supplier_sync.php
php testes/test_product_sync.php
```

Os testes usam SQLite temporário e, nos testes de integração, servidor HTTP em
127.0.0.1, a API e o sincronizador reais. Não leem `.env` nem alteram dados reais.
Requerem PHP 8+, PDO SQLite, mbstring, cURL, proc_open e sockets locais; a regressão
NF-e requer SimpleXML. O JavaScript passou por verificação de sintaxe. Interações
visuais no navegador e concorrência entre conexões InnoDB não são simuladas.
