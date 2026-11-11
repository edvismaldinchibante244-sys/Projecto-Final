# Migracao da idempotencia offline

## Escopo

A migracao `20260814_1400_offline_operations` representa a tabela
`offline_operations`. Ela preserva a chave unica por restaurante e operacao, o
hash canonico do pedido, o estado de processamento e a resposta usada no replay.

As regras de caixa, pedidos, pagamentos e estados nao foram alteradas. O DDL
antigo permanece como fallback para bancos ainda nao migrados.

## Validacao segura

```bat
php tools\db_migrate.php --validate --json
php tools\test_offline_operations_migration.php
```

Somente num sandbox:

```bat
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=NOME_EXACTO_DO_SANDBOX --json
php tools\test_offline_operations_migration_integration.php --target=NOME_EXACTO_DO_SANDBOX --json
```

O teste reserva uma operacao sintetica, repete durante o processamento, conclui,
valida o replay, rejeita a reutilizacao com dados diferentes e executa rollback.
