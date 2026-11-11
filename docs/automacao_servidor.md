# Automacao de email, backups e planos

## Verificacao segura

O diagnostico abaixo e somente leitura. Ele nao envia emails, nao cria backups e nao altera planos:

```powershell
php tools/check_automation_health.php
```

Num servidor com cron configurado, o resultado deve apresentar `healthy: true`. Avisos sobre cron atrasado ou backup pendente indicam que o codigo funciona, mas o agendador nao esta chamando os jobs na frequencia esperada. No InfinityFree gratuito esses avisos sao esperados, porque o provedor nao oferece cron.

## Jobs por linha de comando

Quando o servidor permite cron com PHP CLI, configure:

```text
0 * * * * php /CAMINHO/V00/src/public/cron/backup_automatico.php --json
15 2 * * * php /CAMINHO/V00/src/public/cron/notificar_vencimento_planos.php
```

O primeiro job procura backups vencidos a cada hora. O segundo processa diariamente avisos, expiracoes e downgrades agendados.

Para monitorar seguranca, WAF, readiness, alertas e vencimentos de evidencias sem renovar datas automaticamente, configure tambem:

```text
*/15 * * * * php /CAMINHO/V00/tools/security_ops_runner.php --json
```

Este job atualiza `app/storage/logs/security_ops_status.json` e `app/storage/logs/security_objective_gate.json`. Se WAF, pentest, DR ou tabletop vencerem, o status fica vermelho; as datas so devem ser renovadas depois de uma revisao real.

Antes de ativar, valide sem alterar dados:

```powershell
php src/public/cron/backup_automatico.php --dry-run --json
php src/public/cron/notificar_vencimento_planos.php --dry-run
php tools/security_ops_runner.php --json
```

## Jobs por HTTP

Quando o painel nao oferece PHP CLI, os dois endpoints aceitam uma chamada HTTP protegida por `CRON_HTTP_TOKEN`:

```text
https://SEU-DOMINIO/cron/backup_automatico.php?token=SEU_TOKEN
https://SEU-DOMINIO/cron/notificar_vencimento_planos.php?token=SEU_TOKEN
```

O token deve ser longo, aleatorio, diferente das senhas do banco e SMTP, e igual ao valor definido no `.env` do servidor. Sem token ou com token incorreto, o endpoint responde HTTP 403 e nao executa operacoes.

Prefira enviar o token no cabecalho `X-Cron-Token` quando o agendador suportar cabecalhos secretos. Nao publique URLs com token em repositorios, capturas de tela ou logs partilhados.

### Limite do InfinityFree gratuito

O InfinityFree gratuito nao disponibiliza cron jobs e proibe usar agendadores externos para reproduzir essa funcao. Portanto, nao configure as URLs acima num servico externo quando publicar nesse plano.

Neste ambiente, o sistema ainda tenta processar backups vencidos quando o administrador abre o dashboard ou a pagina de backup. Isso e oportunista: se ninguem entrar no sistema, nao existe garantia de horario. Para backups realmente horarios e notificacoes de plano diariamente, use uma hospedagem que permita cron, incluindo o plano premium indicado pelo proprio provedor.

## Email

O envio usa `USE_SMTP`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_SECURE` e `SMTP_FROM`. Execute o diagnostico depois de publicar o `.env` no servidor. Uma configuracao completa sem envio recente ainda aparece como aviso ate existir evidencia de entrega no log.

## Teste de preservacao de dados

Este teste aplica downgrade e expiracao dentro de transacoes e executa `ROLLBACK` no final:

```powershell
php tools/test_plano_lifecycle_downgrade.php
```

Ele compara contagens e identificadores de produtos, usuarios, mesas, categorias, clientes, pedidos, vendas e filiais antes e depois. Nunca use uma versao modificada desse teste que remova o `ROLLBACK` num banco de producao.
