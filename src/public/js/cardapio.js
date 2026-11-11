(function () {
    'use strict';

    var cfg = window.CARDAPIO_CONFIG || {};
    var cartStorageKey = 'cardapio_cart_' + (cfg.rid || 0) + '_' + (cfg.mesaId || 0);
    var cart = [];
    var restaurantId = cfg.rid || 0;
    var tableId = cfg.mesaId || 0;
    var takeawayMode = !!cfg.takeawayMode;
    var baseUrl = cfg.baseUrl || './';
    var qrOrderToken = cfg.qrOrderToken || '';
    var qrOrderEnabled = !!cfg.qrOrderEnabled;
    var viewerProduct = null;
    var viewerQty = 1;
    var viewerReturnFocus = null;
    var cartReturnFocus = null;
    var pendingIdemKey = '';
    var lastPedidoId = 0;
    var trackTimer = null;
    var editingNoteIndex = -1;

    function trackMenuEvent(eventName, productId) {
        if (!cfg.rid || !cfg.qrOrderToken) return;
        var body = JSON.stringify({ evento: eventName, rid: cfg.rid, mesa_id: cfg.mesaId || 0, produto_id: productId || 0, token: cfg.qrOrderToken });
        try {
            fetch('api/cardapio_evento.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true }).catch(function () {});
        } catch (e) {}
    }

    // Brand CSS variables
    try {
        var root = document.documentElement;
        if (cfg.brandPrimary) root.style.setProperty('--primary', cfg.brandPrimary);
        if (cfg.brandAccent) root.style.setProperty('--accent', cfg.brandAccent);
        if (cfg.brandPrimary) {
            root.style.setProperty('--primary-soft', 'color-mix(in srgb, ' + cfg.brandPrimary + ' 18%, transparent)');
        }
    } catch (e) {}

    try {
        var savedCart = localStorage.getItem(cartStorageKey);
        if (savedCart) cart = JSON.parse(savedCart) || [];
    } catch (e) {
        cart = [];
    }

    try {
        pendingIdemKey = String(localStorage.getItem(cartStorageKey + '_idem') || '');
    } catch (e) {
        pendingIdemKey = '';
    }

    function gerarIdemKey() {
        var rand = Math.random().toString(36).slice(2, 10);
        return 'cardapio_' + Date.now().toString(36) + '_' + rand;
    }

    function getIdemKey() {
        if (!pendingIdemKey || pendingIdemKey.length < 8) {
            pendingIdemKey = gerarIdemKey();
            try { localStorage.setItem(cartStorageKey + '_idem', pendingIdemKey); } catch (e) {}
        }
        return pendingIdemKey;
    }

    function clearIdemKey() {
        pendingIdemKey = '';
        try { localStorage.removeItem(cartStorageKey + '_idem'); } catch (e) {}
    }

    function persistCart() {
        try { localStorage.setItem(cartStorageKey, JSON.stringify(cart)); } catch (e) {}
    }

    function formatPrice(value) {
        return parseFloat(value).toFixed(2).replace('.', ',') + ' MZN';
    }

    function escHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function addToCart(id, name, price, image, qty, note) {
        var card = document.querySelector('.product-card[data-id="' + id + '"]');
        if (card && card.getAttribute('data-esgotado') === '1') {
            NivoraFeedback.warning('Este item está esgotado.');
            return;
        }
        qty = Math.max(1, parseInt(qty, 10) || 1);
        note = String(note || '').trim();
        var existing = cart.find(function (item) {
            return item.id === id && String(item.note || '') === note;
        });
        if (existing) {
            existing.qty += qty;
        } else {
            cart.push({
                id: id,
                name: name,
                price: parseFloat(price),
                qty: qty,
                image: image || '',
                note: note
            });
        }
        updateCartUI();
        animateAddButton(id);
        trackMenuEvent('produto_adicionado', id);
    }

    function addPromotionToCart(id, name, price, image) {
        var cartId = 'promo_' + parseInt(id, 10);
        var existing = cart.find(function (item) { return item.id === cartId; });
        if (existing) {
            existing.qty += 1;
        } else {
            cart.push({ id: cartId, promotionId: parseInt(id, 10), name: name, price: parseFloat(price), qty: 1, image: image || '', note: '' });
        }
        updateCartUI();
        animateAddButton(cartId);
    }

    function animateAddButton(id) {
        document.querySelectorAll('.product-card[data-id="' + id + '"] .product-add').forEach(function (btn) {
            btn.classList.add('added');
            setTimeout(function () { btn.classList.remove('added'); }, 500);
        });
    }

    function updateCartUI() {
        persistCart();
        var total = cart.reduce(function (sum, item) { return sum + (item.price * item.qty); }, 0);
        var count = cart.reduce(function (sum, item) { return sum + item.qty; }, 0);

        var cartFloat = document.getElementById('cartFloat');
        if (cartFloat) cartFloat.classList.toggle('visible', count > 0);
        if (cartFloat) {
            cartFloat.setAttribute('aria-label', count > 0
                ? 'Abrir pedido com ' + count + (count === 1 ? ' item' : ' itens') + ', total ' + formatPrice(total)
                : 'Abrir pedido vazio');
        }
        var sidebarCart = document.getElementById('sidebarCartBtn');
        if (sidebarCart) sidebarCart.classList.toggle('visible', count > 0);

        var setTxt = function (id, val) {
            var el = document.getElementById(id);
            if (el) el.textContent = val;
        };
        setTxt('cartTotalDisplay', formatPrice(total));
        setTxt('cartCountDisplay', count);
        setTxt('cartItemsLabel', count === 1 ? 'item' : 'itens');
        setTxt('cartTotalValue', formatPrice(total));
        setTxt('sidebarCartTotal', formatPrice(total));
        setTxt('enterpriseCartCount', count);
    }

    function renderCartItems() {
        var container = document.getElementById('cartItems');
        if (!container) return;
        if (cart.length === 0) {
            container.innerHTML = '<div class="empty-cart"><div class="icon"><i class="fas fa-shopping-bag"></i></div><p>Seu pedido está vazio</p></div>';
            return;
        }

        var html = '';
        cart.forEach(function (item, index) {
            var subtotal = item.price * item.qty;
            var imgHtml = item.image
                ? '<img src="' + escHtml(baseUrl + item.image) + '" alt="' + escHtml(item.name) + '" loading="lazy">'
                : '<span style="font-size:24px;"><i class="fas fa-utensils"></i></span>';

            html += '<div class="cart-item">';
            html += '<div class="cart-item-img">' + imgHtml + '</div>';
            html += '<div class="cart-item-info">';
            html += '<div class="cart-item-name">' + escHtml(item.name) + '</div>';
            html += '<div class="cart-item-price">' + formatPrice(item.price) + '</div>';
            if (item.note) {
                html += '<div class="cart-item-note"><i class="fas fa-note-sticky"></i> ' + escHtml(item.note) + '</div>';
            } else {
                html += '<button type="button" class="cart-note-btn" onclick="editItemNote(' + index + ')"><i class="fas fa-pen"></i> Obs.</button>';
            }
            if (item.note) {
                html += ' <button type="button" class="cart-note-btn" onclick="editItemNote(' + index + ')">Editar</button>';
            }
            html += '</div>';
            html += '<div class="cart-item-qty">';
            html += '<button class="qty-btn" onclick="changeQty(' + index + ', -1)">-</button>';
            html += '<span class="qty-value">' + item.qty + '</span>';
            html += '<button class="qty-btn" onclick="changeQty(' + index + ', 1)">+</button>';
            html += '</div>';
            html += '<div class="cart-item-subtotal">' + formatPrice(subtotal) + '</div>';
            html += '</div>';
        });
        container.innerHTML = html;
    }

    window.editItemNote = async function (index) {
        editingNoteIndex = index;
        var item = cart[index];
        if (!item) return;
        var note = await nivoraPerguntar({
            titulo: 'Observação do item',
            mensagem: item.name ? String(item.name) : '',
            campo: { rotulo: 'Ex.: sem cebola, bem passado', valor: item.note || '', max: 120 },
            confirmar: 'Guardar',
        });
        if (note === null) return;
        item.note = String(note).trim().slice(0, 120);
        updateCartUI();
        renderCartItems();
    };

    window.changeQty = function (index, delta) {
        cart[index].qty += delta;
        if (cart[index].qty <= 0) cart.splice(index, 1);
        updateCartUI();
        renderCartItems();
    };

    window.openCart = function () {
        cartReturnFocus = document.activeElement;
        renderCartItems();
        document.getElementById('modalOverlay').classList.add('open');
        document.getElementById('cartModal').classList.add('open');
        var cartFloat = document.getElementById('cartFloat');
        if (cartFloat) cartFloat.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        var cartClose = document.querySelector('#cartModal .modal-close');
        if (cartClose) cartClose.focus();
    };

    window.closeCart = function () {
        document.getElementById('modalOverlay').classList.remove('open');
        document.getElementById('cartModal').classList.remove('open');
        var cartFloat = document.getElementById('cartFloat');
        if (cartFloat) cartFloat.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        if (cartReturnFocus && typeof cartReturnFocus.focus === 'function') cartReturnFocus.focus();
        cartReturnFocus = null;
    };

    function showTrackWhatsApp(phone, numeroPedido) {
        var wrap = document.getElementById('trackWhatsApp');
        if (!wrap) return;
        var digits = String(phone || '').replace(/\D+/g, '');
        if (digits.length < 8) {
            wrap.hidden = true;
            wrap.innerHTML = '';
            return;
        }
        if (digits.length === 9 && digits.charAt(0) === '8') {
            digits = '258' + digits;
        } else if (digits.length === 12 && digits.indexOf('258') === 0) {
            // ok
        } else if (digits.charAt(0) !== '2') {
            digits = '258' + digits.replace(/^0+/, '');
        }
        var nome = cfg.restauranteNome || 'restaurante';
        var msg = encodeURIComponent('Olá! Pedido #' + (numeroPedido || '') + ' no ' + nome);
        wrap.innerHTML = '<a href="https://wa.me/' + digits + '?text=' + msg + '" target="_blank" rel="noopener"><i class="fab fa-whatsapp"></i> Abrir no WhatsApp</a>';
        wrap.hidden = false;
    }

    window.submitOrder = function () {
        if (cart.length === 0) {
            NivoraFeedback.warning('Adicione itens ao pedido!');
            return;
        }
        if (!qrOrderEnabled || !qrOrderToken) {
            NivoraFeedback.warning('Pedido online indisponível neste momento.');
            return;
        }
        if (!takeawayMode && !tableId) {
            NivoraFeedback.warning('Pedido online disponível pelo QR Code da mesa.');
            return;
        }
        trackMenuEvent('pedido_enviado');

        var clientName = document.getElementById('clientName').value.trim();
        if (takeawayMode && clientName.length < 2) {
            NivoraFeedback.warning('Indique o seu nome para o pedido takeaway.');
            document.getElementById('clientName').focus();
            return;
        }

        var btn = document.getElementById('btnOrder');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';

        var total = cart.reduce(function (sum, item) { return sum + (item.price * item.qty); }, 0);
        var phoneEl = document.getElementById('clientPhone');

        var data = {
            token: qrOrderToken,
            mesa_id: tableId || 0,
            cliente_nome: clientName,
            cliente_telefone: phoneEl ? phoneEl.value.trim() : '',
            observacao: document.getElementById('orderNote').value.trim(),
            total: total,
            idem_key: getIdemKey(),
            itens: cart.map(function (item) {
                return item.promotionId ? {
                    promocao_id: item.promotionId,
                    quantidade: item.qty
                } : {
                    produto_id: item.id,
                    quantidade: item.qty,
                    observacao: item.note || ''
                };
            })
        };

        fetch('api/reserva_publica.php?route=pedido_qr', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        })
            .then(async function (r) {
                var text = await r.text();
                try { return JSON.parse(text); }
                catch (e) {
                    return { success: false, message: 'Resposta inválida do servidor ao enviar pedido.' };
                }
            })
            .then(function (result) {
                if (result.success) {
                    document.getElementById('successNumber').textContent = '#' + result.numero_pedido;
                    document.getElementById('cartScreen').style.display = 'none';
                    document.getElementById('successScreen').classList.add('show');
                    lastPedidoId = parseInt(result.pedido_id, 10) || 0;
                    showTrackWhatsApp(phoneEl ? phoneEl.value.trim() : '', result.numero_pedido);
                    cart = [];
                    clearIdemKey();
                    updateCartUI();
                    startOrderTracking(lastPedidoId);
                } else {
                    NivoraFeedback.error(result.message || 'Erro ao enviar pedido');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Confirmar pedido';
                }
            })
            .catch(function () {
                NivoraFeedback.error('Erro de conexão');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Confirmar pedido';
            });
    };

    function applyTimeline(status) {
        status = String(status || 'PREPARANDO').toUpperCase();
        var order = ['NOVO', 'PREPARANDO', 'PRONTO', 'ENTREGUE', 'PAGO'];
        var idx = order.indexOf(status);
        if (idx < 0) idx = 1;
        if (status === 'PAGO') idx = 3;
        document.querySelectorAll('#trackStatus .track-step').forEach(function (step) {
            var key = step.getAttribute('data-step');
            var stepIdx = order.indexOf(key);
            step.classList.remove('done', 'current');
            if (stepIdx < idx) step.classList.add('done');
            else if (stepIdx === idx || (status === 'PAGO' && key === 'ENTREGUE')) step.classList.add('current', 'done');
        });
        var hint = document.getElementById('trackHint');
        if (hint) {
            if (status === 'PRONTO') hint.textContent = 'O pedido está pronto!';
            else if (status === 'ENTREGUE' || status === 'PAGO') hint.textContent = 'Pedido concluído. Bom apetite!';
            else hint.textContent = 'Actualizamos automaticamente a cada poucos segundos.';
        }
    }

    function startOrderTracking(pedidoId) {
        if (trackTimer) {
            clearInterval(trackTimer);
            trackTimer = null;
        }
        applyTimeline('PREPARANDO');
        var poll = function () {
            var url = 'api/reserva_publica.php?route=acompanhar_pedido&token=' + encodeURIComponent(qrOrderToken);
            if (pedidoId) url += '&pedido_id=' + encodeURIComponent(pedidoId);
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.success && res.pedido) {
                        applyTimeline(res.pedido.status);
                        if (['ENTREGUE', 'PAGO', 'CANCELADO'].indexOf(String(res.pedido.status || '').toUpperCase()) >= 0) {
                            clearInterval(trackTimer);
                            trackTimer = null;
                        }
                    }
                })
                .catch(function () {});
        };
        poll();
        trackTimer = setInterval(poll, 8000);
    }

    window.newOrder = function () {
        if (trackTimer) {
            clearInterval(trackTimer);
            trackTimer = null;
        }
        document.getElementById('cartScreen').style.display = 'block';
        document.getElementById('successScreen').classList.remove('show');
        var wa = document.getElementById('trackWhatsApp');
        if (wa) {
            wa.hidden = true;
            wa.innerHTML = '';
        }
        document.getElementById('clientName').value = '';
        var phoneEl = document.getElementById('clientPhone');
        if (phoneEl) phoneEl.value = '';
        document.getElementById('orderNote').value = '';
        document.getElementById('btnOrder').disabled = !qrOrderEnabled;
        document.getElementById('btnOrder').innerHTML = '<i class="fas fa-check"></i> Confirmar pedido';
        closeCart();
    };

    window.filterCategory = function (category, btn) {
        document.querySelectorAll('.cat-pill, .menu-sidebar-link').forEach(function (p) {
            p.classList.remove('active');
        });
        document.querySelectorAll('.cat-pill, .menu-sidebar-link').forEach(function (item) {
            if (item.getAttribute('data-category-filter') === String(category)) {
                item.classList.add('active');
            }
        });
        if (btn) btn.classList.add('active');

        document.querySelectorAll('.category-section').forEach(function (section) {
            var sectionCat = section.getAttribute('data-category');
            var parentCat = section.getAttribute('data-parent') || '';
            var show = category === 'all'
                || sectionCat === category
                || parentCat === category
                || (category.indexOf('›') === -1 && sectionCat.indexOf(category + ' ›') === 0);
            section.style.display = show ? 'block' : 'none';
            section.querySelectorAll('.product-card').forEach(function (card) {
                card.style.display = '';
            });
        });

        var search = document.getElementById('searchInput');
        if (search) search.value = '';
        var empty = document.getElementById('menuSearchEmpty');
        if (empty) empty.hidden = true;
    };

    window.filterProducts = function () {
        var searchInput = document.getElementById('searchInput');
        var normalize = function (value) {
            return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        };
        var query = normalize(searchInput ? searchInput.value.trim() : '');
        var totalVisible = 0;
        document.querySelectorAll('.cat-pill, .menu-sidebar-link').forEach(function (p) {
            p.classList.remove('active');
        });
        document.querySelectorAll('[data-category-filter="all"]').forEach(function (el) {
            el.classList.add('active');
        });

        document.querySelectorAll('.category-section').forEach(function (section) {
            var visible = 0;
            section.querySelectorAll('.product-card').forEach(function (card) {
                var searchable = normalize((card.dataset.name || '') + ' ' + (card.dataset.desc || '')
                    + ' ' + (card.querySelector('.product-name') || {}).textContent
                    + ' ' + (card.querySelector('.product-desc') || {}).textContent
                    + ' ' + (section.dataset.category || ''));
                var show = !query || searchable.includes(query);
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            section.style.display = visible > 0 ? 'block' : 'none';
            totalVisible += visible;
        });
        var empty = document.getElementById('menuSearchEmpty');
        if (empty) empty.hidden = !query || totalVisible > 0;
    };

    window.openProductDetail = function (id, name, price, image, desc) {
        var card = document.querySelector('.product-card[data-id="' + id + '"]');
        if (card && card.getAttribute('data-esgotado') === '1') return;
        trackMenuEvent('produto_visualizado', id);
        viewerReturnFocus = document.activeElement;

        viewerProduct = { id: id, name: name, price: price, image: image || '', desc: desc || '' };
        viewerQty = 1;

        var imgEl = document.getElementById('viewerImage');
        var photoWrap = document.getElementById('viewerPhotoWrap');
        if (image) {
            imgEl.hidden = false;
            imgEl.src = /^https?:\/\//i.test(image) ? image : (baseUrl + image);
            imgEl.alt = name;
            if (photoWrap) {
                photoWrap.classList.remove('no-photo');
                var oldErrorLabel = photoWrap.querySelector('.photo-error-label');
                if (oldErrorLabel) oldErrorLabel.remove();
            }
        } else {
            imgEl.removeAttribute('src');
            imgEl.alt = 'Sem foto disponível';
            if (photoWrap) photoWrap.classList.add('no-photo');
        }
        document.getElementById('viewerName').textContent = name;
        document.getElementById('viewerPrice').textContent = formatPrice(price);
        document.getElementById('viewerDesc').textContent = desc || '';
        document.getElementById('viewerQty').textContent = '1';
        var noteEl = document.getElementById('viewerNote');
        if (noteEl) noteEl.value = '';
        document.getElementById('imageViewer').classList.add('open');
        document.body.style.overflow = 'hidden';
        var closeButton = document.querySelector('#imageViewer .image-viewer-close');
        if (closeButton) closeButton.focus();
    };

    window.closeImageViewer = function () {
        document.getElementById('imageViewer').classList.remove('open');
        document.body.style.overflow = '';
        viewerProduct = null;
        if (viewerReturnFocus && typeof viewerReturnFocus.focus === 'function') viewerReturnFocus.focus();
        viewerReturnFocus = null;
    };

    window.changeViewerQty = function (delta) {
        viewerQty = Math.max(1, viewerQty + delta);
        document.getElementById('viewerQty').textContent = String(viewerQty);
    };

    window.addFromViewer = function () {
        if (!viewerProduct) return;
        var noteEl = document.getElementById('viewerNote');
        var note = noteEl ? noteEl.value.trim() : '';
        addToCart(viewerProduct.id, viewerProduct.name, viewerProduct.price, viewerProduct.image, viewerQty, note);
        closeImageViewer();
    };

    window.addToCart = addToCart;
    window.addPromotionToCart = addPromotionToCart;

    // Featured carousel — um slide de cada vez (sem misturar imagens)
    (function initFeatured() {
        var track = document.getElementById('featuredTrack');
        var dots = document.getElementById('featuredDots');
        if (!track || !dots) return;
        var slides = track.querySelectorAll('.featured-slide');
        if (!slides.length) return;
        var pauseUntil = 0;
        var pointerOver = false;
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function pauseRotation() { pauseUntil = Date.now() + 20000; }
        track.addEventListener('pointerdown', pauseRotation);
        track.addEventListener('focusin', pauseRotation);
        track.addEventListener('pointerenter', function () { pointerOver = true; });
        track.addEventListener('pointerleave', function () { pointerOver = false; });

        function goTo(index) {
            var i = ((index % slides.length) + slides.length) % slides.length;
            var left = slides[i].offsetLeft;
            track.scrollTo({ left: left, behavior: reducedMotion ? 'auto' : 'smooth' });
            dots.querySelectorAll('span').forEach(function (d, di) {
                d.classList.toggle('active', di === i);
            });
            return i;
        }

        var i = 0;
        dots.querySelectorAll('span').forEach(function (dot, di) {
            dot.addEventListener('click', function () {
                pauseRotation();
                i = goTo(di);
            });
            dot.setAttribute('role', 'button');
            dot.setAttribute('tabindex', '0');
            dot.setAttribute('aria-label', 'Mostrar destaque ' + (di + 1));
            dot.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    dot.click();
                }
            });
        });

        if (slides.length <= 1 || reducedMotion) return;
        setInterval(function () {
            if (document.hidden || pointerOver || Date.now() < pauseUntil || track.contains(document.activeElement)) return;
            i = goTo(i + 1);
        }, 5200);
    })();

    updateCartUI();
    trackMenuEvent('abertura');

    document.addEventListener('error', function (event) {
        var image = event.target;
        if (!image || image.tagName !== 'IMG') return;
        if (!image.closest('.product-image, .featured-photo, .image-viewer-photo')) return;
        image.hidden = true;
        var wrap = image.closest('.product-image, .featured-photo, .image-viewer-photo');
        if (wrap) {
            wrap.classList.add('no-photo');
            if (!wrap.querySelector('.photo-error-label')) {
                var label = document.createElement('span');
                label.className = 'photo-error-label';
                label.textContent = 'Sem foto disponível';
                wrap.appendChild(label);
            }
        }
    }, true);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            var viewer = document.getElementById('imageViewer');
            var cart = document.getElementById('cartModal');
            if (viewer && viewer.classList.contains('open')) { closeImageViewer(); return; }
            if (cart && cart.classList.contains('open')) { closeCart(); return; }
        }
        var card = event.target.closest ? event.target.closest('.product-card[role="button"]') : null;
        if (!card || event.target.closest('button, input, textarea, a')) return;
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            card.click();
        }
    });
})();
