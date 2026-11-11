# Migracao do ciclo de cobranca dos planos

## Escopo

A migracao `20260814_1345_plano_lifecycle` representa as tabelas
`plano_cobranca_logs` e `plano_lifecycle_notificacoes`. A chave unica de
notificacao continua a impedir repeticoes por restaurante, fim do ciclo, dias
restantes e canal.

A politica de vencimento, carencia, bloqueio, envio de email e downgrade nao e
alterada. O DDL legado permanece como fallback para bancos ainda nao migrados.

## Validacao segura

```bat
php tools\db_migrate.php --validate --json
php tools\test_plano_lifecycle_migration.php
```

Somente num banco identificado como teste:

```bat
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=NOME_EXACTO_DO_SANDBOX --json
php tools\test_plano_lifecycle_migration_integration.php --target=NOME_EXACTO_DO_SANDBOX --json
```

O teste integrado valida indices, JSON, rejeicao de notificacao duplicada e
rollback dos registos sinteticos. Nao execute esse teste em producao.
