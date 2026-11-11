# Migracao da fila de downgrade de planos

## Escopo

A migracao `20260814_1310_plano_downgrades` representa a tabela
`plano_downgrades`, incluindo os indices da fila e do restaurante. A regra de
negocio de agendamento, validacao de limites, aplicacao e cancelamento nao foi
alterada.

O DDL legado em `app/src/config/plano_downgrade.php` permanece como fallback
temporario. Quando a migracao estiver registada, a requisicao deixa de executar
o `CREATE TABLE IF NOT EXISTS`.

## Validacao sem banco

```bat
php tools\db_migrate.php --validate --json
php tools\test_plano_downgrade_migration.php
```

## Aplicacao apenas em sandbox

Confirme primeiro que `DB_NAME` aponta para uma base vazia identificada como
`test`, `testing`, `sandbox` ou `staging`:

```bat
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=NOME_EXACTO_DO_SANDBOX --json
php tools\test_plano_downgrade_migration_integration.php --target=NOME_EXACTO_DO_SANDBOX --json
```

O teste integrado cria um downgrade sintetico dentro de uma transacao, valida
o agendamento e o cancelamento e executa `ROLLBACK`. Nao use esse teste numa
base de producao.

O teste nao carrega `plano_check.php`, porque o legado desse arquivo ainda
inicializa o catalogo de planos durante o `require`. Esse bootstrap foi
identificado como uma migracao separada e nao deve ficar escondido neste piloto.

## Recuperacao

O runner nao executa rollback automatico de DDL MySQL. Antes de uma futura
aplicacao real, produza backup verificado e janela de manutencao. Esta migracao
nao remove tabelas, colunas ou dados existentes.
