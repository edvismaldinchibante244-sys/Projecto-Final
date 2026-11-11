@echo off
REM WebSocket realtime (Socket.IO) — corre a partir da raiz V00 (onde esta node_modules)
setlocal
cd /d "%~dp0..\.."
if not exist "services\realtime\server.js" (
  echo services\realtime\server.js nao encontrado em %CD%
  exit /b 1
)
node services\realtime\server.js
exit /b %ERRORLEVEL%
