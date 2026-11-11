# Roteiro da migracao de presenca online

Migracao: `20260814_1225_usuario_presenca_online`.

O piloto adiciona somente `usuarios.ultimo_acesso` no sandbox local.

```bat
set DB_NAME=nivora_migration_test
php tools\setup_presence_migration_sandbox.php --extend --target=nivora_migration_test --json
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_migration_test --json
php tools\test_presence_migration_integration.php --target=nivora_migration_test --json
```

O teste cria um utilizador sintetico, valida ping, equipa online, marcacao offline
e termina com `ROLLBACK`. Nenhum utilizador do banco original e copiado.

## Validacao local

Em 2026-08-14, a tabela minima `usuarios` foi criada vazia no sandbox. A
migracao foi aplicada uma vez e repetida como `already_applied`. Quinze
verificacoes confirmaram a coluna, ping, equipa online, marcacao offline e
rollback do utilizador sintetico.

O banco original deve continuar com esta migracao em estado `pending`.
