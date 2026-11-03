"""Testar saída direta: login, retirada de uma unidade e confirmação na tela."""

import os
from pathlib import Path
from urllib.parse import parse_qs, urlparse

import pytest
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait


def test_testar_saida_direta(tmp_path):
    """Retira 1 do primeiro produto e verifica o texto Saída Registrada."""
    base_url = os.getenv("SAIDA_DIRETA_URL")
    if not base_url:
        pytest.skip("Defina SAIDA_DIRETA_URL para executar a retirada real de estoque.")

    options = webdriver.ChromeOptions()
    if os.getenv("HEADLESS", "true").lower() == "true":
        options.add_argument("--headless")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--window-size=1365,1000")
    if os.getenv("CHROME_NO_SANDBOX") == "1":
        options.add_argument("--no-sandbox")
    if os.getenv("CHROME_BINARY"):
        options.binary_location = os.environ["CHROME_BINARY"]
    driver_path = os.getenv("CHROMEDRIVER")
    service = Service(driver_path) if driver_path else Service()
    driver = webdriver.Chrome(service=service, options=options)
    try:
        driver.set_page_load_timeout(60)
        wait = WebDriverWait(driver, 30)
        driver.get(base_url.rstrip("/") + "/")
        usuario = wait.until(EC.visibility_of_element_located((By.ID, "usuario")))
        usuario.clear()
        usuario.send_keys(os.getenv("SAIDA_DIRETA_USUARIO", "0716"))
        driver.find_element(By.XPATH, "//button[normalize-space()='Entrar']").click()

        # Usa o campo vinculado ao primeiro botão Pegar, sem trocar de produto.
        pegar = wait.until(EC.element_to_be_clickable((By.CSS_SELECTOR, ".btn-pegar")))
        produto_id = pegar.get_attribute("data-produto-id")
        quantidade = driver.find_element(By.ID, f"qty-{produto_id}")
        quantidade.clear()
        quantidade.send_keys("1")
        pegar.click()

        wait.until(EC.url_contains("/pos_saida.php"))
        wait.until(EC.text_to_be_present_in_element((By.TAG_NAME, "body"), "Saída Registrada"))
        destino = urlparse(driver.current_url)
        assert destino.path == "/pos_saida.php", driver.current_url
        movimentacao = parse_qs(destino.query).get("mov", [""])[0]
        assert movimentacao.isdigit() and int(movimentacao) > 0, driver.current_url
        assert "Saída Registrada" in driver.find_element(By.TAG_NAME, "body").text
        print(f"Saída direta registrada: produto={produto_id}, quantidade=1, mov={movimentacao}")
    except Exception:
        screenshot = Path(tmp_path) / "testar_saida_direta_erro.png"
        driver.save_screenshot(str(screenshot))
        print(f"Falha em {driver.current_url}. Screenshot: {screenshot}")
        raise
    finally:
        driver.quit()
