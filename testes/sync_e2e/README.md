# Testes de integração da sincronização com pytest

A suíte cria **duas bases SQLite temporárias**, representando local e web, e dois
servidores PHP em `127.0.0.1`. Cria produtos e movimentações pelos produtores reais,
confere a fila, executa o **`scripts/sync2.php` real**, valida a aplicação na outra
base e verifica o ACK e a ausência de novos envios.

## Execução

Na raiz de `estoque_Linux`:

```bash
python3 -m pip install -r testes/sync_e2e/requirements.txt
python3 -m pytest testes/sync_e2e -v
```

Requisitos: Python 3.9+, PHP 8.1+ com `pdo`, `pdo_sqlite` e `curl`, dependências PHP
já instaladas em `vendor/`, e permissão para abrir portas locais. O comando PHP usa
`-n` e carrega explicitamente essas extensões. A suíte tem configuração própria;
não depende de `test_config.py`, Selenium, login de produção ou `.env`.

Para filtrar casos:

```bash
python3 -m pytest testes/sync_e2e -v -k nfe
python3 -m pytest testes/sync_e2e -v -k inventory
python3 -m pytest testes/sync_e2e -v -k push
python3 -m pytest testes/sync_e2e -v -k pull
```

## Cobertura

| Fluxo | Produtor executado | Validações |
|---|---|---|
| Cadastro | `public/product/index.php` → `ProductController::store()` | Dados, ID preservado e evento `INSERT` |
| Inativação e reativação | Mesma rota de produto → controlador | Campo `ativo`, evento `UPDATE`, histórico preservado |
| Entrada direta/manual | `public/movimentacao/registrar_entrada.php` | Quantidades, unidades, itens, valores e `ENTRADA_MANUAL` |
| Entrada por NF-e | `public/movimentacao/registrar_entrada_nfe.php` | Nota, chave única, fornecedor, mapeamento XML, quantidades e total |
| Saída direta | `ExitController::saidaDireta()` | Saldo, unidades FIFO, itens, total e `SAIDA_DIRETA` |
| Saída pelo administrador | `MovimentacaoController::registrarSaida()` | Múltiplos produtos, total e `SAIDA_DIRETA_ADM` |
| Inventário | `public/inventory_confirm.php` | Entrada e saída no mesmo inventário, contagem zero e `AJUSTE_INVENTARIO` |
| Desfazer inventário | `public/inventory_undo.php` | Ajustes inversos, cancelamento, vínculo original e IDs distintos nas bases |

Os fluxos são exercitados em **PUSH (local → web)** e **PULL (web → local)**.
Depois da sincronização, o teste exige fila drenada, nenhuma tentativa com erro,
recibos confirmados e nova execução sem duplicar registros. Há casos adicionais
para saldo insuficiente, token inválido e limpeza após uma falha proposital.

A entrada NF-e parte dos dados de uma nota já interpretada, enviados ao endpoint de
confirmação; upload e interpretação do XML no navegador estão fora desta suíte.
Login e apresentação também não são exercitados: o roteador temporário prepara
uma sessão de teste com o perfil correspondente à operação.

## Isolamento e limpeza

Cada caso recebe uma cópia temporária de `src/`, dos pontos de entrada usados e do
`sync2.php`. O bootstrap conecta cada processo a seu próprio arquivo SQLite.
Somente o bootstrap e os templates de apresentação são substituídos; os produtores,
a API e a lógica de sincronização são copiados sem alteração. O adaptador PDO
traduz `FOR UPDATE` e `DELETE ... ORDER BY ... LIMIT` para o dialeto SQLite.
Consequentemente, a suíte não valida bloqueios concorrentes, engine, migrations ou
todas as particularidades do MySQL. A view de alertas fica vazia para excluir
notificações e envio de e-mail do escopo dos testes de sincronização.

Os nomes de produto, códigos, números de nota e nomes XML incluem um identificador
`PYTEST_SYNC_<execução>`. Usuário, categoria, unidade, tipos e fornecedor básicos
são criados como referências nas duas bases antes dos testes.

O finalizador:

1. Para os processos PHP, aguardando sua saída.
2. Remove os registros gerados por suas chaves primárias, na ordem das dependências,
   incluindo fila, recibos, notas, mapeamentos XML, unidades, itens, movimentos,
   inventários e produtos.
3. Compara as bases com o snapshot inicial, preservando os registros de referência,
   e executa `PRAGMA foreign_key_check`.
4. Fecha as conexões e remove bases, sessões, logs e cópia temporária da aplicação.

Isso ocorre também se a preparação ou uma asserção falhar. Erros de limpeza fazem
o pytest acusar erro no finalizador. O teste `test_cleanup_even_when_assertion_fails`
cria registros nas duas bases, provoca uma falha e verifica a exclusão dos arquivos.
Um encerramento forçado do processo, como `kill -9`, impede a execução de qualquer
finalizador; nesse caso os arquivos ficam apenas no diretório temporário do pytest.

Para executar apenas esta suíte, use o caminho acima. Os testes antigos em
`testes/test_sync_nfe.py` têm configuração e dependências próprias.
