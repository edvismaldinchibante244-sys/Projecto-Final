# Roteiro da migracao de auditoria

Migracao: `20260814_1255_audit_logs`.

```bat
set DB_NAME=nivora_migration_test
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_migration_test --json
php tools\test_audit_migration_integration.php --target=nivora_migration_test --json
```

O teste valida a tabela, quatro indices, contexto da sessao/requisicao e JSON.
O evento sintetico e removido com `ROLLBACK`.

## Validacao local

Em 2026-08-14, a migracao foi aplicada no sandbox, repetida como
`already_applied` e aprovou 16 verificacoes integradas. O evento sintetico foi
removido por rollback e o banco original permaneceu inalterado.
