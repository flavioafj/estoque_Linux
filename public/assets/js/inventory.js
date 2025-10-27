document.addEventListener('DOMContentLoaded', function () {
    // -------------------------------------------------
    // 1. FUNÇÕES DE LOCALSTORAGE
    // -------------------------------------------------
    const STORAGE_KEY = 'inventory_quantities';

    /** Carrega dados salvos e preenche os inputs */
    function loadInventoryData() {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');

        document.querySelectorAll('input[name^="quantidade["]').forEach(input => {
            // nome = "quantidade[23]"  →  match = ["quantidade[23]", "23"]
            const match = input.name.match(/quantidade\[(\d+)\]/);
            if (match && saved[match[1]]) {
                input.value = saved[match[1]];
            }
        });
    }

    /** Salva o estado atual no localStorage */
    function saveInventoryData() {
        const data = {};

        document.querySelectorAll('input[name^="quantidade["]').forEach(input => {
            const match = input.name.match(/quantidade\[(\d+)\]/);
            if (match && input.value !== '') {
                data[match[1]] = input.value;
            }
        });

        localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
    }

    // -------------------------------------------------
    // 2. INICIALIZAÇÃO
    // -------------------------------------------------
    loadInventoryData();                     // preenche ao carregar a página

    // Salva a cada digitação (debounce opcional, mas simples assim já funciona)
    document.querySelectorAll('input[name^="quantidade["]').forEach(input => {
        input.addEventListener('input', saveInventoryData);
    });

    // -------------------------------------------------
    // 3. LIMPEZA APÓS SUBMISSÃO
    // -------------------------------------------------
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function () {
            localStorage.removeItem(STORAGE_KEY);
        });
    }

    // -------------------------------------------------
    // 4. BUSCA
    // -------------------------------------------------
    const searchInput = document.getElementById('search');
    if (searchInput) {
        searchInput.addEventListener('input', function (e) {
            const term = e.target.value.toLowerCase();
            document.querySelectorAll('.product-card').forEach(card => {
                const name = card.querySelector('h3').textContent.toLowerCase();
                card.style.display = name.includes(term) ? '' : 'none';
            });
        });
    }

    // -------------------------------------------------
    // 5. ORDENAÇÃO ALFABÉTICA
    // -------------------------------------------------
    const sortBtn = document.getElementById('sort-btn');
    if (sortBtn) {
        sortBtn.addEventListener('click', function () {
            const grid = document.querySelector('.product-grid');
            const cards = Array.from(grid.children);

            cards.sort((a, b) => {
                const nameA = a.querySelector('h3').textContent.toLowerCase();
                const nameB = b.querySelector('h3').textContent.toLowerCase();
                return nameA.localeCompare(nameB);
            });

            cards.forEach(card => grid.appendChild(card));
        });
    }
});