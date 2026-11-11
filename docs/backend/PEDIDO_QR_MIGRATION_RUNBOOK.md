# Migracao do pedido publico via QR

Esta etapa transfere para uma migracao versionada o esquema criado por
`app/src/api/reserva_publica_handler.php` durante o pedido publico:

- tabela `pedido_qr_meta`;
- coluna opcional `itens_pedido.observacao`.

## Limites de seguranca

- A migracao exige `itens_pedido` e `itens_pedido.subtotal` antes de criar ou
  alterar qualquer estrutura.
- Nenhum pedido ou item existente e actualizado ou eliminado.
- A chave unica `(restaurante_id, token, idem_key)` permanece inalterada.
- O fallback continua activo enquanto a migracao nao estiver registada.
- Os testes aceitam somente bancos identificados como teste, sandbox ou
  staging e revertem os dados sinteticos.

## Preparacao e validacao no sandbox

```powershell
$env:DB_NAME='nivora_migration_test'
php tools/setup_pedido_qr_migration_sandbox.php --extend --target=nivora_migration_test
php tools/db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_migration_test
php tools/test_pedido_qr_migration_integration.php --target=nivora_migration_test
```

Antes da ligacao ao banco:

```powershell
php tools/db_migrate.php --validate
php tools/test_pedido_qr_migration.php
```

O procedimento deve ser repetido somente nos sandboxes autorizados. A base
original nao faz parte desta etapa.
