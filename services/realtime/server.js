'use strict';
/*
 * Servidor de tempo real (Socket.IO): avisa o dashboard de pedidos novos.
 *
 * Seguranca (revisto em 01/11/2026):
 *  - NAO serve ficheiros: antes, express.static('.') expunha todo o projeto na porta 3001
 *    (codigo PHP, configuracoes, credenciais).
 *  - Cada browser so entra na sala do seu restaurante com um codigo assinado pelo PHP
 *    (HMAC com REALTIME_SECRET, ver app/src/config/realtime.php) e com validade.
 *  - Cada pedido so e enviado a sala do restaurante dele (antes ia para todos os browsers).
 *  - /notify exige o mesmo segredo no cabecalho X-Realtime-Secret.
 *
 * Variaveis: REALTIME_SECRET (obrigatoria, >= 32 caracteres), PORT (3001),
 * REALTIME_ALLOWED_ORIGINS (opcional, ex.: http://localhost,https://meusite.com).
 */
const crypto = require('crypto');
const express = require('express');
const http = require('http');
const socketIo = require('socket.io');

// Le REALTIME_* do .env da raiz (o mesmo do PHP) quando nao vem do ambiente.
(function lerEnv() {
  const fs = require('fs'); const path = require('path');
  for (const f of [path.resolve(__dirname, '../../.env'), path.resolve(process.cwd(), '.env')]) {
    if (!fs.existsSync(f)) continue;
    for (const linha of fs.readFileSync(f, 'utf8').split(/\r?\n/)) {
      const m = linha.match(/^\s*(REALTIME_[A-Z_]+)\s*=\s*(.*?)\s*$/);
      if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
    }
    break;
  }
})();

const SECRET = String(process.env.REALTIME_SECRET || '');
if (SECRET.length < 32) {
  console.error('REALTIME_SECRET em falta ou curto (minimo 32 caracteres). Defina-o no .env e no ambiente deste processo.');
  process.exit(1);
}

const origens = String(process.env.REALTIME_ALLOWED_ORIGINS || '')
  .split(',').map(s => s.trim()).filter(Boolean);

const app = express();
app.disable('x-powered-by');
app.use(express.json({ limit: '64kb' }));

const server = http.createServer(app);
const io = socketIo(server, {
  cors: { origin: origens.length ? origens : false, methods: ['GET', 'POST'] }
});

const iguais = (a, b) => {
  const x = Buffer.from(String(a)); const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
};

function codigoValido(dados) {
  const sala = String((dados && dados.room) || '');
  const exp = Number((dados && dados.exp) || 0);
  const sig = String((dados && dados.sig) || '');
  if (!/^restaurante_\d+$/.test(sala) || !Number.isInteger(exp) || exp < Math.floor(Date.now() / 1000)) return null;
  const esperado = crypto.createHmac('sha256', SECRET).update(`${sala}|${exp}`).digest('hex');
  return iguais(esperado, sig) ? sala : null;
}

io.on('connection', socket => {
  socket.on('join-room', dados => {
    const sala = codigoValido(dados);
    if (!sala) {
      socket.emit('join-recusado');
      return;
    }
    socket.join(sala);
  });
});

// Qualquer alteracao na operacao (pedido, item, mesa): os ecras do restaurante recarregam.
// Varias alteracoes seguidas (ex.: 5 itens marcados de uma vez) juntam-se num so aviso (250 ms).
const pendentes = new Map();
function avisarMudanca(sala) {
  if (pendentes.has(sala)) return;
  pendentes.set(sala, setTimeout(() => {
    pendentes.delete(sala);
    io.to(sala).emit('operacao_mudou', { t: Date.now() });
  }, 250));
}

app.post('/notify', (req, res) => {
  if (!iguais(req.get('X-Realtime-Secret') || '', SECRET)) {
    return res.status(403).json({ success: false });
  }
  const { restaurante_id: restauranteId, pedido } = req.body || {};
  const id = Number(restauranteId);
  if (!Number.isInteger(id) || id <= 0) {
    return res.status(422).json({ success: false });
  }
  const tipo = String((req.body && req.body.tipo) || 'novo_pedido');
  if (tipo === 'mudou') {
    avisarMudanca(`restaurante_${id}`);
  } else {
    io.to(`restaurante_${id}`).emit('novo_pedido', pedido || {});
    avisarMudanca(`restaurante_${id}`);
  }
  res.json({ success: true });
});

// Saude do servico (para monitorizacao): nao revela nada alem de "ok" e quantas ligacoes ha.
app.get('/health', (req, res) => res.json({ ok: true, ligacoes: io.engine.clientsCount }));

app.use((req, res) => res.status(404).end());

const PORT = Number(process.env.PORT || 3001);
server.listen(PORT, () => {
  console.log(`Tempo real (Socket.IO) na porta ${PORT}`);
});
