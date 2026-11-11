# Migracoes versionadas

Esta pasta contem as futuras alteracoes versionadas do schema MySQL. A
infraestrutura foi adicionada sem migracoes activas para nao alterar o banco
existente durante a fase de fundacao.

## Comandos seguros

Validar arquivos sem ligar ao banco:

```bat
php tools\db_migrate.php --validate --json
```

Consultar o estado no banco, sem criar tabelas:

```bat
php tools\db_migrate.php --status --json
```

Aplicar migracoes requer confirmacao explicita:

```bat
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_test --json
```

O comando `--apply` cria `schema_migrations`, usa lock exclusivo MySQL, guarda
checksum e recusa alteracoes em arquivos ja aplicados. O `--target` deve ser
exactamente igual ao banco conectado. Bancos sem `test`, `testing`, `sandbox` ou
`staging` no nome sao recusados por padrao; `--allow-non-test` exige autorizacao
operacional separada. Nao existe rollback automatico para DDL MySQL; cada
migracao deve ser pequena, testada em copia do banco e acompanhada de plano de
recuperacao.

## Nome e contrato

Formato: `AAAAMMDD_HHMM_descricao.php`. O arquivo deve retornar:

```php
<?php
return [
    'id' => '20260814_1200_exemplo',
    'description' => 'Descricao curta',
    'transactional' => false,
    'up' => static function (PDO $db): void {
        // DDL idempotente e previamente testado.
    },
];
```

Nao mova nem remova o fallback legado no mesmo passo em que criar a primeira
migracao. Primeiro aplicar e validar; depois retirar o DDL de runtime.
