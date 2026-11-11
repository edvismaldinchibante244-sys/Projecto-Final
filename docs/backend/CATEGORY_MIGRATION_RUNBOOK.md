# Roteiro da migracao piloto de categorias

Migracao: `20260814_1145_categoria_hierarquia`.

Este roteiro deve ser executado primeiro numa copia isolada do banco. Nao use o
banco de producao como primeiro teste.

## 1. Preparacao

1. Criar uma copia recente do banco num schema exclusivo de teste.
2. Configurar `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS` para a copia.
3. Confirmar manualmente que `DB_NAME` identifica o banco de teste.
4. Guardar um backup recuperavel antes da aplicacao.

Para o piloto local sem dados reais, o projecto inclui um provisionador que
recusa sobrescrever schemas existentes:

```bat
php tools\setup_category_migration_sandbox.php --create --target=nivora_migration_test --json
```

## 2. Validacao sem banco

```bat
php tools\db_migrate.php --validate --json
php tools\test_migration_framework.php
php tools\test_categoria_migration.php
```

O resultado esperado e uma migracao activa e zero ligacoes ao banco no primeiro
comando.

## 3. Estado somente leitura

```bat
php tools\db_migrate.php --status --json
```

Confirmar que a migracao aparece como `pending`. Este comando nao cria a tabela
de controlo.

## 4. Aplicacao no banco isolado

```bat
php tools\db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=nivora_test --json
```

O resultado esperado e `applied`. Repetir o comando deve retornar
`already_applied`, sem repetir `ALTER TABLE`.

Substitua `nivora_test` pelo nome exacto da copia isolada. O runner compara esse
valor com `SELECT DATABASE()` e recusa nomes que nao indiquem teste. Nao use
`--allow-non-test` nesta etapa piloto.

## 5. Verificacao funcional

Validar no banco de teste:

```sql
SHOW COLUMNS FROM categorias LIKE 'parent_id';
SHOW COLUMNS FROM categorias LIKE 'ativo';
SELECT id, checksum, applied_at
FROM schema_migrations
WHERE id = '20260814_1145_categoria_hierarquia';
```

Depois testar:

- listagem de categorias;
- criacao de categoria principal;
- criacao de subcategoria no plano permitido;
- rejeicao de subcategoria fora do plano;
- cardapio publico;
- inativacao de categoria;
- segunda requisicao sem DDL de fallback.

O teste SQL integrado usa dados sinteticos e executa `ROLLBACK`:

```bat
set DB_NAME=nivora_migration_test
php tools\test_categoria_migration_integration.php --target=nivora_migration_test --json
```

## 6. Recuperacao

Nao remover colunas automaticamente, pois elas podem conter dados depois do
teste. Se a migracao falhar, parar, guardar o erro e restaurar a copia isolada.
Somente depois de todos os testes aprovados deve ser preparado um procedimento
separado para producao.

## Validacao local do piloto

Em 2026-08-14, o sandbox `nivora_migration_test` foi criado sem dados reais. A
migracao foi aplicada uma vez, a segunda aplicacao retornou `already_applied` e
o estado ficou `applied` com checksum valido. O teste integrado confirmou as
colunas, categoria principal, subcategoria, hierarquia e inativacao; todos os
registos sinteticos foram revertidos com `ROLLBACK`.

O banco original nao recebeu a migracao e deve continuar a apresenta-la como
`pending` ate existir uma janela operacional propria.
