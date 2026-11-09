(() => {
    const endpoint = '/admin/product_suppliers.php';
    const node = (tag, text, cls) => {
        const el = document.createElement(tag);
        if (text !== undefined) el.textContent = text;
        if (cls) el.className = cls;
        return el;
    };
    async function request(params, post = false) {
        const response = await fetch(endpoint + (post ? '' : '?' + new URLSearchParams(params)), post ? {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams(params)
        } : {});
        let data;
        try { data = await response.json(); } catch (_) { throw new Error('Atualize a página e entre novamente para continuar.'); }
        if (!response.ok || !data.success) throw new Error(data.message || 'Não foi possível carregar os vínculos.');
        return data;
    }
    async function render(box) {
        const product = box.dataset.produtoId;
        const supplier = box.dataset.fornecedorId;
        const side = product ? 'produto' : 'fornecedor';
        box.replaceChildren(node('p', 'Carregando…'));
        try {
            const data = await request({[side + '_id']: product || supplier});
            box.replaceChildren();
            const status = node('div'); status.setAttribute('role', 'status'); box.append(status);
            const act = async (action, p, f) => {
                try {
                    await request({action, produto_id: p, fornecedor_id: f, csrf_token: box.dataset.csrf}, true);
                    await render(box);
                } catch (error) { status.textContent = error.message; status.className = 'alert alert-danger'; }
            };
            if (!data.data.length) box.append(node('p', 'Nenhum vínculo ativo.'));
            const list = node('ul', undefined, 'list-group mb-3');
            for (const row of data.data) {
                const li = node('li', undefined, 'list-group-item d-flex flex-wrap gap-2 align-items-center');
                const label = node('span', product ? row.fornecedor : row.produto, 'me-auto');
                if (product && (row.cnpj || row.cpf)) label.append(document.createTextNode(' — ' + (row.cnpj || row.cpf)));
                li.append(label);
                if (Number(row.principal)) li.append(node('span', 'Principal', 'badge bg-primary'));
                else {
                    const primary = node('button', 'Definir principal', 'btn btn-outline-primary btn-sm'); primary.type = 'button';
                    primary.onclick = () => act('principal', row.produto_id, row.fornecedor_id); li.append(primary);
                }
                const remove = node('button', 'Desvincular', 'btn btn-outline-danger btn-sm'); remove.type = 'button';
                remove.onclick = () => { if (confirm('Desvincular? As notas e movimentações serão preservadas.')) act('remove', row.produto_id, row.fornecedor_id); };
                li.append(remove); list.append(li);
            }
            box.append(list);
            const label = node('label', product ? 'Adicionar fornecedor' : 'Adicionar produto', 'form-label');
            const search = node('input', undefined, 'form-control mb-2'); search.type = 'search'; search.placeholder = 'Buscar por nome'; search.setAttribute('aria-label', 'Buscar opções');
            const select = node('select', undefined, 'form-select mb-2'); select.setAttribute('aria-label', label.textContent);
            const fill = () => {
                select.replaceChildren(new Option('Selecione…', ''));
                for (const choice of data.choices) {
                    const linked = data.data.some(row => Number(product ? row.fornecedor_id : row.produto_id) === Number(choice.id));
                    if (!linked && choice.nome.toLocaleLowerCase().includes(search.value.toLocaleLowerCase())) select.add(new Option(choice.nome, choice.id));
                }
            };
            search.oninput = fill; fill();
            const add = node('button', 'Adicionar', 'btn btn-primary'); add.type = 'button';
            add.onclick = () => { if (select.value) act('add', product || select.value, supplier || select.value); };
            box.append(label, search, select, add);
        } catch (error) { box.replaceChildren(node('div', error.message, 'alert alert-danger')); }
    }
    document.querySelectorAll('[data-product-suppliers]').forEach(render);
    const modal = document.getElementById('supplierProductsModal');
    if (modal) modal.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        modal.querySelector('.modal-title').textContent = 'Produtos — ' + button.dataset.supplierName;
        const box = modal.querySelector('[data-supplier-products]'); box.dataset.fornecedorId = button.dataset.supplierId;
        render(box);
    });
})();
