# Servico realtime

Servidor Socket.IO usado para notificacoes de pedidos em tempo real.

## Execucao

A partir da raiz `V00`:

```bat
run_websocket_server.bat
```

O arquivo `V00/websocket-server.js` e mantido como wrapper de compatibilidade.
A implementacao real vive em `services/realtime/server.js`.

## Dependencias

O manifesto deste servico e `services/realtime/package.json`.
Durante a transicao, o Node tambem consegue resolver o `node_modules/` existente
na raiz de `V00`. Uma instalacao futura pode ser feita dentro desta pasta.
