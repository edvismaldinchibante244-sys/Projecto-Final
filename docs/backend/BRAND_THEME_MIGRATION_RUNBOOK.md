# Roteiro da migracao de tema e marca

Migracao: `20260814_1210_restaurante_brand_theme`.

O piloto usa o sandbox local criado na migracao de categorias e nao copia dados
do banco original.

```bat
set DB_NAME=nivora_migration_test
php tools\setup_brand_theme_migration_sandbox.php --extend --target=nivora_migration_test --json
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_migration_test --json
php tools\test_brand_theme_migration_integration.php --target=nivora_migration_test --json
```

O teste confirma `VARCHAR(7) NULL`, leitura das duas cores pelo helper visual e
reverte o restaurante sintetico com `ROLLBACK`. O fallback deve permanecer ate
a migracao ser aplicada no banco principal numa janela operacional separada.

## Validacao local

Em 2026-08-14, a tabela minima `restaurantes` foi adicionada ao sandbox sem
dados reais. A migracao foi aplicada uma vez, repetida como `already_applied` e
aprovou 17 verificacoes integradas. As cores `#123456` e `#ABCDEF` foram lidas
pelo helper com origem `db`; o restaurante sintetico foi removido por rollback.

O banco original deve continuar com esta migracao em estado `pending`.
