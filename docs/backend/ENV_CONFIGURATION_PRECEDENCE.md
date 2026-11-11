# Precedencia da configuracao de ambiente

Os carregadores de configuracao do banco e do email seguem a mesma ordem:

1. variavel ja fornecida pelo processo, servidor ou plataforma de hospedagem;
2. `.env` e `.env.local` do projecto como fallback local;
3. valor padrao explicito do componente, quando aplicavel.

O helper de email nao deve substituir `DB_NAME`, `APP_ENV`, chaves SMTP ou
qualquer outra variavel que ja exista no processo. Isso permite executar testes
em bancos isolados e usar segredos injectados pela infraestrutura de producao.

## Verificacao segura

```powershell
php tools/test_email_env_precedence.php
```

O teste utiliza somente valores sinteticos e nao imprime configuracoes reais.
Ele tambem nao abre conexao ao banco nem tenta enviar email.
## SMTP em desenvolvimento e producao

Para evitar que uma variavel SMTP antiga, herdada pelo servidor local ou pela IDE,
seja misturada com as demais chaves do projeto, o SMTP segue uma regra propria:

- Em `APP_ENV=local` (ou outro ambiente nao produtivo), `USE_SMTP` e `SMTP_*`
  definidos no `.env` do projeto sao prioritarios.
- Em `APP_ENV=production` ou `prod`, as variaveis injetadas pelo processo/hosting
  continuam prioritarias. Os segredos de producao nao devem ficar em `.env`.

As demais configuracoes, como a base de dados, mantem a precedencia normal do
processo sobre o `.env` local.
