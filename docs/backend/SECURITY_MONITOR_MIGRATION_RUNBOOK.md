# Migracao do monitor de seguranca

Esta etapa transfere para uma migracao versionada as tres tabelas que eram
criadas por `app/src/config/security_monitor.php` durante requisicoes:

- `blocked_ips`;
- `security_events`;
- `offline_sync_queue`.

`offline_sync_queue` nao substitui `offline_operations`: a primeira representa
a fila de sincronizacao e suas tentativas, enquanto a segunda garante a
idempotencia das operacoes recebidas.

## Limites de seguranca

- A migracao nao elimina nem actualiza dados existentes.
- As regras de classificacao, severidade, bloqueio, desbloqueio e SIEM nao sao
  alteradas.
- O DDL legado permanece como fallback enquanto a migracao nao estiver
  registada no banco.
- A aplicacao exige o nome exacto do banco e a confirmacao do runner.
- Os testes de integracao aceitam somente bancos identificados como teste,
  sandbox ou staging e revertem todos os dados sinteticos.

## Validacao sem banco

```powershell
php tools/db_migrate.php --validate
php tools/test_security_monitor_migration.php
```

## Aplicacao somente no sandbox autorizado

```powershell
$env:DB_NAME='nivora_migration_test'
php tools/db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_migration_test
php tools/test_security_monitor_migration_integration.php --target=nivora_migration_test
```

Repetir o comando de aplicacao deve devolver todas as migracoes como ja
aplicadas. O banco original fica fora deste procedimento.
