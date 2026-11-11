# Procedimento de Incidente e Vazamento de Dados

**Estado:** procedimento operacional sujeito a revisao juridica e teste pratico.

## 1. Objetivo

Conter incidentes, preservar evidencia, reduzir danos, restaurar o EDVISTRO com seguranca e cumprir as obrigacoes legais e contratuais aplicaveis.

## 2. Papeis minimos

Antes da entrada em producao, registar nomes e contactos para:

- Responsavel pelo incidente e decisao final.
- Responsavel tecnico por contencao, investigacao e recuperacao.
- Responsavel por privacidade e avaliacao dos titulares afetados.
- Responsavel por comunicacao com restaurantes, fornecedores e autoridades.
- Advogado moçambicano para determinar obrigacoes, destinatarios e prazos legais.

O contacto operacional deve coincidir com `INCIDENT_CONTACT` no ambiente de producao.

## 3. Classificacao inicial

| Severidade | Exemplo | Acao inicial |
| --- | --- | --- |
| Critica | Extracao confirmada de dados, controlo administrativo, ransomware | Acionar equipa imediatamente e considerar suspensao controlada |
| Alta | Acesso indevido confirmado, chave exposta, alteracao de dados | Conter e investigar com prioridade |
| Media | Tentativa relevante bloqueada, vulnerabilidade sem exploracao confirmada | Corrigir, monitorizar e documentar |
| Baixa | Evento sem impacto ou falso positivo | Registar e encerrar com justificacao |

## 4. Fluxo obrigatorio

1. **Detetar e registar:** abrir um registo imutavel com hora UTC, origem do alerta, sistemas afetados e responsavel.
2. **Conter:** revogar sessoes e chaves afetadas, bloquear indicadores maliciosos e isolar apenas os componentes necessarios.
3. **Preservar evidencia:** guardar logs, hashes, configuracoes e linha temporal. Nao alterar o original nem incluir segredos no ticket.
4. **Determinar alcance:** identificar restaurantes, utilizadores, categorias de dados, volume, periodo e possibilidade de exfiltracao.
5. **Erradicar:** corrigir a causa, rodar segredos, remover persistencia e validar que o vetor deixou de funcionar.
6. **Avaliar notificacao:** advogado e responsavel de privacidade determinam, conforme a lei e contratos vigentes, se INTIC, outras autoridades, restaurantes ou titulares devem ser informados e em que prazo.
7. **Recuperar:** restaurar de copia verificada, aplicar correcoes, monitorizar intensivamente e obter autorizacao antes de reabrir.
8. **Aprender:** em ate cinco dias uteis internos, produzir causa raiz, impacto, decisoes, medidas e responsaveis com prazos.

## 5. Conteudo de uma comunicacao

Quando juridicamente indicado, a comunicacao deve ser factual e aprovada: natureza do incidente, dados e pessoas potencialmente afetadas, periodo, medidas tomadas, riscos, passos recomendados e canal de contacto. Nao afirmar que o incidente esta resolvido sem evidencia tecnica.

## 6. Regras de seguranca

- Nao apagar logs ou restaurar por cima da unica evidencia.
- Nao enviar bases, palavras-passe, chaves ou documentos de identidade por email comum.
- Nao prometer prazos legais sem confirmacao juridica do caso concreto.
- Nao atribuir culpa antes da investigacao.
- Guardar decisoes, aprovadores, notificacoes e comprovativos de entrega.

## 7. Teste e manutencao

Executar um exercicio de mesa semestral e um teste tecnico anual envolvendo comprometimento de conta, restauracao e comunicacao. Atualizar contactos, fornecedores e arquitetura depois de cada mudanca relevante.
