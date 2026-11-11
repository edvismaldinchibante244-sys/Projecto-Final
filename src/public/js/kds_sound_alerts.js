(function (window, document) {
    'use strict';

    function normalizeStatus(value) {
        return String(value || '').trim().toLowerCase();
    }

    function snapshot(root) {
        const items = new Map();
        if (!root) return items;

        root.querySelectorAll('.card-item[data-id]').forEach((card) => {
            const id = String(card.dataset.id || '').trim();
            if (id) items.set(id, normalizeStatus(card.dataset.status));
        });
        return items;
    }

    class KdsSoundController {
        constructor(options) {
            this.enabledByPlan = Boolean(options && options.enabled);
            this.area = String((options && options.area) || 'kds').toLowerCase();
            this.button = document.querySelector((options && options.buttonSelector) || '[data-kds-sound-toggle]');
            this.storageKey = 'nivora_kds_sound_enabled';
            this.userEnabled = this.readPreference();
            this.audioContext = null;
            this.unlocked = false;
            this.state = snapshot(document.querySelector('.kds-refresh-root'));
            this.bind();
            this.renderButton();
        }

        readPreference() {
            try {
                return window.localStorage.getItem(this.storageKey) !== '0';
            } catch (error) {
                return true;
            }
        }

        savePreference() {
            try {
                window.localStorage.setItem(this.storageKey, this.userEnabled ? '1' : '0');
            } catch (error) {}
        }

        bind() {
            if (!this.enabledByPlan || !this.button) return;

            this.button.hidden = false;
            this.button.addEventListener('click', async () => {
                if (this.userEnabled && this.unlocked) {
                    this.userEnabled = false;
                    this.savePreference();
                    this.renderButton();
                    return;
                }

                this.userEnabled = true;
                this.savePreference();
                await this.unlock(true);
                this.renderButton();
            });

            const unlockSilently = (event) => {
                if (event.target && event.target.closest && event.target.closest('[data-kds-sound-toggle]')) return;
                if (this.userEnabled && !this.unlocked) this.unlock(false);
            };
            document.addEventListener('pointerdown', unlockSilently, { once: true, capture: true });
            document.addEventListener('keydown', unlockSilently, { once: true, capture: true });
        }

        async unlock(confirmWithSound) {
            if (!this.enabledByPlan || !this.userEnabled) return false;
            try {
                const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextClass) return false;
                if (!this.audioContext) this.audioContext = new AudioContextClass();
                if (this.audioContext.state === 'suspended') await this.audioContext.resume();
                this.unlocked = this.audioContext.state === 'running';
                if (this.unlocked && confirmWithSound) this.playPattern('enabled');
            } catch (error) {
                this.unlocked = false;
            }
            this.renderButton();
            return this.unlocked;
        }

        renderButton() {
            if (!this.button || !this.enabledByPlan) return;
            const active = this.userEnabled && this.unlocked;
            const muted = !this.userEnabled;
            this.button.classList.toggle('is-active', active);
            this.button.classList.toggle('is-muted', muted);
            this.button.setAttribute('aria-pressed', active ? 'true' : 'false');
            this.button.title = muted
                ? 'Ativar alertas sonoros'
                : (active ? 'Desativar alertas sonoros' : 'Clique para permitir os alertas sonoros');
            this.button.innerHTML = '<i class="fas ' + (muted ? 'fa-volume-xmark' : (active ? 'fa-volume-high' : 'fa-volume-low')) + '" aria-hidden="true"></i><span>' + (muted ? 'Som desligado' : (active ? 'Som ativo' : 'Ativar som')) + '</span>';
        }

        tone(frequency, start, duration, volume) {
            if (!this.audioContext || !this.unlocked) return;
            const oscillator = this.audioContext.createOscillator();
            const gain = this.audioContext.createGain();
            const beginsAt = this.audioContext.currentTime + start;
            const endsAt = beginsAt + duration;
            oscillator.type = 'triangle';
            oscillator.frequency.setValueAtTime(frequency, beginsAt);
            gain.gain.setValueAtTime(0.001, beginsAt);
            gain.gain.exponentialRampToValueAtTime(volume, beginsAt + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, endsAt);
            oscillator.connect(gain);
            gain.connect(this.audioContext.destination);
            oscillator.start(beginsAt);
            oscillator.stop(endsAt + 0.02);
        }

        playPattern(eventName) {
            if (!this.enabledByPlan || !this.userEnabled || !this.unlocked) return;
            const patterns = {
                novo: [[740, 0, .15, .44], [988, .17, .15, .50], [1175, .34, .24, .56]],
                pronto: [[1047, 0, .22, .58], [1319, .24, .30, .64]],
                entregue: [[784, 0, .16, .46], [659, .18, .26, .42]],
                enabled: [[880, 0, .08, .22], [1175, .1, .12, .25]]
            };
            (patterns[eventName] || []).forEach((tone) => this.tone(tone[0], tone[1], tone[2], tone[3]));
        }

        announce(eventName) {
            if (!this.enabledByPlan) return;
            const messages = {
                novo: ['fa-bell', 'Novo pedido recebido'],
                pronto: ['fa-circle-check', 'Pedido pronto para entrega'],
                entregue: ['fa-truck', 'Pedido entregue']
            };
            const message = messages[eventName];
            if (!message) return;

            let region = document.querySelector('.kds-event-toast-region');
            if (!region) {
                region = document.createElement('div');
                region.className = 'kds-event-toast-region';
                region.setAttribute('aria-live', 'polite');
                document.body.appendChild(region);
            }

            const toast = document.createElement('div');
            toast.className = 'kds-event-toast is-' + eventName;
            toast.setAttribute('role', 'status');
            toast.innerHTML = '<i class="fas ' + message[0] + '" aria-hidden="true"></i><span>' + message[1] + '</span>';
            region.appendChild(toast);
            window.setTimeout(() => toast.remove(), 4200);
        }

        notify(eventName) {
            this.announce(eventName);
            this.playPattern(eventName);
        }

        queueEvents(events) {
            if (!events.length) return;
            const order = ['novo', 'pronto', 'entregue'];
            order.filter((name) => events.includes(name)).forEach((name, index) => {
                window.setTimeout(() => this.notify(name), index * 650);
            });
        }

        compareAndNotify(root) {
            const next = snapshot(root);
            const events = [];

            next.forEach((status, id) => {
                if (!this.state.has(id) && status === 'em_preparo') events.push('novo');
                if (this.state.get(id) === 'em_preparo' && status === 'pronto') events.push('pronto');
            });
            this.state.forEach((status, id) => {
                if (status === 'pronto' && !next.has(id)) events.push('entregue');
            });

            this.state = next;
            this.queueEvents(events);
            return events;
        }

        acknowledgeTransition(itemId, nextStatus) {
            const id = String(itemId || '').trim();
            const status = normalizeStatus(nextStatus);
            if (!id) return;

            if (status === 'pronto') {
                this.state.set(id, 'pronto');
                this.notify('pronto');
            } else if (status === 'entregue') {
                this.state.delete(id);
                this.notify('entregue');
            }
        }
    }

    window.KdsSoundAlerts = {
        create(options) {
            return new KdsSoundController(options || {});
        },
        snapshot
    };
})(window, document);
