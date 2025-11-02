/**
 * PRODUTOS - CLIENT-SIDE
 * Paginação + Busca + Ações (Pegar / Carrinho)
 * Não conflita com main.js
 */

class ProdutosManager {
    constructor() {
        this.allProducts = [];
        this.filtered = [];
        this.currentPage = 1;
        this.perPage = 6; // 3x4 grid
        this.searchTerm = '';
    }

    async init() {
        await this.loadProducts();
        this.render();
        this.bindEvents();
    }

    async loadProducts() {
        try {
            const res = await fetch('/produtos.php?api=products');
            if (!res.ok) throw new Error('Erro na API');
            this.allProducts = await res.json();
            this.filtered = [...this.allProducts];
        } catch (err) {
            this.showStatus('Erro ao carregar produtos: ' + err.message, 'danger');
        }
    }

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
    }

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

        // Usa o mesmo product-card.php via template
        grid.innerHTML = items.map(p => this.renderCard(p)).join('');
    }

    renderCard(product) {
        const fallback = '/assets/images/fallback.jpg';
        const imgSrc = (product.foto_url && product.foto_url.trim() !== '')
            ? product.foto_url
            : fallback;

        // Verifica existência da imagem
        const checkImg = (url) => fetch(url, { method: 'HEAD' })
            .then(r => r.ok ? url : fallback)
            .catch(() => fallback);

        // Renderiza com fallback imediato
        const imgHtml = `<img src="${fallback}" data-real-src="${imgSrc}" alt="${this.escape(product.nome)}" class="card-img">`;

        return `
        <div class="product-card">
            ${imgHtml}
            <h3>${this.highlight(product.nome)}</h3>
            <p>Estoque: ${parseFloat(product.estoque_atual).toFixed(3)}</p>
            <input type="number" id="qty-${product.id}" step="1" value="0" min="0">
            <button class="btn btn-pegar" data-produto-id="${product.id}">Pegar</button>
            <button class="btn btn-add-cart" data-produto-id="${product.id}">Adicionar ao Carrinho</button>
        </div>`;
    }

    highlight(text) {
        if (!this.searchTerm) return text;
        const regex = new RegExp(`(${this.escapeRegExp(this.searchTerm)})`, 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }

    escapeRegExp(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    escape(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    renderPagination() {
        const nav = document.getElementById('pagination');
        const total = this.getTotalPages();
        if (total <= 1) { nav.innerHTML = ''; return; }

        let html = `<ul class="pagination justify-content-center">`;
        const add = (label, page, disabled = false) => {
            html += `<li class="page-item ${disabled ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${page}">${label}</a></li>`;
        };

        add('Primeiro', 1, this.currentPage === 1);
        add('Anterior', this.currentPage - 1, this.currentPage === 1);
        for (let i = Math.max(1, this.currentPage - 2); i <= Math.min(total, this.currentPage + 2); i++) {
            html += `<li class="page-item ${i === this.currentPage ? 'active' : ''}">
                <a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }
        add('Próximo', this.currentPage + 1, this.currentPage === total);
        add('Último', total, this.currentPage === total);
        html += `</ul>`;
        nav.innerHTML = html;
    }

    bindEvents() {
        // Busca
        document.getElementById('search')?.addEventListener('input', e => {
            this.filterProducts(e.target.value);
        });

        document.getElementById('clear-search')?.addEventListener('click', () => {
            document.getElementById('search').value = '';
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

        // AÇÕES: Pegar / Carrinho (delegação)
        document.getElementById('product-grid').addEventListener('click', e => {
            const pegarBtn = e.target.closest('.btn-pegar');
            const cartBtn = e.target.closest('.btn-add-cart');

            if (pegarBtn) this.handleSaida(pegarBtn);
            if (cartBtn) this.handleCarrinho(cartBtn);
        });

        // Carregar imagens reais
        this.loadRealImages();
    }

    async handleSaida(btn) {
        if (btn.disabled) return;
        btn.disabled = true;
        btn.innerText = 'Processando...';

        const id = btn.dataset.produtoId;
        const qty = parseFloat(document.getElementById(`qty-${id}`).value) || 0;

        if (qty <= 0) {
            btn.disabled = false;
            btn.innerText = 'Pegar';
            return;
        }

        try {
            const res = await fetch('/produtos.php?action=saida_direta', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `produto_id=${id}&quantidade=${qty}`
            });
            const data = await res.json();

            if (data.success) {
                alert('Saída registrada com sucesso!');
                location.reload();
            } else {
                alert('Erro: ' + data.message);
                btn.disabled = false;
                btn.innerText = 'Pegar';
            }
        } catch {
            alert('Erro de comunicação.');
            btn.disabled = false;
            btn.innerText = 'Pegar';
        }
    }

    async handleCarrinho(btn) {
        if (btn.disabled) return;
        btn.disabled = true;

        const id = btn.dataset.produtoId;
        const qty = parseFloat(document.getElementById(`qty-${id}`).value) || 0;

        if (qty <= 0) {
            btn.disabled = false;
            return;
        }

        try {
            const res = await fetch('/produtos.php?action=add_cart', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `produto_id=${id}&quantidade=${qty}`
            });
            const data = await res.json();

            if (data.success) {
                alert('Adicionado ao carrinho!');
                btn.disabled = false;

                const badge = document.querySelector('.cart-badge');
                let count = (parseInt(badge?.textContent) || 0) + 1;
                if (!badge) {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'cart-badge';
                    newBadge.textContent = '1';
                    document.querySelector('.cart-link').appendChild(newBadge);
                } else {
                    badge.textContent = count;
                }
            } else {
                alert('Erro: ' + data.message);
                btn.disabled = false;
            }


        } catch {
            alert('Erro de comunicação.');
            btn.disabled = false;
        }
    }

    loadRealImages() {
        document.querySelectorAll('img[data-real-src]').forEach(img => {
            const real = img.dataset.realSrc;
            if (real === img.src) return;
            fetch(real, { method: 'HEAD' })
                .then(r => { if (r.ok) img.src = real; })
                .catch(() => {});
        });
    }

    updateStatus() {
        const total = this.filtered.length;
        const showing = this.getCurrentPageItems().length;
        const status = document.getElementById('status');
        status.innerHTML = this.searchTerm
            ? `<small class="text-muted">Mostrando ${showing} de ${total} resultado(s)</small>`
            : `<small class="text-muted">${total} produto(s) • Página ${this.currentPage} de ${this.getTotalPages()}</small>`;
    }

    showStatus(msg, type = 'info') {
        document.getElementById('status').innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
    }
}

// Iniciar SEM interferir no main.js
document.addEventListener('DOMContentLoaded', () => {
    // Só inicia se estiver na página de produtos
    if (document.querySelector('#product-grid')) {
        new ProdutosManager().init();
    }
});