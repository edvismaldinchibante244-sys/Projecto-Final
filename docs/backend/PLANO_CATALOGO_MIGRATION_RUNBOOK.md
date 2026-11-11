# Migracao do catalogo central de planos

## Escopo

A migracao `20260814_1330_plano_catalogo` representa as tabelas
`planos_disponiveis`, `restaurante_planos`, `plano_funcionalidades` e
`plano_logs`. Tambem regista os planos Basico, Profissional e Empresarial e a
fotografia actual das respectivas funcionalidades.

Quando a migracao estiver registada, `plano_check.php` deixa de executar DDL,
seed e `DROP VIEW` durante a requisicao. O fallback antigo permanece no arquivo
para bancos ainda nao migrados. A view legada nao e removida por esta migracao.

## Validacao

```bat
php tools\db_migrate.php --validate --json
php tools\test_plano_catalogo_migration.php
```

Para um sandbox novo e vazio:

```bat
php tools\setup_plan_catalog_migration_sandbox.php --create --target=nivora_plan_catalog_test --json
```

Depois configure apenas o processo filho para usar esse banco e execute:

```bat
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_plan_catalog_test --json
php tools\test_plano_catalogo_migration_integration.php --target=nivora_plan_catalog_test --json
```

O teste confirma planos, 152 permissoes, ausencia de duplicados, chaves de
cascata, preservacao da view legada e rollback de todos os dados sinteticos.
Nao execute em producao.
