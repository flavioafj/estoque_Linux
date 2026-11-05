"""Executa produtores reais -> fila -> sync2.php -> API -> base de destino."""
from datetime import date
import json

import pytest

from conftest import http, temporary_environment

DIRECTIONS = ["local", "web"]


def pending(env, side, action, table="movimentacoes"):
    row = env.bases[side].one(
        "SELECT * FROM sincronizacao WHERE sincronizado = 0 AND acao = ? AND tabela = ?", (action, table))
    assert row["tentativas"] == 0
    assert row["sincronizado_em"] is None
    assert json.loads(row["dados"])
    return row


def quantities(env, expected):
    for side, db in env.bases.items():
        for product, amount in expected.items():
            row = db.one("SELECT estoque_atual FROM produtos WHERE id = ?", (product,))
            assert row["estoque_atual"] == amount, (side, product, row, amount)
            assert db.scalar("SELECT COUNT(*) FROM estoques WHERE produto_id = ?", (product,)) == amount, side
        assert db.rows("PRAGMA foreign_key_check") == []


def create_products(env, source, count=1):
    ids = []
    for index in range(count):
        name = f"{env.prefix}_{index}"
        env.action(source, "product", {
            "nome": name, "codigo": name, "categoria_id": 1, "unidade_medida_id": 1,
            "estoque_atual": 0, "estoque_minimo": 1, "estoque_maximo": 100,
            "preco_custo": 4.25, "preco_venda": 7.50, "margem_lucro": 50,
            "fornecedor_principal_id": "", "descricao": "Produto temporário pytest", "ativo": "on",
        })
        row = env.bases[source].one("SELECT * FROM produtos WHERE nome = ?", (name,))
        ids.append(row["id"])
    env.sync()
    env.assert_drained()
    return ids


def entry(env, source, products, amount=10):
    env.action(source, "entry", {"tipo_movimentacao_id": 1, "observacao": env.prefix,
                                "produtos": {pid: amount for pid in products},
                                "valor_unitario": {pid: 4.25 for pid in products}})
    row = pending(env, source, "ENTRADA_MANUAL")
    env.sync()
    quantities(env, {pid: amount for pid in products})
    return row


def stable_after_sync(env):
    env.assert_drained()
    before = {side: db.snapshot() for side, db in env.bases.items()}
    env.sync()
    env.assert_drained()
    assert {side: db.snapshot() for side, db in env.bases.items()} == before, "Nova execução duplicou registros."


@pytest.mark.parametrize("source", DIRECTIONS, ids=["push", "pull"])
def test_product_creation_inactivation_reactivation(environment, source):
    env = environment
    product = create_products(env, source)[0]
    for side, db in env.bases.items():
        row = db.one("SELECT * FROM produtos WHERE id = ?", (product,))
        assert row["nome"].startswith(env.prefix)
        assert row["ativo"] == 1
        assert row["preco_custo"] == 4.25
        assert db.scalar("SELECT COUNT(*) FROM sincronizacao WHERE acao = 'INSERT' AND tabela = 'produtos'") == (1 if side == source or source == "local" else 0)
    quantities(env, {product: 0})
    for operation, active in (("inactivate", 0), ("reactivate", 1)):
        env.action(source, operation, {"id": product})
        event = pending(env, source, "UPDATE", "produtos")
        assert json.loads(event["dados"])["ativo"] == active
        env.sync()
        for db in env.bases.values():
            assert db.scalar("SELECT ativo FROM produtos WHERE id = ?", (product,)) == active
    stable_after_sync(env)


@pytest.mark.parametrize("source", DIRECTIONS, ids=["push", "pull"])
def test_direct_entry(environment, source):
    env = environment
    products = create_products(env, source, 2)
    event = entry(env, source, products, 3)
    assert json.loads(event["dados"])["total"] == 25.50
    for db in env.bases.values():
        assert db.scalar("SELECT COUNT(*) FROM movimentacoes") == 1
        assert db.scalar("SELECT valor_total FROM movimentacoes") == 25.50
        assert db.scalar("SELECT COUNT(*) FROM movimentacao_itens") == 2
        assert db.scalar("SELECT SUM(valor_total) FROM movimentacao_itens") == 25.50
    stable_after_sync(env)


@pytest.mark.parametrize("source", DIRECTIONS, ids=["push", "pull"])
def test_nfe_entry(environment, source):
    env = environment
    products = create_products(env, source, 2)
    number = env.prefix
    key = str(int(env.token, 16)).zfill(44)[-44:]
    env.action(source, "nfe", {
        "fornecedor_cnpj": "50902071001120", "fornecedor_razao_social": "PYTEST_BASE_FORNECEDOR",
        "nota_numero": number, "nota_chave_acesso": key, "nota_data_emissao": str(date.today()),
        "nota_valor_total": 25.50, "id_fornecedor_sistema": "1",
        "produtos": [{"id_produto_sistema": pid, "quantidade": 3, "valor_unitario": 4.25,
                      "nome_xml": f"{env.prefix}_XML_{pid}"} for pid in products],
    })
    event = pending(env, source, "ENTRADA_NFE", "notas_fiscais")
    assert json.loads(event["dados"])["chave_acesso"] == key
    env.sync()
    quantities(env, {pid: 3 for pid in products})
    for db in env.bases.values():
        note = db.one("SELECT * FROM notas_fiscais WHERE chave_acesso = ?", (key,))
        assert note["numero_nota"] == number
        assert note["valor_total"] == 25.50
        assert note["processado"] == 1
        assert db.scalar("SELECT valor_total FROM movimentacoes WHERE id = ?", (note["movimentacao_id"],)) == 25.50
        assert db.scalar("SELECT COUNT(*) FROM itens_nota") == 2
    stable_after_sync(env)


@pytest.mark.parametrize("source", DIRECTIONS, ids=["push", "pull"])
@pytest.mark.parametrize("admin", [False, True], ids=["direta", "administrador"])
def test_exits(environment, source, admin):
    env = environment
    products = create_products(env, source, 2 if admin else 1)
    entry(env, source, products)
    if admin:
        env.action(source, "admin_exit", {"tipo_movimentacao_id": 5, "observacao": env.prefix,
                                          "produtos": {pid: 2 for pid in products}})
        pending(env, source, "SAIDA_DIRETA_ADM")
        total = 17.0
    else:
        env.action(source, "exit", {"produto_id": products[0], "quantidade": 2})
        pending(env, source, "SAIDA_DIRETA")
        total = 8.50
    env.sync()
    quantities(env, {pid: 8 for pid in products})
    for db in env.bases.values():
        movement = db.one("SELECT * FROM movimentacoes WHERE tipo_movimentacao_id = 5")
        assert movement["valor_total"] == total
        assert db.scalar("SELECT SUM(quantidade) FROM movimentacao_itens WHERE movimentacao_id = ?", (movement["id"],)) == 2 * len(products)
        assert db.scalar("SELECT COUNT(*) FROM movimentacoes") == 2
    stable_after_sync(env)


@pytest.mark.parametrize("source", DIRECTIONS, ids=["push", "pull"])
def test_inventory_and_undo(environment, source):
    env = environment
    destination = "web" if source == "local" else "local"
    # Força IDs distintos do histórico entre as bases sem criar dados de negócio.
    with env.bases[destination].conn:
        env.bases[destination].conn.execute("INSERT INTO sqlite_sequence (name, seq) VALUES ('inventarios', 100)")
        env.bases[destination].conn.execute("INSERT INTO sqlite_sequence (name, seq) VALUES ('movimentacoes', 100)")
    products = create_products(env, source, 2)
    entry(env, source, products)
    counts = {products[0]: 8, products[1]: 13}
    env.action(source, "inventory", {"quantidade": counts})
    original = pending(env, source, "AJUSTE_INVENTARIO")
    original_uuid = json.loads(original["dados"])["evento_id"]
    env.sync()
    quantities(env, counts)
    for db in env.bases.values():
        inventory = db.one("SELECT * FROM inventarios")
        assert inventory["status"] == "CONCLUIDO"
        assert db.scalar("SELECT COUNT(*) FROM movimentacoes WHERE inventario_id = ?", (inventory["id"],)) == 2
    stable_after_sync(env)
    inventory = env.bases[source].one("SELECT * FROM inventarios")
    env.action(source, "undo", {"inventario_id": inventory["id"]})
    undo = pending(env, source, "AJUSTE_INVENTARIO")
    assert json.loads(undo["dados"])["desfaz_evento_id"] == original_uuid
    env.sync()
    quantities(env, {pid: 10 for pid in products})
    for db in env.bases.values():
        assert db.scalar("SELECT status FROM inventarios") == "CANCELADO"
        assert db.scalar("SELECT COUNT(*) FROM movimentacoes WHERE inventario_id IS NOT NULL") == 4
    stable_after_sync(env)


def test_cleanup_even_when_assertion_fails(tmp_path):
    paths = []
    with pytest.raises(AssertionError, match="falha proposital"):
        with temporary_environment(tmp_path) as env:
            paths = [db.path for db in env.bases.values()]
            product = create_products(env, "local")[0]
            entry(env, "local", [product], 3)
            raise AssertionError("falha proposital após criar e sincronizar registros")
    assert paths and all(not path.exists() for path in paths), "Bases temporárias não foram removidas."


@pytest.mark.parametrize("source", DIRECTIONS, ids=["push", "pull"])
def test_inventory_zero_count(environment, source):
    env = environment
    product = create_products(env, source)[0]
    entry(env, source, [product], 3)
    env.action(source, "inventory", {"quantidade": {product: "0"}})
    event = pending(env, source, "AJUSTE_INVENTARIO")
    assert json.loads(event["dados"])["itens"][str(product)]["diferenca"] == -3
    env.sync()
    quantities(env, {product: 0})
    stable_after_sync(env)


@pytest.mark.parametrize("source", DIRECTIONS, ids=["push", "pull"])
def test_insufficient_stock_does_not_enqueue_exit(environment, source):
    env = environment
    product = create_products(env, source)[0]
    entry(env, source, [product], 3)
    before = env.bases[source].snapshot()
    body = env.action(source, "exit", {"produto_id": product, "quantidade": 10})
    response = json.loads(body)
    assert response["success"] is False
    assert env.bases[source].scalar("SELECT COUNT(*) FROM sincronizacao WHERE sincronizado = 0") == 0
    # O produtor antigo pode deixar um cabeçalho de movimento sem itens;
    # saldo, unidades e fila devem permanecer iguais.
    after = env.bases[source].snapshot()
    for table in ("produtos", "estoques", "movimentacao_itens", "sincronizacao"):
        assert after[table] == before[table], table
    env.sync()
    quantities(env, {product: 3})


def test_invalid_token_preserves_both_databases(environment):
    env = environment
    before = {side: db.snapshot() for side, db in env.bases.items()}
    status, body = http(env.urls["web"] + "/api/sync.php?protocol=2", headers={"X-Api-Token": "Bearer invalido"})
    assert status == 401
    assert json.loads(body)["success"] is False
    assert {side: db.snapshot() for side, db in env.bases.items()} == before
