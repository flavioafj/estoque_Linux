<?php
// public/pos_saida.php
require_once '../config/config.php';
use Middleware\Auth;
use Helpers\Session;
use Models\Movimentacao;
use Models\MovimentacaoItem;
use Models\Product;

Auth::check();
if (Session::isAdmin()) { header('Location: /dashboard.php'); exit; }

$movId = intval($_GET['mov'] ?? 0);
if (!$movId) { header('Location: /produtos.php'); exit; }

$movModel   = new Movimentacao();
$mov        = $movModel->getById($movId);
if (!$mov || $mov['usuario_id'] != Session::getUserId()) {
    header('Location: /produtos.php'); exit;
}
if ($mov['corrigida']) {
    Session::setFlash('info', 'Esta saída já foi corrigida.');
    header('Location: /produtos.php'); exit;
}

$itemModel  = new MovimentacaoItem();
$itens      = $itemModel->where('movimentacao_id', $movId);
$produtoModel = new Product();

// Preparar array para edição
$editItens = [];
foreach ($itens as $i) {
    $prod = $produtoModel->getById($i['produto_id']);
    $editItens[] = [
        'produto'    => $prod,
        'quantidade' => $i['quantidade'],
        'item_id'    => $i['id']
    ];
}

/* ------------------------------------------------------------------ */
/*  PROCESSAMENTO DE CORREÇÃO                                         */
/* ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['corrigir'])) {
    $ajustes = [];               // produto_id => diferença (pode ser negativa)
    foreach ($_POST['qty'] ?? [] as $produtoId => $novaQty) {
        $novaQty = abs(floatval($novaQty));
        $original = array_filter($itens, fn($x) => $x['produto_id'] == $produtoId);
        $originalQty = $original ? reset($original)['quantidade'] : 0;
        $dif = $novaQty - $originalQty;
        if ($dif != 0) $ajustes[$produtoId] = $dif;
    }

    if (!empty($ajustes)) {
        $movModel->criarAjusteCompensatorio($movId, $ajustes, Session::getUserId());
        Session::setFlash('success', 'Correção aplicada com sucesso.');
            // Libera o bloqueio caso nenhuma ação seja executada
        Session::set('is_processing', false);
        header("Location: /logout.php");
        exit;
    }
}

/* ------------------------------------------------------------------ */
require_once '../templates/header.php';
require_once '../templates/navigation.php';
?>
<main class="container mt-4">
    <h1>Saída Registrada – Verifique os Itens</h1>
    <p class="text-muted">
        Você retirou os itens abaixo. Caso precise corrigir a quantidade, altere o campo e clique em
        <strong>Confirmar Correção</strong>.
    </p>

    <div id="timer" class="badge bg-warning text-dark mb-3">05:00</div>

    <form method="POST" id="form-correcao">
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr><th>Produto</th><th>Quantidade Retirada</th></tr>
            </thead>
            <tbody>
<?php foreach ($editItens as $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['produto']['nome']) ?></td>
                    <td>
                        <input type="number" step="1" min="0"
                               name="qty[<?= $item['produto']['id'] ?>]"
                               value="<?= $item['quantidade'] ?>"
                               class="form-control form-control-sm qty-input"
                               style="width:100px;">
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>

        <div class="d-flex gap-2">
            <a href="/produtos.php" class="btn btn-secondary">Adicionar produtos</a>
            <button type="submit" name="corrigir" class="btn btn-primary d-none" id="btn-corrigir">
                Confirmar Correção
            </button>
        </div>
    </form>
</main>

<script>
/* --------------------------------------------------------------
   TIMER DE 5 MINUTOS + PAUSA AO EDITAR
   -------------------------------------------------------------- */
let tempo = 300;
let timerId = null;
const timerEl = document.getElementById('timer');
const btnCorrigir = document.getElementById('btn-corrigir');
const inputs = document.querySelectorAll('.qty-input');

function format(t) {
    const m = String(Math.floor(t/60)).padStart(2,'0');
    const s = String(t%60).padStart(2,'0');
    return `${m}:${s}`;
}
function startTimer() {
    timerId = setInterval(() => {
        tempo--;
        timerEl.textContent = format(tempo);
        if (tempo <= 0) { clearInterval(timerId); location.href='/logout.php?motivo=timeout'; }
    }, 1000);
}
function stopTimer() { if (timerId) clearInterval(timerId); }

// iniciar
timerEl.textContent = format(tempo);
startTimer();

// pausar ao editar
inputs.forEach(i => {
    i.addEventListener('focus', stopTimer);
    i.addEventListener('blur', startTimer);
});

/* --------------------------------------------------------------
   MOSTRAR BOTÃO DE CORREÇÃO SOMENTE SE HOUVER ALTERAÇÃO
   -------------------------------------------------------------- */
<?php
// Monta array: produto_id => quantidade_original
$originalQty = [];
foreach ($editItens as $item) {
    $originalQty[$item['produto']['id']] = $item['quantidade'];
}
?>
const originalQty = <?= json_encode($originalQty); ?>;

inputs.forEach(inp => {
    const match = inp.name.match(/\[(\d+)\]/);
    if (!match) return;
    const prodId = match[1];
    const orig = originalQty[prodId] || 0;

    const check = () => {
        const atual = parseFloat(inp.value) || 0;
        const diff = Math.abs(atual - orig);
        btnCorrigir.classList.toggle('d-none', diff < 0.001);
    };

    inp.addEventListener('input', check);
    inp.addEventListener('change', check);
    check(); // inicial
});
</script>

<?php require_once '../templates/footer.php'; ?>