# Scripts de cron / ops (Windows)

Estes ficheiros sao a implementacao real. Na raiz de `V00/` existem **wrappers** com o mesmo nome para o Agendador de Tarefas continuar a funcionar.

| Script | O que faz |
|--------|-----------|
| `run_backup_cron.*` | Backups automaticos |
| `run_security_ops_cron.*` | Monitor + reator de seguranca |
| `run_plano_lifecycle_cron.*` | Notificacoes de vencimento de planos |
| `run_notificar_vencimento_planos.bat` | Alias do lifecycle |
| `run_websocket_server.bat` | Servidor Socket.IO |

Uso manual (a partir de `V00`):

```bat
scripts\cron\run_backup_cron.bat
```

ou pelo atalho na raiz:

```bat
run_backup_cron.bat
```
