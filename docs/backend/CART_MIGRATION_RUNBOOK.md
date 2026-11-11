# Roteiro da migracao do carrinho do cliente

Migracao: `20260814_1240_cliente_carrinho`.

O piloto cria as tabelas `cliente_carrinhos` e `cliente_carrinho_itens` no
sandbox, com uma tabela `clientes` minima e sem dados reais.

```bat
set DB_NAME=nivora_migration_test
php tools\setup_cart_migration_sandbox.php --extend --target=nivora_migration_test --json
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_migration_test --json
php tools\test_cart_migration_integration.php --target=nivora_migration_test --json
```

O teste valida indices unicos, chaves estrangeiras, exclusao em cascata, total
do carrinho e rollback dos dados sinteticos.

## Validacao local

Em 2026-08-14, a tabela minima `clientes` foi criada vazia no sandbox. A
migracao criou as duas tabelas do carrinho, foi repetida como `already_applied`
e aprovou 15 verificacoes integradas. Cliente, carrinho e item sinteticos foram
revertidos; o banco original nao foi alterado.
