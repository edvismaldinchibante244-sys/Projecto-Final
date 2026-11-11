# Plano de evolucao do backend

O backend sera melhorado em fases pequenas. Cada fase deve preservar os fluxos
existentes, executar validacoes antes/depois e evitar alteracoes directas em
dados de producao.

## Fase 1 - Estabilizacao e diagnostico

- [x] Bloquear `DB_DEBUG` e `DB_DEBUG_DUMP` em producao.
- [x] Classificar SIEM desativado como aviso, nao como aprovacao.
- [x] Validar formato do endpoint SIEM.
- [x] Uniformizar a precedencia de variaveis entre banco e email.
- [x] Criar `tools/backend_quality_preflight.php`.
- [ ] Configurar `.env` de producao apenas no servidor real.
- [ ] Confirmar WAF, SIEM e crons no ambiente real.

## Fase 2 - Migracoes versionadas

- [x] Inventariar DDL executado durante requisicoes.
- [x] Criar `db/migrations/` com controlo de versoes, lock e checksum.
- [x] Exigir confirmacao explicita antes de ligar ao banco no modo de aplicacao.
- [x] Criar a primeira migracao piloto para categorias, sem aplica-la ao banco actual.
- [x] Exigir confirmacao do banco exacto e autorizacao extra para alvos nao isolados.
- [x] Testar e aplicar a migracao de categorias no sandbox local sem dados reais.
- [x] Testar a migracao de tema/marca no mesmo sandbox sem dados reais.
- [x] Testar a migracao de presenca online no sandbox sem dados reais.
- [x] Testar a migracao do carrinho do cliente no sandbox sem dados reais.
- [x] Testar a migracao da auditoria central no sandbox sem dados reais.
- [x] Testar a migracao da fila de downgrade de planos no sandbox sem dados reais.
- [x] Testar o catalogo central de planos em sandbox existente e sandbox novo.
- [x] Testar logs e notificacoes do ciclo de planos nos dois sandboxes.
- [x] Testar a idempotencia de operacoes offline nos dois sandboxes.
- [x] Testar o esquema QR de reservas nos dois sandboxes.
- [x] Testar o esquema do monitor de seguranca nos dois sandboxes.
- [x] Testar os metadados e observacoes do pedido QR nos dois sandboxes.
- [x] Preparar a migracao de seguranca das compras de planos com bloqueio de valores incompativeis.
- [x] Preparar a migracao da redefinicao de senha preservando encriptacao e hash dos tokens.
- [ ] Migrar os modulos seguintes um por vez.
- [ ] Manter compatibilidade temporaria e testar banco limpo e banco existente.

## Fase 3 - Testes integrados

- [ ] Introduzir PHPUnit sem remover os scripts actuais.
- [ ] Criar banco exclusivo de teste.
- [ ] Cobrir autenticacao, isolamento por restaurante e permissoes.
- [ ] Cobrir pedido, cozinha/bar, entrega, conta e pagamento.
- [ ] Cobrir planos, backup/restauro, privacidade e suporte.

## Fase 4 - APIs e servicos

- [ ] Definir a API oficial para reservas e manter wrappers temporarios.
- [ ] Padronizar respostas JSON, erros e transacoes.
- [ ] Extrair regras de negocio das paginas e endpoints grandes.
- [ ] Centralizar notificacoes, auditoria e validacao.

## Fase 5 - Funcionalidades pendentes

- [ ] Implementar campanhas WhatsApp/SMS com consentimento e auditoria.
- [ ] Implementar reativacao e cupoes com fila e repeticao segura.
- [ ] Validar entrega, falha, cancelamento e opt-out das mensagens.

## Regra de conclusao

Uma fase so termina quando sintaxe, preflights, testes aplicaveis e verificacao
dos fluxos afectados forem aprovados. Alteracoes de producao exigem configuracao
e confirmacao separadas do operador.
