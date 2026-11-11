/**
 * JAVASCRIPT - GESTÃO DE PRODUTOS (Bootstrap 5)
 * Fixed: Added BASE_URL to all API fetch calls
 * Patch: Adiciona checagem e logs para BASE_URL e RESTAURANTE_ID
 */

if (typeof BASE_URL === 'undefined' || !BASE_URL) {
    console.error('BASE_URL não está definido!');
    NivoraFeedback.error('Erro: BASE_URL não está definido!');
}
if (typeof RESTAURANTE_ID === 'undefined' || !RESTAURANTE_ID) {
    console.error('RESTAURANTE_ID não está definido!');
    NivoraFeedback.error('Erro: RESTAURANTE_ID não está definido!');
}
let modalProdutoInstance = null;
const produtosCache = new Map();
let estoqueModalInstance = null;
let estoqueOperacaoAtual = 'ENTRADA';
let estoqueProdutoAtual = null;

function resolveApiCandidates(endpoint) {
    const clean = String(endpoint || '').replace(/^\/+/, '');
    const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
    const candidates = [];
    candidates.push(clean); // relativo ao diretorio atual (/V00/src/public/)
    candidates.push(basePath + clean); // fallback relativo calculado
    candidates.push('/V00/src/public/' + clean); // fallback absoluto interno do projeto
    return [...new Set(candidates)];
}

async function fetchWithFallback(endpoint, options = {}) {
    const candidates = resolveApiCandidates(endpoint);
    let lastError = null;
    let lastResponse = null;

    const xhrFallback = (url, opts = {}) => new Promise((resolve, reject) => {
        try {
            const xhr = new XMLHttpRequest();
            xhr.open(opts.method || 'GET', url, true);
            xhr.withCredentials = true;
            const headers = opts.headers || {};
            Object.keys(headers).forEach((k) => xhr.setRequestHeader(k, headers[k]));
            xhr.onload = () => {
                const body = xhr.responseText || '';
                resolve({
                    ok: xhr.status >= 200 && xhr.status < 300,
                    status: xhr.status,
                    statusText: xhr.statusText || '',
                    text: async () => body,
                    json: async () => JSON.parse(body),
                    headers: { entries: () => [] }
                });
            };
            xhr.onerror = () => reject(new Error('XHR network error'));
            xhr.send(opts.body || null);
        } catch (e) {
            reject(e);
        }
    });

    for (let i = 0; i < candidates.length; i++) {
        try {
            const response = await fetch(candidates[i], options);
            if (response.ok) {
                return response;
            }
            if (response.status === 404) {
                lastResponse = response;
                continue;
            }
            return response;
        } catch (err) {
            lastError = err;
            if (!/Failed to fetch|NetworkError|ERR_CONNECTION_CLOSED|conexao/i.test(String(err?.message || ''))) {
                throw err;
            }
            try {
                const xhrResp = await xhrFallback(candidates[i], options);
                if (xhrResp.ok) {
                    return xhrResp;
                }
                if (xhrResp.status === 404) {
                    lastResponse = xhrResp;
                    continue;
                }
                return xhrResp;
            } catch (xhrErr) {
                lastError = xhrErr;
            }
        }
    }
    if (lastResponse) {
        return lastResponse;
    }
    throw lastError || new Error('Falha de conexao com API');
}

document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('modalProduto');
    if (modalEl) modalProdutoInstance = new bootstrap.Modal(modalEl);
    
    const novaCategoria = document.getElementById('novaCategoria');
    if(novaCategoria) novaCategoria.addEventListener('keypress', function(e) { 
        if(e.key === 'Enter') { e.preventDefault(); adicionarNovaCategoria(); } 
    });
    
    const form = document.getElementById('formProduto');
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            const imgInput = document.getElementById('imagem');
            let file = imgInput && imgInput.files && imgInput.files[0] ? imgInput.files[0] : null;
            if (file) {
                try {
                    const compressed = await compressImageFile(file);
                    if (compressed) {
                        assignFileToInput(imgInput, compressed);
                        file = imgInput.files?.[0] || compressed;
                    }
                } catch (err) {}
            }
            if (file && file.size > 8 * 1024 * 1024) {
                showAlertModal('A imagem é demasiado grande. Use um ficheiro até 8 MB.', 'danger');
                return;
            }

            const formData = new FormData(form);
            if (file) {
                formData.set('imagem', file, file.name || 'produto.jpg');
            }
            formData.append('_csrf', getCsrfToken());
            // Garante valores dos checkboxes (FormData omite unchecked)
            formData.set('ativo', document.getElementById('ativo')?.checked ? '1' : '0');
            const destaqueEl = document.getElementById('destaque');
            if (destaqueEl) {
                formData.set('destaque', destaqueEl.checked ? '1' : '0');
            }

            const produto_id = (document.getElementById('produto_id')?.value || '').trim();
            const endpoint = (produto_id ? 'api/produto_editar.php' : 'api/produto_cadastrar.php');
            const btnSalvar = document.querySelector('button[type="submit"][form="formProduto"]');
            if (btnSalvar) {
                btnSalvar.disabled = true;
                btnSalvar.dataset._old = btnSalvar.innerHTML;
                btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>A guardar…';
            }

            fetchWithFallback(endpoint, { method: 'POST', body: formData, headers: csrfHeaders() })
            .then(async (response) => {
                const raw = await response.text();
                let data = null;
                try {
                    data = raw ? JSON.parse(raw) : null;
                } catch (err) {
                    throw new Error(raw ? 'Resposta inválida do servidor.' : 'Sem resposta do servidor (imagem demasiado grande?).');
                }
                if (!data) {
                    throw new Error('Sem resposta do servidor.');
                }
                return data;
            })
            .then(data => {
                if (data.success) {
                    showAlertModal(data.message || 'Guardado com sucesso.', 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showAlertModal(data.message || 'Não foi possível guardar.', 'danger');
                }
            })
            .catch(error => {
                showAlertModal((error && error.message) ? error.message : 'Erro ao processar', 'danger');
            })
            .finally(() => {
                if (btnSalvar) {
                    btnSalvar.disabled = false;
                    if (btnSalvar.dataset._old) btnSalvar.innerHTML = btnSalvar.dataset._old;
                }
            });
        });
    }
    
    const inputBuscar = document.getElementById('buscar');
    const filtroCategoria = document.getElementById('filtroCategoria');
    const filtroEstoque = document.getElementById('filtroEstoque');
    if(inputBuscar) inputBuscar.addEventListener('keyup', filtrarProdutos);
    if(filtroCategoria) filtroCategoria.addEventListener('change', filtrarProdutos);
    if(filtroEstoque) filtroEstoque.addEventListener('change', filtrarProdutos);

    const btnConfirmarEstoque = document.getElementById('btnConfirmarEstoque');
    if (btnConfirmarEstoque) {
        btnConfirmarEstoque.addEventListener('click', confirmarAjusteEstoque);
    }

    document.querySelectorAll('.stock-op-btn').forEach((btn) => {
        btn.addEventListener('click', function () {
            estoqueOperacaoAtual = this.getAttribute('data-tipo') || 'ENTRADA';
            document.querySelectorAll('.stock-op-btn').forEach((item) => item.classList.remove('active'));
            this.classList.add('active');
            atualizarPreviewEstoqueModal();
        });
    });

    const estoqueQuantidadeInput = document.getElementById('estoqueQuantidadeInput');
    if (estoqueQuantidadeInput) {
        estoqueQuantidadeInput.addEventListener('input', atualizarPreviewEstoqueModal);
    }
    
    const inputImagem = document.getElementById('imagem');
    if (inputImagem) {
        inputImagem.addEventListener('change', function() {
            previewImagem(inputImagem);
        });
    }

    if (Array.isArray(window.PRODUTOS_INICIAIS)) {
        window.PRODUTOS_INICIAIS.forEach((p) => {
            if (p && typeof p.id !== 'undefined') {
                produtosCache.set(String(p.id), p);
            }
        });
    }
});

function preencherModalProduto(produto) {
    produto = (produto && produto.data && typeof produto.data === 'object')
        ? produto.data
        : (produto || {});
    const valor = (campo, fallback = '') => {
        const value = produto[campo];
        return value === null || typeof value === 'undefined' ? fallback : value;
    };
    document.getElementById('tituloModal').textContent = 'Editar Produto';
    document.getElementById('produto_id').value = valor('id', '');
    const nomeProduto = valor('nome', valor('produto_nome', valor('nome_produto', valor('name', valor('title', '')))));
    document.getElementById('nome').value = String(nomeProduto || '').trim();
    const selectCategoria = document.getElementById('categoria_id');
    if (selectCategoria) {
        const categoriaIdStr = valor('categoria_id', '') ? String(valor('categoria_id', '')) : '';
        let found = false;
        for (let i = 0; i < selectCategoria.options.length; i++) {
            if (selectCategoria.options[i].value === categoriaIdStr) {
                selectCategoria.selectedIndex = i;
                found = true;
                break;
            }
        }
        if (!found) selectCategoria.selectedIndex = 0;
    }
    document.getElementById('descricao').value = valor('descricao', '');
    document.getElementById('preco').value = valor('preco', valor('preco_venda', ''));
    const custoEl = document.getElementById('custo');
    if (custoEl) custoEl.value = valor('custo', '');
    const estoqueEl = document.getElementById('estoque');
    if (estoqueEl) estoqueEl.value = valor('estoque', '');
    const estoqueMinEl = document.getElementById('estoque_minimo');
    if (estoqueMinEl) estoqueMinEl.value = valor('estoque_minimo', '');
    const ativoEl = document.getElementById('ativo');
    if (ativoEl) ativoEl.checked = Number(valor('ativo', 0)) === 1;
    const destaqueEl = document.getElementById('destaque');
    if (destaqueEl) destaqueEl.checked = Number(valor('destaque', 0)) === 1;
    const imgPrev = document.getElementById('imagemPreview');
    const imgField = document.getElementById('imagem_existing');
    const imgPlaceholder = document.getElementById('imagemPlaceholder');
    if (valor('imagem', '')) {
        let imgPath = String(valor('imagem', '')).replace(/src\/public\//g, '');
        imgPrev.src = BASE_URL + imgPath;
        imgPrev.style.display = 'block';
        if (imgPlaceholder) imgPlaceholder.style.display = 'none';
        imgField.value = imgPath;
    } else {
        imgPrev.src = '';
        imgPrev.style.display = 'none';
        if (imgPlaceholder) imgPlaceholder.style.display = 'flex';
        imgField.value = '';
    }
}

function extrairProdutoDaLinha(id) {
    const btn = document.querySelector(`button[onclick="editarProduto(${Number(id)})"]`);
    if (!btn) return null;
    const tr = btn.closest('tr');
    if (!tr) return null;
    const tds = tr.querySelectorAll('td');
    if (!tds || tds.length < 6) return null;

    const nomeEl = tds[1]?.querySelector('.product-name');
    const descEl = tds[1]?.querySelector('.product-desc');
    const catTd = tds[2];
    const precoTxt = (tds[3]?.textContent || '').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
    const estoqueQtyEl = tds[4]?.querySelector('.stock-qty');
    const estoqueMinEl = tds[4]?.querySelector('.stock-min');
    const estoqueTxt = (estoqueQtyEl?.textContent || tds[4]?.textContent || '').replace(/[^\d-]/g, '');
    const minimoTxt = (estoqueMinEl?.textContent || '').replace(/[^\d]/g, '');
    const ativoTxt = (tds[5]?.textContent || '').toLowerCase();
    const imgEl = tds[0]?.querySelector('img');

    return {
        id: Number(id),
        nome: (nomeEl?.textContent || '').trim(),
        descricao: (descEl?.textContent || '').trim(),
        categoria_id: String(catTd?.getAttribute('data-categoria-id') || ''),
        preco: Number(precoTxt || 0),
        custo: '',
        estoque: Number(estoqueTxt || 0),
        estoque_minimo: Number(minimoTxt || 5),
        ativo: ativoTxt.includes('ativo') ? 1 : 0,
        imagem: (() => {
            const src = String(imgEl?.getAttribute('src') || '');
            if (!src || src.includes('ui-avatars.com')) return '';
            return src.replace(BASE_URL, '').replace(/^\/+/, '');
        })()
    };
}

function compressImageFile(file, options = {}) {
    const maxSide = options.maxSide || 1400;
    const quality = options.quality || 0.82;
    const maxBytes = options.maxBytes || (1.5 * 1024 * 1024);

    return new Promise((resolve) => {
        if (!file || !/^image\//i.test(file.type || '')) {
            resolve(file);
            return;
        }
        // Já pequeno o suficiente
        if (file.size <= maxBytes && file.size <= 1800 * 1024) {
            resolve(file);
            return;
        }

        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            try {
                let w = img.naturalWidth || img.width;
                let h = img.naturalHeight || img.height;
                if (!w || !h) {
                    URL.revokeObjectURL(url);
                    resolve(file);
                    return;
                }
                const scale = Math.min(1, maxSide / Math.max(w, h));
                w = Math.max(1, Math.round(w * scale));
                h = Math.max(1, Math.round(h * scale));

                const canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#f5f5f7';
                ctx.fillRect(0, 0, w, h);
                ctx.drawImage(img, 0, 0, w, h);
                URL.revokeObjectURL(url);

                const toBlob = (q) => new Promise((res) => {
                    canvas.toBlob((blob) => res(blob), 'image/jpeg', q);
                });

                (async () => {
                    let q = quality;
                    let blob = await toBlob(q);
                    while (blob && blob.size > maxBytes && q > 0.5) {
                        q -= 0.08;
                        blob = await toBlob(q);
                    }
                    if (!blob) {
                        resolve(file);
                        return;
                    }
                    const base = String(file.name || 'produto').replace(/\.[^.]+$/, '');
                    const compressed = new File([blob], base + '.jpg', {
                        type: 'image/jpeg',
                        lastModified: Date.now()
                    });
                    resolve(compressed);
                })().catch(() => resolve(file));
            } catch (e) {
                URL.revokeObjectURL(url);
                resolve(file);
            }
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(file);
        };
        img.src = url;
    });
}

function assignFileToInput(input, file) {
    if (!input || !file) return;
    try {
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
    } catch (e) {
        // Alguns browsers antigos não permitem setar files
    }
}

function previewImagem(input) {
    const file = input?.files?.[0];
    const preview = document.getElementById('imagemPreview');
    const placeholder = document.getElementById('imagemPlaceholder');
    const imgField = document.getElementById('imagem_existing');

    if (!preview) return;

    if (file) {
        // Comprime fotos grandes (telemóvel) para caber no limite do PHP (ex.: 2MB)
        compressImageFile(file).then((ready) => {
            if (ready && ready !== file) {
                assignFileToInput(input, ready);
            }
            const finalFile = input.files?.[0] || ready || file;
            const reader = new FileReader();
            reader.onload = function(ev) {
                preview.src = ev.target.result;
                preview.style.display = 'block';
                if (placeholder) placeholder.style.display = 'none';
            };
            reader.readAsDataURL(finalFile);
        });
        if (imgField) imgField.value = '';
        return;
    }

    preview.src = '';
    preview.style.display = 'none';
    if (placeholder) placeholder.style.display = 'flex';
}

function abrirModal() {
    document.getElementById('tituloModal').textContent = 'Novo Produto';
    document.getElementById('formProduto').reset();
    document.getElementById('produto_id').value = '';
    document.getElementById('ativo').checked = true;
    const destaqueEl = document.getElementById('destaque');
    if (destaqueEl) destaqueEl.checked = false;
    const imgPrev = document.getElementById('imagemPreview');
    const imgField = document.getElementById('imagem_existing');
    const imgPlaceholder = document.getElementById('imagemPlaceholder');
    if (imgPrev) { imgPrev.src = ''; imgPrev.style.display = 'none'; }
    if (imgPlaceholder) imgPlaceholder.style.display = 'flex';
    if (imgField) imgField.value = '';
    if (modalProdutoInstance) modalProdutoInstance.show();
    else document.getElementById('modalProduto').style.display = 'block';
}

function fecharModal() {
    if (modalProdutoInstance) modalProdutoInstance.hide();
    else document.getElementById('modalProduto').style.display = 'none';
    document.getElementById('alertModal').style.display = 'none';
}

function editarProduto(id) {
    console.log('Editar produto ID:', id);
    console.log('BASE_URL:', BASE_URL);
    
    document.getElementById('tituloModal').textContent = 'Carregando...';
    document.getElementById('formProduto').reset();
    document.getElementById('produto_id').value = id;
    document.getElementById('alertModal').style.display = 'none';
    if (modalProdutoInstance) modalProdutoInstance.show();
    else document.getElementById('modalProduto').style.display = 'block';

    const cached = produtosCache.get(String(id));
    const daLinha = extrairProdutoDaLinha(id);
    fetchWithFallback('api/produto_buscar.php?id=' + encodeURIComponent(id))
    .then(response => {
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        if(data.success) {
            const produto = data.data;
            produtosCache.set(String(produto.id), produto);
            preencherModalProduto(produto);
        } else { 
            document.getElementById('tituloModal').textContent = 'Erro'; 
            showAlertModal(data.message || 'Erro ao carregar', 'danger'); 
        }
    })
    .catch(error => { 
        console.error('Fetch error:', error);
        const fallback = produtosCache.get(String(id)) || extrairProdutoDaLinha(id);
        if (fallback) {
            preencherModalProduto(fallback);
            showAlertModal('Modo local ativo: dados carregados sem API.', 'warning');
            return;
        }
        document.getElementById('tituloModal').textContent = 'Erro'; 
        showAlertModal('Erro ao carregar dados: ' + error.message, 'danger'); 
    });
}



function appendCategoriaOption(cat) {
    const select = document.getElementById('categoria_id');
    if (!select || !cat || !cat.id) return;
    const jaExiste = Array.from(select.options).some(opt => opt.value === String(cat.id));
    if (!jaExiste) {
        const option = document.createElement('option');
        option.value = cat.id;
        option.textContent = cat.nome;
        select.appendChild(option);
    }
    select.value = String(cat.id);

    const paiSelect = document.getElementById('categoriaPaiSub');
    if (paiSelect && !cat.parent_id) {
        const existePai = Array.from(paiSelect.options).some(opt => opt.value === String(cat.id));
        if (!existePai) {
            const optPai = document.createElement('option');
            optPai.value = cat.id;
            optPai.textContent = cat.nome;
            paiSelect.appendChild(optPai);
        }
    }
}

function adicionarNovaCategoria() {
    const nomeCategoria = document.getElementById('novaCategoria').value.trim();
    const botao = event?.target || document.querySelector('button[onclick="adicionarNovaCategoria()"]');
    if(!nomeCategoria) { showAlertModal('Digite o nome da categoria', 'warning'); document.getElementById('novaCategoria').focus(); return; }
    if(nomeCategoria.length < 2) { showAlertModal('O nome deve ter pelo menos 2 caracteres', 'warning'); return; }
    if(botao) botao.disabled = true;
    const formData = new FormData();
    formData.append('nome', nomeCategoria);
    formData.append('_csrf', getCsrfToken());
    fetchWithFallback('api/categoria_cadastrar.php', { method: 'POST', body: formData, headers: csrfHeaders() })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            appendCategoriaOption(data.categoria);
            document.getElementById('novaCategoria').value = '';
            showAlertModal('✓ ' + data.message, 'success');
        } else { showAlertModal('❌ ' + data.message, 'danger'); }
    })
    .catch(error => { showAlertModal('Erro ao criar categoria', 'danger'); })
    .finally(() => {
        if(botao) botao.disabled = false;
    });
}

function adicionarNovaSubcategoria() {
    if (typeof TEM_SUBCATEGORIAS !== 'undefined' && !TEM_SUBCATEGORIAS) {
        showAlertModal('Subcategorias disponíveis a partir do plano Profissional.', 'warning');
        return;
    }
    const parentEl = document.getElementById('categoriaPaiSub');
    const nomeEl = document.getElementById('novaSubcategoria');
    const parentId = parentEl ? String(parentEl.value || '').trim() : '';
    const nome = nomeEl ? nomeEl.value.trim() : '';
    const botao = event?.target || document.querySelector('button[onclick="adicionarNovaSubcategoria()"]');
    if (!parentId) { showAlertModal('Selecione a categoria pai', 'warning'); return; }
    if (!nome) { showAlertModal('Digite o nome da subcategoria', 'warning'); if (nomeEl) nomeEl.focus(); return; }
    if (nome.length < 2) { showAlertModal('O nome deve ter pelo menos 2 caracteres', 'warning'); return; }
    if (botao) botao.disabled = true;
    const formData = new FormData();
    formData.append('nome', nome);
    formData.append('parent_id', parentId);
    formData.append('_csrf', getCsrfToken());
    fetchWithFallback('api/categoria_cadastrar.php', { method: 'POST', body: formData, headers: csrfHeaders() })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            appendCategoriaOption(data.categoria);
            if (nomeEl) nomeEl.value = '';
            showAlertModal('✓ ' + data.message, 'success');
        } else {
            showAlertModal('❌ ' + (data.message || 'Erro ao criar subcategoria'), 'danger');
        }
    })
    .catch(() => showAlertModal('Erro ao criar subcategoria', 'danger'))
    .finally(() => {
        if (botao) botao.disabled = false;
    });
}

async function deletarProduto(id) {
    if(!(await nivoraConfirmar({ titulo: 'Excluir este produto?', mensagem: 'Esta ação não pode ser desfeita.', confirmar: 'Excluir', perigo: true }))) return;
    
    const formData = new FormData();
    formData.append('id', id);
    formData.append('_csrf', getCsrfToken());
    
    fetchWithFallback('api/produto_deletar.php', { method: 'POST', body: formData, headers: csrfHeaders() })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            NivoraFeedback.reloadWithMessage(data.message || 'Produto excluído.', 'success');
        } else {
            NivoraFeedback.error(data.message || 'Não foi possível excluir o produto.');
        }
    });
}

function estoqueMeta(produto) {
    const estoque = Math.max(0, Number(produto?.estoque || 0));
    const minimo = Math.max(1, Number(produto?.estoque_minimo || 5));
    const referencia = Math.max(minimo * 2, minimo);

    if (estoque <= 0) {
        return { estoque, minimo, status: 'zerado', label: 'Sem estoque', classe: 'danger', percentual: 0 };
    }
    if (estoque <= minimo) {
        return { estoque, minimo, status: 'baixo', label: 'Estoque baixo', classe: 'warning', percentual: Math.min(100, Math.round((estoque / referencia) * 100)) };
    }
    if (estoque <= referencia) {
        return { estoque, minimo, status: 'atencao', label: 'Atenção', classe: 'warning', percentual: Math.min(100, Math.round((estoque / referencia) * 100)) };
    }
    return { estoque, minimo, status: 'ok', label: 'Saudável', classe: 'success', percentual: 100 };
}

function renderEstoqueCell(produto) {
    if (typeof ESTOQUE_AVANCADO !== 'undefined' && ESTOQUE_AVANCADO) {
        if (typeof ESTOQUE_EMP_COCKPIT !== 'undefined' && ESTOQUE_EMP_COCKPIT) {
            return `<div class="stock-cell">
            <span class="badge-custom badge-warning">Via insumos</span>
            <a href="estoque_inteligente.php" class="stock-link-advanced"><i class="fas fa-boxes-stacked me-1"></i>Estoque Inteligente</a>
        </div>`;
        }
        return `<div class="stock-cell">
            <span class="badge-custom badge-warning">Via insumos</span>
            <a href="estoque_inteligente.php?tab=fichas" class="stock-link-advanced"><i class="fas fa-clipboard-list me-1"></i>Ficha técnica</a>
        </div>`;
    }

    const meta = estoqueMeta(produto);
    // Versão simplificada para planos sem estoque avançado (BASICO): mostrar apenas quantidade e badge
    return `<div class="stock-cell simple-stock-cell" data-estoque-status="${meta.status}">
        <div class="d-flex align-items-center justify-content-between">
            <strong class="stock-qty">${meta.estoque}</strong>
            <span class="badge-custom badge-${meta.classe}">${meta.label}</span>
        </div>
        <small class="text-muted">Mín. ${meta.minimo}</small>
    </div>`;
}

function renderEstoqueActions(produtoId) {
    if (typeof ESTOQUE_AVANCADO !== 'undefined' && ESTOQUE_AVANCADO) {
        if (typeof ESTOQUE_EMP_COCKPIT !== 'undefined' && ESTOQUE_EMP_COCKPIT) {
            return `<a class="btn btn-warning btn-action btn-sm me-1" href="estoque_inteligente.php" title="Estoque Inteligente">
            <i class="fas fa-boxes-stacked"></i>
        </a>`;
        }
        return `<a class="btn btn-warning btn-action btn-sm me-1" href="estoque_inteligente.php?tab=fichas" title="Ficha técnica">
            <i class="fas fa-clipboard-list"></i>
        </a>`;
    }
    return `<button class="btn btn-warning btn-action btn-sm me-1" onclick="abrirModalEstoque(${produtoId})" title="Ajustar estoque">
        <i class="fas fa-boxes-stacked"></i>
    </button>`;
}

function atualizarPreviewEstoqueModal() {
    if (!estoqueProdutoAtual) return;

    const quantidadeInput = document.getElementById('estoqueQuantidadeInput');
    const preview = document.getElementById('estoqueNovoPreview');
    const label = document.getElementById('estoqueQuantidadeLabel');
    if (!quantidadeInput || !preview) return;

    const atual = Number(estoqueProdutoAtual.estoque || 0);
    const quantidade = Math.max(0, Number(quantidadeInput.value || 0));
    let novo = atual;

    if (estoqueOperacaoAtual === 'ENTRADA') {
        novo = atual + quantidade;
        if (label) label.textContent = 'Quantidade da entrada';
    } else if (estoqueOperacaoAtual === 'SAIDA') {
        if (quantidade > atual) {
            NivoraFeedback.warning('A saída não pode ser maior que o saldo atual (' + atual + ').');
            return;
        }
        novo = Math.max(0, atual - quantidade);
        if (label) label.textContent = 'Quantidade da saída';
    } else {
        novo = quantidade;
        if (label) label.textContent = 'Novo saldo final';
    }

    preview.textContent = String(novo);
}

function abrirModalEstoque(id) {
    if (typeof ESTOQUE_AVANCADO !== 'undefined' && ESTOQUE_AVANCADO) {
        window.location.href = (typeof ESTOQUE_EMP_COCKPIT !== 'undefined' && ESTOQUE_EMP_COCKPIT)
            ? 'estoque_inteligente.php'
            : 'estoque_inteligente.php?tab=fichas';
        return;
    }

    const produto = produtosCache.get(String(id));
    if (!produto) {
        NivoraFeedback.warning('Produto não encontrado para ajuste de estoque.');
        return;
    }

    estoqueProdutoAtual = produto;
    estoqueOperacaoAtual = 'ENTRADA';

    const modalEl = document.getElementById('modalEstoque');
    if (!modalEl) {
        atualizarEstoque(id);
        return;
    }

    document.getElementById('estoqueProdutoId').value = String(id);
    document.getElementById('estoqueProdutoNome').textContent = produto.nome || 'Produto';
    document.getElementById('estoqueAtualValor').textContent = String(Number(produto.estoque || 0));
    document.getElementById('estoqueMinimoValor').textContent = String(Number(produto.estoque_minimo || 5));
    document.getElementById('estoqueQuantidadeInput').value = '1';

    document.querySelectorAll('.stock-op-btn').forEach((btn) => {
        btn.classList.toggle('active', btn.getAttribute('data-tipo') === 'ENTRADA');
    });

    atualizarPreviewEstoqueModal();

    if (!estoqueModalInstance) {
        estoqueModalInstance = new bootstrap.Modal(modalEl);
    }
    estoqueModalInstance.show();
}

function confirmarAjusteEstoque() {
    if (!estoqueProdutoAtual) return;

    const id = Number(document.getElementById('estoqueProdutoId')?.value || 0);
    const quantidadeInput = Number(document.getElementById('estoqueQuantidadeInput')?.value || 0);
    const atual = Number(estoqueProdutoAtual.estoque || 0);
    let quantidade = Math.max(0, quantidadeInput);
    let tipo = estoqueOperacaoAtual;

    if (tipo === 'DEFINIR') {
        const delta = quantidade - atual;
        if (delta === 0) {
            if (estoqueModalInstance) estoqueModalInstance.hide();
            return;
        }
        tipo = delta > 0 ? 'ENTRADA' : 'SAIDA';
        quantidade = Math.abs(delta);
    }

    if (quantidade <= 0) {
        NivoraFeedback.warning('Informe uma quantidade válida.');
        return;
    }

    if (tipo === 'SAIDA' && quantidade > atual) {
        NivoraFeedback.warning('A saída não pode ser maior que o saldo atual (' + atual + ').');
        return;
    }

    const formData = new FormData();
    formData.append('id', String(id));
    formData.append('quantidade', tipo === 'SAIDA' ? String(-quantidade) : String(quantidade));
    formData.append('_csrf', getCsrfToken());

    const btn = document.getElementById('btnConfirmarEstoque');
    if (btn) btn.disabled = true;

    fetchWithFallback('api/produto_estoque.php', { method: 'POST', body: formData, headers: csrfHeaders() })
        .then((response) => response.json())
        .then((data) => {
            if (data.success) {
                if (estoqueModalInstance) estoqueModalInstance.hide();
                NivoraFeedback.reloadWithMessage(data.message || 'Stock atualizado.', 'success');
            } else {
                NivoraFeedback.error(data.message || 'Não foi possível atualizar o estoque.');
            }
        })
        .catch(() => NivoraFeedback.error('Erro ao atualizar estoque.'))
        .finally(() => {
            if (btn) btn.disabled = false;
        });
}

function atualizarEstoque(id) {
    abrirModalEstoque(id);
}

function getCsrfToken() {
    return (typeof CSRF_TOKEN !== 'undefined' && CSRF_TOKEN) ? CSRF_TOKEN : '';
}

function csrfHeaders() {
    const token = getCsrfToken();
    return token ? { 'X-CSRF-Token': token } : {};
}

function showAlertModal(message, type) {
    const alertDiv = document.getElementById('alertModal');
    alertDiv.textContent = message;
    alertDiv.className = 'alert alert-' + type;
    alertDiv.style.display = 'block';
    setTimeout(() => alertDiv.style.display = 'none', 5000);
}

function filtrarProdutos() {
    const busca = document.getElementById('buscar')?.value.toLowerCase().trim() || '';
    const categoriaFiltro = document.getElementById('filtroCategoria')?.value.trim() || '';
    const estoqueFiltro = document.getElementById('filtroEstoque')?.value || '';
    if (document.body.classList.contains('plano-empresarial')) {
        filtrarProdutosEmpLocal(categoriaFiltro, busca);
        return;
    }
    carregarProdutos(categoriaFiltro, busca, estoqueFiltro);
}

function filtrarProdutosEmpLocal(categoriaId = '', busca = '') {
    const tabela = document.getElementById('tabelaProdutos');
    if (!tabela) return;
    const cat = String(categoriaId || '').trim();
    const q = String(busca || '').toLowerCase().trim();
    let visiveis = 0;
    tabela.querySelectorAll('tr').forEach((tr) => {
        if (!tr.querySelector('.emp-prod-cell') && !tr.querySelector('.emp-prod-copy')) {
            // linha vazia / empty state
            return;
        }
        const rowCat = String(tr.getAttribute('data-categoria-id') || '');
        const nome = (tr.querySelector('.emp-prod-copy strong')?.textContent || '').toLowerCase();
        const desc = (tr.querySelector('.emp-prod-copy span')?.textContent || '').toLowerCase();
        const okCat = !cat || rowCat === cat;
        const okBusca = !q || nome.includes(q) || desc.includes(q);
        const sitFiltro = window.__empSituacao || '';
        const okSit = !sitFiltro || (' ' + (tr.getAttribute('data-situacao') || '') + ' ').includes(' ' + sitFiltro + ' ');
        const show = okCat && okBusca && okSit;
        tr.style.display = show ? '' : 'none';
        if (show) visiveis++;
    });
    const foot = document.querySelector('.emp-prod-foot span');
    if (foot) {
        foot.innerHTML = `Mostrando <strong>${visiveis}</strong> produto${visiveis === 1 ? '' : 's'}`;
    }
}

// Função para carregar produtos via AJAX
function carregarProdutos(categoriaId = '', busca = '', estoqueFiltro = '') {
    if (document.body.classList.contains('plano-empresarial')) {
        filtrarProdutosEmpLocal(categoriaId, busca);
        return;
    }
    const tabela = document.getElementById('tabelaProdutos');
    const originalHtml = tabela.innerHTML;
    if (!originalHtml.trim()) {
        tabela.innerHTML = '<tr><td colspan="7" class="text-center py-5"><span class="spinner-border text-primary"></span> Carregando...</td></tr>';
    }
    const params = new URLSearchParams();
    if (categoriaId) params.append('categoria_id', categoriaId);
    if (busca) params.append('busca', busca);
    params.append('restaurante_id', RESTAURANTE_ID);
    const endpoint = 'api/produto_listar.php?' + params.toString();
    console.log('🔍 carregarProdutos endpoint:', endpoint);
    console.log('📊 RESTAURANTE_ID:', RESTAURANTE_ID);
    console.log('🌐 BASE_URL:', BASE_URL);
    
    fetchWithFallback(endpoint)
        .then(res => {
            console.log('📡 Fetch status:', res.status, res.statusText);
            console.log('📡 Response headers:', [...res.headers.entries()]);
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }
            return res.text().then(text => {
                console.log('📄 Raw response (primeiros 500 chars):', text.substring(0, 500));
                try {
                    return JSON.parse(text);
                } catch (jsonErr) {
                    console.error('JSON Parse error:', jsonErr);
                    throw new Error('Resposta inválida JSON: ' + jsonErr.message);
                }
            });
        })
        .then(data => {
            console.log('✅ API data:', data);
            if (!data || typeof data.success === 'undefined' || !data.success) {
                console.error('❌ API success=false ou indefinido:', data);
                tabela.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-5">
                    <i class="fas fa-exclamation-triangle fa-2x mb-3 d-block"></i>
                    <strong>Erro na API:</strong><br>
                    <small>${data?.message || 'Resposta inválida (success=false)'}</small>
                </td></tr>`;
                return;
            }
            const produtos = (data.data || []).filter((p) => {
                if (!estoqueFiltro || (typeof ESTOQUE_AVANCADO !== 'undefined' && ESTOQUE_AVANCADO)) {
                    return true;
                }
                return estoqueMeta(p).status === estoqueFiltro;
            });
            console.log('📦 Produtos encontrados:', produtos.length);
            produtos.forEach((p) => {
                if (p && typeof p.id !== 'undefined') {
                    produtosCache.set(String(p.id), p);
                }
            });
            if (!produtos.length) {
                tabela.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-inbox fa-3x mb-3 d-block"></i><strong>Nenhum produto encontrado</strong><br><small>para os filtros atuais</small></td></tr>';
                return;
            }
            tabela.innerHTML = produtos.map(p => `
                <tr>
                    <td>
                        <img src="${p.imagem ? (BASE_URL + p.imagem.replace(/src\/public\//g, '')) : produtoImgIniciais(p.nome)}"
                             class="product-img"
                             style="width:80px;height:80px;object-fit:cover;border-radius:8px;"
                             onerror="this.onerror=null;this.src=produtoImgIniciais(this.alt)" />
                    </td>
                    <td>
                        <div class="product-info">
                            <span class="product-name">${p.nome || '—'}</span>
                            ${p.descricao ? `<span class="product-desc">${p.descricao}</span>` : ''}
                        </div>
                    </td>
                    <td style="cursor:pointer;color:#FF6B35;text-decoration:underline;"
                        data-categoria-id="${p.categoria_id || ''}">${p.categoria_nome || '—'}</td>
                    <td><strong>${Number(p.preco || 0).toLocaleString('pt-MZ', {minimumFractionDigits:2, maximumFractionDigits:2})} MZN</strong></td>
                    <td>${renderEstoqueCell(p)}</td>
                    <td>${p.ativo == 1 ? '<span class="badge-custom badge-success">Ativo</span>' : '<span class="badge-custom badge-danger">Inativo</span>'}</td>
                    <td class="text-center">
                        <button class="btn btn-info btn-action btn-sm me-1" onclick="editarProduto(${p.id})" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        ${renderEstoqueActions(p.id)}
                        <button class="btn btn-danger btn-action btn-sm" onclick="deletarProduto(${p.id})" title="Excluir">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        })
        .catch((err) => {
            console.error('💥 Fetch ERROR completo:', err);
            console.error('💥 Stack trace:', err.stack);
            const msg = String(err?.message || '');
            const isNetwork = /XHR network error|Failed to fetch|ERR_CONNECTION_CLOSED|NetworkError/i.test(msg);
            if (isNetwork && originalHtml.trim()) {
                tabela.innerHTML = originalHtml;
                console.warn('Conexao bloqueada no navegador/antivirus. Mantendo lista local ja renderizada.');
                return;
            }
            tabela.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-5">
                <i class="fas fa-network-wired fa-2x mb-3 d-block text-danger"></i>
                <strong>Erro de conexão:</strong><br>
                <code>${err.message}</code><br>
                <small>Abrir F12 → Console para detalhes técnicos</small>
            </td></tr>`;
        });
}

// Chamar AJAX ao carregar página
window.addEventListener('DOMContentLoaded', function() {
    const isEmp = document.body.classList.contains('plano-empresarial');
    if (!isEmp) {
        carregarProdutos();
    }

    // Ajustar botões de categoria para AJAX / filtro EMP
    const botoes = document.querySelectorAll('.categoria-btn, .emp-cat-chip');
    const filtro = document.getElementById('filtroCategoria');
    if (botoes && filtro) {
        botoes.forEach(btn => {
            btn.addEventListener('click', function() {
                botoes.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const catId = btn.getAttribute('data-categoria-id') || '';
                filtro.value = catId;
                const busca = document.getElementById('buscar')?.value.toLowerCase().trim() || '';
                if (isEmp) {
                    filtrarProdutosEmpLocal(catId, busca);
                } else {
                    carregarProdutos(catId, busca, document.getElementById('filtroEstoque')?.value || '');
                }
            });
        });
    }

    // Clicar na categoria (td legado ou tag EMP)
    document.addEventListener('click', function(e) {
        const tag = e.target.closest('td[data-categoria-id], .emp-cat-tag[data-categoria-id]');
        if (!tag) return;
        const categoriaId = String(tag.getAttribute('data-categoria-id') || '');
        if (filtro) filtro.value = categoriaId;
        botoes.forEach(b => {
            if (String(b.getAttribute('data-categoria-id') || '') === categoriaId) b.classList.add('active');
            else b.classList.remove('active');
        });
        const busca = document.getElementById('buscar')?.value.toLowerCase().trim() || '';
        if (isEmp) {
            filtrarProdutosEmpLocal(categoriaId, busca);
        } else {
            carregarProdutos(categoriaId, busca);
        }
    });
});

// Ajustar select de categoria para AJAX
window.addEventListener('DOMContentLoaded', function() {
    const filtro = document.getElementById('filtroCategoria');
    const isEmp = document.body.classList.contains('plano-empresarial');
    if (filtro) {
        filtro.addEventListener('change', function() {
            const busca = document.getElementById('buscar')?.value.toLowerCase().trim() || '';
            if (isEmp) {
                filtrarProdutosEmpLocal(filtro.value, busca);
                document.querySelectorAll('.categoria-btn, .emp-cat-chip').forEach(b => {
                    b.classList.toggle('active', String(b.getAttribute('data-categoria-id') || '') === String(filtro.value || ''));
                });
            } else {
                carregarProdutos(filtro.value, busca);
            }
        });
    }
});

// Ajustar busca para AJAX
window.addEventListener('DOMContentLoaded', function() {
    const inputBuscar = document.getElementById('buscar');
    const isEmp = document.body.classList.contains('plano-empresarial');
    if (inputBuscar) {
        inputBuscar.addEventListener('keyup', function() {
            const cat = document.getElementById('filtroCategoria')?.value || '';
            if (isEmp) {
                filtrarProdutosEmpLocal(cat, inputBuscar.value.toLowerCase().trim());
            } else {
                carregarProdutos(cat, inputBuscar.value.toLowerCase().trim());
            }
        });
    }

    const nome = document.getElementById('nome');
    const preco = document.getElementById('preco');
    const previewNome = document.getElementById('prodPreviewNome');
    const previewPreco = document.getElementById('prodPreviewPreco');
    const atualizarPreviewProduto = function () {
        if (previewNome) previewNome.textContent = (nome && nome.value.trim()) || 'Nome no cardápio';
        if (previewPreco) {
            const v = preco && String(preco.value || '').trim();
            previewPreco.textContent = v ? (v + ' MZN') : 'Preço de venda';
        }
    };
    nome?.addEventListener('input', atualizarPreviewProduto);
    preco?.addEventListener('input', atualizarPreviewProduto);
});


// Imagem com as iniciais do produto, gerada no navegador (sem sites externos).
function produtoImgIniciais(nome) {
    const partes = String(nome || '').trim().split(/\s+/).filter(Boolean);
    let ini = partes.slice(0, 2).map((x) => x.charAt(0).toUpperCase()).join('') || 'P';
    const cores = ['#ea580c', '#0f766e', '#2563eb', '#7c3aed', '#be123c', '#a16207', '#0e7490', '#4d7c0f'];
    let h = 0;
    for (let i = 0; i < String(nome || '').length; i++) h = (h * 31 + String(nome).charCodeAt(i)) >>> 0;
    ini = ini.replace(/[<>&"']/g, '');
    const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128"><rect width="128" height="128" fill="' + cores[h % cores.length] + '"/><text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="50" font-weight="700" fill="#ffffff">' + ini + '</text></svg>';
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

// ---- Empresarial: filtros por situação e ações em vários produtos ----
window.addEventListener('DOMContentLoaded', function () {
    if (!document.body.classList.contains('plano-empresarial')) return;
    const tabela = document.getElementById('tabelaProdutos');
    if (!tabela) return;
    const linhas = () => Array.from(tabela.querySelectorAll('tr[data-produto-id]'));

    // Contagens
    document.querySelectorAll('[data-sit-count]').forEach((em) => {
        const k = em.getAttribute('data-sit-count');
        const n = k ? linhas().filter((tr) => (' ' + (tr.getAttribute('data-situacao') || '') + ' ').includes(' ' + k + ' ')).length : linhas().length;
        em.textContent = String(n);
    });

    const reaplicar = () => filtrarProdutosEmpLocal(
        document.getElementById('filtroCategoria')?.value || '',
        (document.getElementById('buscar')?.value || '').toLowerCase().trim()
    );
    document.querySelectorAll('.emp-sit-chip').forEach((b) => {
        b.addEventListener('click', () => {
            document.querySelectorAll('.emp-sit-chip').forEach((x) => x.classList.toggle('active', x === b));
            window.__empSituacao = b.getAttribute('data-sit') || '';
            reaplicar();
        });
    });

    // Seleção
    const bar = document.getElementById('empLoteBar');
    const nEl = document.getElementById('empLoteN');
    const todos = document.getElementById('empSelTodos');
    const selecionados = () => linhas().filter((tr) => tr.querySelector('.emp-sel')?.checked).map((tr) => tr.getAttribute('data-produto-id'));
    const atualizarBarra = () => {
        const ids = selecionados();
        linhas().forEach((tr) => tr.classList.toggle('is-sel', !!tr.querySelector('.emp-sel')?.checked));
        if (bar) bar.hidden = ids.length === 0;
        if (nEl) nEl.textContent = String(ids.length);
        if (todos) {
            const vis = linhas().filter((tr) => tr.style.display !== 'none');
            todos.checked = vis.length > 0 && vis.every((tr) => tr.querySelector('.emp-sel')?.checked);
        }
    };
    tabela.addEventListener('change', (e) => { if (e.target.classList.contains('emp-sel')) atualizarBarra(); });
    tabela.addEventListener('click', (e) => { if (e.target.closest('.emp-sel-col')) e.stopPropagation(); });
    if (todos) {
        todos.addEventListener('change', () => {
            linhas().forEach((tr) => {
                if (tr.style.display === 'none') return;
                const cb = tr.querySelector('.emp-sel');
                if (cb) cb.checked = todos.checked;
            });
            atualizarBarra();
        });
    }

    const enviar = async (acao, extra) => {
        const ids = selecionados();
        if (!ids.length) return;
        const fd = new FormData();
        fd.append('acao', acao);
        fd.append('ids', ids.join(','));
        Object.keys(extra || {}).forEach((k) => fd.append(k, extra[k]));
        try {
            const r = await fetchWithFallback('api/produtos_lote.php', { method: 'POST', body: fd, headers: csrfHeaders() });
            const d = await r.json();
            if (d && d.success) {
                if (window.NivoraFeedback) NivoraFeedback.show(d.message, 'success');
                setTimeout(() => location.reload(), 600);
            } else if (window.NivoraFeedback) {
                NivoraFeedback.show((d && d.message) || 'Não foi possível atualizar.', 'danger');
            }
        } catch (err) {
            if (window.NivoraFeedback) NivoraFeedback.show('Sem ligação ao servidor.', 'danger');
        }
    };
    if (bar) {
        bar.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-lote]');
            if (!b) return;
            const acao = b.getAttribute('data-lote');
            if (acao === 'limpar') {
                linhas().forEach((tr) => { const cb = tr.querySelector('.emp-sel'); if (cb) cb.checked = false; });
                atualizarBarra();
                return;
            }
            const n = selecionados().length;
            const ok = typeof nivoraConfirmar === 'function'
                ? await nivoraConfirmar({ mensagem: (acao === 'ativar' ? 'Ativar ' : 'Desativar ') + n + ' produto(s)?' + (acao === 'desativar' ? ' Deixam de aparecer no cardápio, no PDV e no Novo Pedido.' : ''), confirmar: acao === 'ativar' ? 'Ativar' : 'Desativar' })
                : true;
            if (ok) enviar(acao);
        });
        const sel = document.getElementById('empLoteCategoria');
        if (sel) {
            sel.addEventListener('change', async () => {
                if (sel.value === '') return;
                const nome = sel.options[sel.selectedIndex].text;
                const n = selecionados().length;
                const ok = typeof nivoraConfirmar === 'function'
                    ? await nivoraConfirmar({ mensagem: 'Mudar ' + n + ' produto(s) para "' + nome + '"?', confirmar: 'Mudar categoria' })
                    : true;
                if (ok) enviar('categoria', { categoria_id: sel.value });
                else sel.value = '';
            });
        }
    }
});
