# Ensaio geral num servidor de teste (Hostinger)

Objetivo: instalar o Nivora do zero num endereço de teste (ex.: `teste.seudominio.com`), exatamente como será em produção, e só depois repetir no domínio real.

## Já testado aqui (03/11/2026)
- Banco vazio → `db/schema_base.sql` + 29 migrações: OK (80 tabelas).
- Encontrado e corrigido: num banco novo `mesas.numero` era número inteiro e o balcão "B1" falhava → migração `20261103_0900_mesas_numero_texto`.
- Encontrado e corrigido: um banco novo não tinha nenhum utilizador e não havia forma de entrar → `tools/criar_super_admin.php`.
- Backup (mysqldump) → apagar → repor: dados iguais.
- Login real pede o código de 2 passos (precisa de SMTP configurado).

## 1. Preparar no painel da Hostinger
- [ ] Criar o subdomínio `teste.` e ativar o **SSL** (grátis) + "Forçar HTTPS".
- [ ] PHP **8.2 ou superior**; extensões: pdo_mysql, gd, mbstring, openssl, zip, intl.
- [ ] `display_errors` = Off.
- [ ] Criar uma base MySQL **nova** com utilizador próprio (não root) e senha forte.
- [ ] Criar uma conta de email (ex.: `nao-responder@seudominio.com`) para o SMTP.

## 2. Enviar o sistema
- [ ] No seu PC: `php tools/criar_pacote.php` → envie o `.zip` da pasta `dist/` pelo Gestor de Ficheiros e extraia.
- [ ] Aponte a **pasta pública** do subdomínio para `src/public`.
- [ ] Copie `.env.example` para `.env` (na raiz, fora de `src/public`) e preencha:
  `APP_ENV=production`, `APP_URL=https://teste.seudominio.com`, dados da base, SMTP,
  e segredos **novos** (`php -r "echo bin2hex(random_bytes(32));"` para `APP_KEY` e `REALTIME_SECRET`).

## 3. Base de dados (Terminal SSH da Hostinger)
```
mysql -u UTILIZADOR -p NOME_BASE < db/schema_base.sql
php tools/db_migrate.php --apply --confirm=APPLY_MIGRATIONS --target=NOME_BASE --allow-non-test
php tools/criar_super_admin.php --nome="O seu nome" --email=voce@seudominio.com
php tools/criar_demo.php --email=demo@seudominio.com --plano=PROFISSIONAL
```

## 4. Tarefas automáticas (Cron Jobs no painel)
- [ ] Os mesmos comandos de `scripts/cron` que usa hoje (backups, avisos de plano, segurança) — com o caminho completo do servidor.

## 5. Verificar
- [ ] Super Admin → **Verificação de produção**: tudo verde.
- [ ] No telemóvel com dados móveis: `https://teste.../.env` e `https://teste.../db/schema_base.sql` → erro 403/404.
- [ ] Recebeu o email com o código de 2 passos?
- [ ] Recuperar senha: chega o email e o link funciona?

## 6. Teste no telemóvel (Android e iPhone, se possível)
- [ ] Entrar, abrir turno, abrir caixa.
- [ ] Novo pedido numa mesa → aparece na cozinha (com som) → fechar conta → talão.
- [ ] Ler o **QR de uma mesa** com a câmara e fazer um pedido como cliente.
- [ ] Desbloquear com **PIN** num terminal; ação sensível pede o PIN do gerente.
- [ ] Desligar a internet a meio de um pedido → voltar a ligar → o pedido sincroniza.
- [ ] Ingredientes: vender um prato com receita → o stock desce.
- [ ] Rodar o ecrã e usar com o dedo: botões grandes o suficiente, nada cortado.

## 7. Backup e reposição (no servidor)
- [ ] Fazer um backup pelo painel (ou `mysqldump`).
- [ ] Criar uma base vazia, repor o backup nela e confirmar que os pedidos/produtos estão lá.
- [ ] Apagar essa base de ensaio.

## 8. Antes de abrir no domínio real
- [ ] `php tools/limpar_testes.php` → ver a lista → apagar só os de teste com `--ids=... --apagar --confirmar=APAGAR_TESTES` (depois de um backup).
- [ ] Repetir os passos 1–5 no domínio real.
