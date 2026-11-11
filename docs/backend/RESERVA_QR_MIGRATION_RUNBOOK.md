# Migracao do esquema QR de reservas

## Escopo

A migracao `20260814_1420_reserva_qr` representa `reserva_qr_sessoes`,
`reserva_pre_bloqueios` e os estados `chegou` e `concluido` do ENUM
`reservas.status`.

A migracao nao atualiza reservas existentes, nao cria QR Codes e nao altera
regras de confirmacao, check-in, pagamento ou conclusao. A sincronizacao
operacional existente continua ativa; somente o DDL passa a ser versionado.

## Validacao segura

```bat
php tools\db_migrate.php --validate --json
php tools\test_reserva_qr_migration.php
```

Antes de testar num sandbox cumulativo, crie a tabela base minima:

```bat
php tools\setup_reserva_qr_migration_sandbox.php --extend --target=NOME_EXACTO_DO_SANDBOX --json
```

Depois aplique e teste somente nesse sandbox. O teste usa tokens sinteticos e
executa rollback de sessoes e pre-bloqueios.
