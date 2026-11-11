# Nivora Restaurant (`V00`)

Mapa rapido para manutencao. O codigo de producao web vive em `src/public/`.

## Estrutura

| Pasta / ficheiro | Funcao |
|------------------|--------|
| `app/` | Backend PHP — config activa em `app/src/config/` |
| `app/config_legacy_backup/` | Antiga `app/config/` (backup; nao usar) |
| `src/public/` | **DocumentRoot** — paginas, APIs, CSS/JS, crons PHP |
| `tools/` | CLI de seguranca, preflight, DR drill, ops |
| `docs/` | Documentacao (seguranca em `docs/security/`) |
| `apps/` | Apps mobile (Flutter) |
| `db/` | Artefactos de base de dados |
| `scripts/cron/` | Scripts Windows de cron/ops (**implementacao**) |
| `run_*.bat` / `run_*.ps1` (raiz) | **Atalhos** para o Agendador de Tarefas |
| `public_legacy_backup/` | Antiga pasta `public/` (backup; nao usar) |
| `.env` | Segredos locais (nunca no Git) |
| `services/realtime/` | Implementacao e manifesto do servidor Socket.IO |
| `websocket-server.js` | Wrapper compativel para comandos antigos |

## Crons (Windows)

Preferir os wrappers na raiz (compativeis com tarefas ja agendadas):

- `run_backup_cron.bat`
- `run_security_ops_cron.bat`
- `run_plano_lifecycle_cron.bat`
- `run_websocket_server.bat`

Implementacao: `scripts/cron/` (ver `scripts/cron/README.md`).

O servidor realtime esta em `services/realtime/server.js`. O wrapper
`websocket-server.js` permanece na raiz para nao quebrar comandos existentes.

## Seguranca (CLI)

```bat
cd V00
php tools\security_preflight.php --json
php tools\security_ops_runner.php --json
php tools\backend_quality_preflight.php --json
```

Plano incremental do backend: `docs/backend/PHASED_BACKEND_PLAN.md`.

## Migracoes de banco

Validacao segura, sem ligar ao banco:

```bat
php tools\db_migrate.php --validate --json
php tools\inventory_runtime_schema.php --json
```

O modo `--apply` nao deve ser usado sem backup, ambiente de teste e confirmacao
operacional. Consulte `db/migrations/README.md`.

## Producao

1. DocumentRoot = `V00/src/public`
2. `.env` so no servidor (ver `.env.example`)
3. Nao expor `app/`, `tools/`, `docs/`, `.env`

## Testes automaticos

80 testes (unitarios, integracao com MySQL real e os scripts `tools/test_*.php`).
Correm no GitHub em cada push/PR (`.github/workflows/tests.yml`).

No computador (XAMPP), na pasta `V00`:

```bat
tools\testes.bat
```

O script recria uma base **descartavel** `nivora_test` a partir de
`db/schema_base.sql` + migracoes. Os testes recusam correr contra qualquer base
cujo nome nao contenha `test`, por isso a base real nunca e tocada.

## Base de dados do zero

`db/schema_base.sql` tem a estrutura base (sem dados). Para criar uma base nova:

```bat
mysql -u root nova_base < db\schema_base.sql
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nova_base --json
```

Alteracoes ao schema fazem-se **sempre** com uma nova migracao em `db/migrations/`.
