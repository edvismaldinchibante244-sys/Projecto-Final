# Inventario de schema executado em runtime

Fotografia inicial da Fase 2, gerada em 2026-08-14 por
`php tools/inventory_runtime_schema.php`.

## Resumo

- Total: 149 ocorrencias.
- `CREATE TABLE`: 49.
- `ALTER TABLE`: 50.
- `CREATE INDEX`: 1.
- `ADD COLUMN`: 49.

Esses valores representam ocorrencias no codigo, nao necessariamente 149
alteracoes diferentes. Algumas rotinas verificam a estrutura antes de executar
DDL e outras repetem garantias semelhantes.

## Maiores concentracoes

| Arquivo | Ocorrencias |
|---|---:|
| `app/src/config/turno_schema.php` | 29 |
| `app/src/config/support_tickets.php` | 18 |
| `app/src/config/estoque_inteligente.php` | 14 |
| `app/src/config/promocao_schema.php` | 11 |
| `app/src/config/backup_helper.php` | 8 |
| `app/src/config/login_security.php` | 8 |
| `app/src/config/password_reset_helper.php` | 5 |
| `app/src/config/pedido_schema.php` | 5 |
| `app/src/Model/Venda.php` | 5 |

## Ordem segura proposta

1. Migrar primeiro um modulo pequeno e auxiliar, como categorias ou tema.
2. Validar banco novo e banco existente antes de remover o fallback legado.
3. Migrar autenticacao e recuperacao de senha somente apos os testes integrados.
4. Migrar turnos, suporte, stock e pedidos em fases independentes.
5. Remover DDL de runtime apenas depois de a migracao estar aplicada e validada.

O inventario pode ser atualizado a qualquer momento; o objetivo e fazer o total
diminuir gradualmente, sem uma reescrita unica e arriscada.

Progresso em 2026-08-15: 30 das 149 ocorrencias ja estao representadas por
migracoes versionadas. Restam 119 ocorrencias distribuidas por 16 arquivos;
os fallbacks continuam no codigo ate a aplicacao controlada em cada ambiente.

Progresso em 2026-08-21: 34 das 149 ocorrencias estao representadas por 13
migracoes versionadas. Restam 115 ocorrencias distribuidas por 15 arquivos;
os fallbacks continuam preservados ate a aplicacao e validacao por ambiente.

Progresso em 2026-08-21 (piloto seguinte): 39 das 149 ocorrencias estao
representadas por 14 migracoes versionadas. Restam 110 ocorrencias em 14
arquivos; nenhum fallback foi removido do banco ainda nao migrado.

## Piloto preparado

A migracao `20260814_1145_categoria_hierarquia` ja representa as colunas
`categorias.parent_id` e `categorias.ativo`. O DDL legado permanece contado ate
a migracao ser testada e aplicada num banco isolado. Consulte
`docs/backend/CATEGORY_MIGRATION_RUNBOOK.md`.

A migracao `20260814_1210_restaurante_brand_theme` representa
`restaurantes.cor_primaria` e `restaurantes.cor_secundaria` e tambem foi
validada no sandbox. O fallback continua contado e preservado no banco original.

A migracao `20260814_1225_usuario_presenca_online` representa
`usuarios.ultimo_acesso` e foi validada com ping, online/offline e rollback no
sandbox. O fallback permanece activo no banco original.

A migracao `20260814_1240_cliente_carrinho` representa as tabelas
`cliente_carrinhos` e `cliente_carrinho_itens`. Indices, cascatas e calculo do
total foram validados no sandbox; o fallback permanece no banco original.

A migracao `20260814_1255_audit_logs` representa a tabela central de auditoria.
Indices e contexto estruturado foram validados no sandbox com rollback.

A migracao `20260814_1310_plano_downgrades` representa a fila de downgrade de
planos. O agendamento, cancelamento, indices e rollback de dados sinteticos sao
validados no sandbox sem alterar a regra comercial do ciclo de planos.

A migracao `20260814_1330_plano_catalogo` representa as quatro tabelas criadas
por `plano_check.php`, os tres planos padrao e as permissoes do catalogo. O
fallback fica activo apenas enquanto a migracao nao estiver registada; nenhuma
view e removida pela migracao.

A migracao `20260814_1345_plano_lifecycle` representa os logs de cobranca e o
controlo idempotente das notificacoes de vencimento. A politica comercial fica
inalterada e o fallback continua disponivel para bancos ainda nao migrados.

A migracao `20260814_1400_offline_operations` representa o registo idempotente
da fila offline. Reserva, repeticao em processamento, replay concluido e rejeicao
de payload divergente sao validados sem alterar a logica dos pedidos.

A migracao `20260814_1420_reserva_qr` representa as sessoes QR, os
pre-bloqueios e a extensao do ciclo de estados da reserva. A sincronizacao de
dados continua separada e nenhuma reserva real e alterada pela migracao.

A migracao `20260814_1440_security_monitor` representa os bloqueios de IP, os
eventos de seguranca e a fila de sincronizacao offline. A classificacao de
ataques, os bloqueios e a integracao SIEM permanecem inalterados.

A migracao `20260815_0900_pedido_qr_meta` representa os metadados idempotentes
do pedido QR e a observacao por item. Nenhum pedido real e alterado e a chave
de repeticao segura permanece igual ao fluxo existente.

A migracao `20260821_1715_compras_planos_hardening` representa o hash SHA-256
do comprovativo e o metodo `TRIAL`. Valores desconhecidos bloqueiam a alteracao
do ENUM para impedir conversao silenciosa; o fallback de runtime foi mantido.

A migracao `20260821_1745_password_resets` representa a tabela de redefinicao,
a ligacao opcional ao utilizador e o lookup criptografico do email. O fluxo
continua a encriptar emails e armazenar apenas o hash do token. Em bancos novos,
`expires_at` usa `DATETIME` para evitar o valor padrao implicito incompatível de
algumas versoes do MySQL, mantendo a comparacao com `NOW()` na sessao UTC.
