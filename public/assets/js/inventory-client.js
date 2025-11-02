/**
 * INVENTÁRIO CLIENT-SIDE (CORRIGIDO)
 * - Busca + Paginação + localStorage UNIFICADO
 */
class InventoryManager {
    constructor() {
        this.allProducts = [];
        this.filtered = [];
        this.currentPage = 1;
        this.perPage = 6;
        this.searchTerm = '';
        this.storageKey = 'inventory_sorveteria_data'; // ← CHAVE ÚNICA
        this.inventoryData = {}; // ← Estado em memória
    }

    async init() {
        await this.loadProducts();
        this.loadInventoryState();     // ← Carrega do localStorage
        this.render();
        this.bindEvents();
    }

    // -------------------------------------------------
    // 1. CARREGAR PRODUTOS
    // -------------------------------------------------
    async loadProducts() {
        try {
            const res = await fetch('api/products');
            if (!res.ok) throw new Error('Erro na API');
            this.allProducts = await res.json();
            this.filtered = [...this.allProducts];
        } catch (err) {
            this.showStatus('Erro ao carregar produtos: ' + err.message, 'danger');
        }
    }

    // -------------------------------------------------
    // 2. BUSCA
    // -------------------------------------------------
    filterProducts(term) {
        this.searchTerm = term.toLowerCase().trim();
        this.filtered = this.searchTerm
            ? this.allProducts.filter(p =>
                p.nome.toLowerCase().includes(this.searchTerm) ||
                (p.codigo && p.codigo.toLowerCase().includes(this.searchTerm))
              )
            : [...this.allProducts];
        this.currentPage = 1;
        this.render();
    }

    // -------------------------------------------------
    // 3. PAGINAÇÃO
    // -------------------------------------------------
    getTotalPages() {
        return Math.ceil(this.filtered.length / this.perPage) || 1;
    }

    getCurrentPageItems() {
        const start = (this.currentPage - 1) * this.perPage;
        return this.filtered.slice(start, start + this.perPage);
    }

    goToPage(page) {
        const total = this.getTotalPages();
        this.currentPage = Math.max(1, Math.min(page, total));
        this.renderPage();
        this.renderPagination();
        this.restoreCardValues(); // ← CRUCIAL
    }

    // -------------------------------------------------
    // 4. RENDER
    // -------------------------------------------------
    render() {
        this.renderPage();
        this.renderPagination();
        this.updateStatus();
    }

    renderPage() {
        const grid = document.getElementById('product-grid');
        const items = this.getCurrentPageItems();

        if (!items.length) {
            grid.innerHTML = `<p class="text-center text-muted">Nenhum produto encontrado.</p>`;
            return;
        }

        grid.innerHTML = items.map(p => this.renderCard(p)).join('');
        this.restoreCardValues(); // ← Aplica valores salvos
        this.loadRealImages();
    }

    loadRealImages() {
        document.querySelectorAll('img[data-real-src]').forEach(img => {
            const realSrc = img.dataset.realSrc;
            if (realSrc === img.src) return;

            fetch(realSrc, { method: 'HEAD' })
                .then(res => {
                    if (res.ok) img.src = realSrc;
                })
                .catch(() => { /* mantém fallback */ });
        });
    }

    renderCard(product) {
    const id = product.id;
    const fallbackImage = '/assets/images/fallback.jpg';
    const imageUrl = (product.foto_url && product.foto_url.trim() !== '')
        ? product.foto_url
        : fallbackImage;

    // Função para verificar se a imagem existe (HEAD request)
    const checkImageExists = (url) => {
        return fetch(url, { method: 'HEAD' })
            .then(res => res.ok ? url : fallbackImage)
            .catch(() => fallbackImage);
    };

    // Renderiza com fallback imediato, mas tenta carregar a imagem real
    const imgHtml = `<img src="${fallbackImage}" 
                          data-real-src="${imageUrl}" 
                          alt="${this.escapeHtml(product.nome)}" 
                          class="product-img" 
                          loading="lazy">`;

    return `
        <div class="product-card border p-3 rounded" data-id="${id}">
            ${imgHtml}
            <h3 class="h5">${this.highlight(product.nome)}</h3>
            <p class="text-muted small">Cód: ${product.codigo || '-'}</p>
            <p><strong>Estoque:</strong> ${parseFloat(product.estoque_atual).toFixed(3)} ${product.unidade_medida_sigla || ''}</p>

            <input type="number" 
                name="quantidade[${id}]" 
                step="1" 
                min="0" 
                class="form-control form-control-sm mt-1"
                placeholder="Qtd contada" 
                data-id="${id}" 
                data-type="count">
        </div>`;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    highlight(text) {
        if (!this.searchTerm) return text;
        const regex = new RegExp(`(${this.escapeRegExp(this.searchTerm)})`, 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }

    escapeRegExp(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    renderPagination() {
        const nav = document.getElementById('pagination');
        const total = this.getTotalPages();
        if (total <= 1) { nav.innerHTML = ''; return; }

        let html = `<ul class="pagination justify-content-center">`;
        const addPage = (label, page, disabled = false) => {
            html += `<li class="page-item ${disabled ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${page}">${label}</a></li>`;
        };

        addPage('Primeiro', 1, this.currentPage === 1);
        addPage('Anterior', this.currentPage - 1, this.currentPage === 1);

        for (let i = Math.max(1, this.currentPage - 2); i <= Math.min(total, this.currentPage + 2); i++) {
            html += `<li class="page-item ${i === this.currentPage ? 'active' : ''}">
                <a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }

        addPage('Próximo', this.currentPage + 1, this.currentPage === total);
        addPage('Último', total, this.currentPage === total);
        html += `</ul>`;
        nav.innerHTML = html;
    }

    // -------------------------------------------------
    // 5. LOCALSTORAGE UNIFICADO
    // -------------------------------------------------
    loadInventoryState() {
        try {
            const raw = localStorage.getItem(this.storageKey);
            this.inventoryData = raw ? JSON.parse(raw) : {};
        } catch (e) {
            this.inventoryData = {};
        }
    }

    saveInventoryState() {
        localStorage.setItem(this.storageKey, JSON.stringify(this.inventoryData));
    }

    updateInventory(id, type, value) {
        if (!this.inventoryData[id]) this.inventoryData[id] = {};
        this.inventoryData[id][type] = value;
        this.saveInventoryState();
    }

    getInventoryValue(id, type) {
        return this.inventoryData[id]?.[type] ?? (type === 'select' ? false : '');
    }

    restoreCardValues() {
        document.querySelectorAll('[data-id]').forEach(el => {
            const id = el.dataset.id;
            const type = el.dataset.type;
            if (!type) return;

            const value = this.getInventoryValue(id, type);
            if (type === 'select') {
                el.checked = !!value;
            } else if (type === 'count') {
                el.value = value;
            }
        });
    }

    // -------------------------------------------------
    // 6. EVENTOS
    // -------------------------------------------------
    bindEvents() {
        // Busca
        const search = document.getElementById('search');
        search?.addEventListener('input', e => this.filterProducts(e.target.value));

        document.getElementById('clear-search')?.addEventListener('click', () => {
            search.value = '';
            this.filterProducts('');
        });

        // Paginação
        document.getElementById('pagination').addEventListener('click', e => {
            const link = e.target.closest('a[data-page]');
            if (link) {
                e.preventDefault();
                this.goToPage(parseInt(link.dataset.page));
            }
        });

        // Inputs do inventário
        document.getElementById('product-grid').addEventListener('change', e => {
            const el = e.target;
            if (el.matches('[data-type="select"]')) {
                this.updateInventory(el.dataset.id, 'select', el.checked);
            }
        });

        document.getElementById('product-grid').addEventListener('input', e => {
            const el = e.target;
            if (el.matches('[data-type="count"]')) {
                this.updateInventory(el.dataset.id, 'count', el.value);
            }
        });

        // LIMPAR INVENTÁRIO
        document.getElementById('clear-inventory')?.addEventListener('click', () => {
            if (confirm('Tem certeza que deseja limpar todo o inventário?')) {
                localStorage.removeItem(this.storageKey);  // ← Agora funciona!
                this.inventoryData = {};
                this.render();
                this.showStatus('Inventário limpo com sucesso!', 'success');
            }
        });

        // Submissão
        document.getElementById('inventory-form').addEventListener('submit', (e) => {
            this.prepareSubmission();
        });
    }

    // -------------------------------------------------
    // 7. SUBMISSÃO
    // -------------------------------------------------
    prepareSubmission() {
        const form = document.getElementById('inventory-form');

        // Remove inputs antigos
        form.querySelectorAll('input[name^="quantidade["], input[name^="selecionado["]').forEach(el => el.remove());

        // Injeta TODOS os dados do localStorage
        Object.keys(this.inventoryData).forEach(id => {
            const item = this.inventoryData[id];

            // Só envia se houver contagem OU checkbox marcado
            if (item.count || item.select) {
                // Checkbox "selecionado"
                const hiddenCheck = document.createElement('input');
                hiddenCheck.type = 'hidden';
                hiddenCheck.name = `selecionado[${id}]`;
                hiddenCheck.value = item.select ? '1' : '0';
                form.appendChild(hiddenCheck);

                // Quantidade contada
                const hiddenCount = document.createElement('input');
                hiddenCount.type = 'hidden';
                hiddenCount.name = `quantidade[${id}]`;
                hiddenCount.value = item.count || '';
                form.appendChild(hiddenCount);
            }
        });

        // Opcional: limpar após envio
        localStorage.removeItem(this.storageKey);
    }

    // -------------------------------------------------
    // 8. STATUS
    // -------------------------------------------------
    updateStatus() {
        const total = this.filtered.length;
        const showing = this.getCurrentPageItems().length;
        const status = document.getElementById('status');
        status.innerHTML = this.searchTerm
            ? `<small class="text-muted">Mostrando ${showing} de ${total} resultado(s) para "<strong>${this.highlight(this.searchTerm)}</strong>"</small>`
            : `<small class="text-muted">${total} produto(s) • Página ${this.currentPage} de ${this.getTotalPages()}</small>`;

        
    }

    showStatus(msg, type = 'info') {
        const status = document.getElementById('status');
        status.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show">
            ${msg}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>`;
    }
}


// -------------------------------------------------
// INICIAR
// -------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    new InventoryManager().init();
});