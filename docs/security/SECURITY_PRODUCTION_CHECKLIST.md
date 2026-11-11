# Checklist de segurança para produção

Use este checklist antes de cada entrada em produção e registre responsável, data e evidência.

## Plataforma e segredos

- [ ] HTTPS obrigatório; redirecionamento HTTP validado e HSTS (`max-age`, `includeSubDomains`) habilitado somente após confirmar cobertura TLS.
- [ ] Cookies de sessão com `Secure`, `HttpOnly` e `SameSite=Strict` (ou exceção `Lax` documentada).
- [ ] `APP_ENV=production`; `display_errors=0`; erros técnicos restritos aos logs.
- [ ] `APP_KEY` forte, exclusiva por ambiente, fora do repositório, com rotação e procedimento de recuperação documentados.
- [ ] `TRUSTED_PROXIES` contém somente IPs/CIDRs reais do balanceador/CDN; headers encaminhados são removidos na borda e recriados pelo proxy confiável.

## Borda, abuso e monitoramento

- [ ] WAF/CDN bloqueia SQLi, XSS, traversal, probes de `.env`/`.git`, métodos inesperados e acesso HTTP a `app/src/api`; regras testadas sem depender apenas do WAF da aplicação.
- [ ] Rate limit usa armazenamento compartilhado e atômico (Redis ou equivalente) em todos os nós; limites e failover foram exercitados.
- [ ] Cron de segurança/SIEM está ativo, com alertas, credenciais mínimas, retry/cooldown e teste recente de entrega.
- [ ] Diretórios e arquivos de log não são servidos pelo web server; permissões são mínimas, rotação/retention estão definidas e descarte atende à política de privacidade.

## Migração e continuidade

- [ ] Flags legadas que aceitam token por query string, body alternativo ou header de sessão estão desligadas após concluir e observar a migração dos clientes.
- [ ] Backup criptografado e automatizado está íntegro; retenção, cópia externa e acesso mínimo foram verificados.
- [ ] Restore foi testado em ambiente isolado, com RPO/RTO e evidência datada.
- [ ] Smoke tests cobrem login/logout, expiração e regeneração de sessão, CSRF, roles, isolamento entre tenants, redaction de logs, validação de entrada, rate limit, erros 4xx/5xx seguros e bloqueio de `app/src/api`.
- [ ] `php -l`, testes focados de segurança e suítes de regressão relevantes passaram no mesmo artefato implantado.
