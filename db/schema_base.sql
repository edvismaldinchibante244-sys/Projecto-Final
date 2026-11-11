-- Estrutura base da base de dados Nivora (sem dados).
-- Gerado com: mysqldump --no-data --skip-comments --routines restaurante_saas
-- Uso: carregar numa base vazia e depois aplicar tools/db_migrate.php --apply
-- Ao alterar o schema, criar uma migracao em db/migrations (nao editar este ficheiro a mao).


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event` varchar(120) NOT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'INFO',
  `usuario_id` int(11) DEFAULT NULL,
  `restaurante_id` int(11) DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `method` varchar(12) DEFAULT NULL,
  `uri` varchar(500) DEFAULT NULL,
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_event` (`event`),
  KEY `idx_audit_usuario` (`usuario_id`),
  KEY `idx_audit_restaurante` (`restaurante_id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auditoria_turnos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auditoria_turnos` (
  `id` bigint(20) unsigned NOT NULL,
  `restaurante_id` bigint(20) unsigned NOT NULL,
  `turno_id` bigint(20) unsigned DEFAULT NULL,
  `responsavel_id` bigint(20) unsigned NOT NULL,
  `funcionario_afetado_id` bigint(20) unsigned NOT NULL,
  `tipo_acao` varchar(40) NOT NULL,
  `motivo` text NOT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backup_configuracoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_configuracoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `automatico` tinyint(1) NOT NULL DEFAULT 0,
  `frequencia` varchar(20) NOT NULL DEFAULT 'DIARIO',
  `hora_execucao` time NOT NULL DEFAULT '00:00:00',
  `retencao_dias` int(11) NOT NULL DEFAULT 30,
  `notificar_email` tinyint(1) NOT NULL DEFAULT 0,
  `ultimo_backup_em` datetime DEFAULT NULL,
  `proximo_backup_em` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_backup_config_restaurante` (`restaurante_id`),
  KEY `idx_backup_config_proximo` (`proximo_backup_em`),
  CONSTRAINT `fk_backup_config_restaurante` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backup_historico`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'MANUAL',
  `status` varchar(20) NOT NULL DEFAULT 'SUCESSO',
  `arquivo_nome` varchar(255) NOT NULL,
  `arquivo_caminho` varchar(500) NOT NULL,
  `tamanho_bytes` int(11) NOT NULL DEFAULT 0,
  `hash_arquivo` char(32) DEFAULT NULL,
  `total_tabelas` int(11) NOT NULL DEFAULT 0,
  `total_registros` int(11) NOT NULL DEFAULT 0,
  `origem` varchar(50) NOT NULL DEFAULT 'web',
  `mensagem` text DEFAULT NULL,
  `executado_em` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_backup_hist_restaurante` (`restaurante_id`),
  KEY `idx_backup_hist_executado` (`executado_em`),
  CONSTRAINT `fk_backup_hist_restaurante` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `blocked_ips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `blocked_ips` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(64) NOT NULL,
  `motivo` varchar(255) NOT NULL,
  `origem` varchar(40) NOT NULL DEFAULT 'manual',
  `bloqueio_tipo` enum('TEMPORARIO','PERMANENTE') NOT NULL DEFAULT 'TEMPORARIO',
  `bloqueado_ate` datetime DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_por` int(11) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_blocked_ip` (`ip`),
  KEY `idx_blocked_ativo` (`ativo`),
  KEY `idx_blocked_ate` (`bloqueado_ate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caixa_turnos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caixa_turnos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `restaurante_id` bigint(20) unsigned NOT NULL,
  `caixa_id` bigint(20) unsigned NOT NULL,
  `turno_id` bigint(20) unsigned NOT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `data_abertura` datetime NOT NULL DEFAULT current_timestamp(),
  `data_fechamento` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ABERTO',
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_caixa_turno_aberto` (`caixa_id`,`turno_id`),
  KEY `idx_caixa_turnos_restaurante_status` (`restaurante_id`,`status`),
  KEY `idx_caixa_turnos_turno` (`turno_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `caixas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caixas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `saldo_inicial` decimal(10,2) DEFAULT 0.00,
  `saldo_final` decimal(10,2) DEFAULT 0.00,
  `status` enum('ABERTO','FECHADO') DEFAULT 'ABERTO',
  `data_abertura` datetime DEFAULT current_timestamp(),
  `data_fechamento` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_restaurante_status` (`restaurante_id`,`status`),
  KEY `idx_data_abertura` (`data_abertura`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `campanhas_marketing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `campanhas_marketing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `tipo` enum('cupom','cashback','pontos','fidelidade','aniversario','promocao') DEFAULT 'cupom',
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `desconto_valor` decimal(10,2) DEFAULT NULL,
  `desconto_percentual` int(11) DEFAULT NULL,
  `restricoes` text DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `restaurante_id` (`restaurante_id`,`ativo`,`data_fim`),
  CONSTRAINT `campanhas_marketing_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `compras_planos` (`restaurante_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cardapio_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cardapio_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `mesa_id` int(11) DEFAULT NULL,
  `sessao_hash` char(64) DEFAULT NULL,
  `evento` varchar(32) NOT NULL,
  `produto_id` int(11) DEFAULT NULL,
  `visitor_hash` char(64) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cardapio_events_restaurante_created` (`restaurante_id`,`created_at`),
  KEY `idx_cardapio_events_evento` (`evento`),
  KEY `idx_cardapio_events_produto` (`produto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cardapio_imagens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cardapio_imagens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `caminho_imagem` varchar(255) DEFAULT NULL,
  `descricao` varchar(200) DEFAULT NULL,
  `ordem` int(11) DEFAULT 0,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `restaurante_id` (`restaurante_id`,`produto_id`),
  CONSTRAINT `cardapio_imagens_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `compras_planos` (`restaurante_id`) ON DELETE CASCADE,
  CONSTRAINT `cardapio_imagens_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  `filial_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `restaurante_id` (`restaurante_id`),
  KEY `idx_categoria_filial` (`filial_id`),
  CONSTRAINT `categorias_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cliente_carrinho_itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cliente_carrinho_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `carrinho_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `quantidade` decimal(10,2) NOT NULL DEFAULT 1.00,
  `preco_unitario` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carrinho_produto` (`carrinho_id`,`produto_id`),
  KEY `idx_carrinho_itens_produto` (`produto_id`),
  CONSTRAINT `fk_carrinho_itens_carrinho` FOREIGN KEY (`carrinho_id`) REFERENCES `cliente_carrinhos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cliente_carrinhos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cliente_carrinhos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `restaurante_id` int(11) NOT NULL,
  `atualizado_em` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_carrinho_cliente_rest` (`cliente_id`,`restaurante_id`),
  KEY `idx_carrinho_rest` (`restaurante_id`),
  CONSTRAINT `fk_carrinho_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cliente_enderecos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cliente_enderecos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `restaurante_id` int(11) NOT NULL,
  `rotulo` varchar(60) NOT NULL DEFAULT 'Casa',
  `endereco` varchar(255) NOT NULL,
  `referencia` varchar(255) DEFAULT NULL,
  `cidade` varchar(80) DEFAULT NULL,
  `provincia` varchar(80) DEFAULT NULL,
  `telefone_contacto` varchar(30) DEFAULT NULL,
  `is_padrao` tinyint(1) NOT NULL DEFAULT 0,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_end_cliente` (`cliente_id`),
  KEY `idx_end_rest` (`restaurante_id`),
  CONSTRAINT `fk_end_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cliente_historico_compras`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cliente_historico_compras` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `venda_id` int(11) DEFAULT NULL,
  `restaurante_id` int(11) NOT NULL,
  `data_compra` datetime NOT NULL,
  `valor_total` decimal(10,2) DEFAULT NULL,
  `desconto_aplicado` decimal(10,2) DEFAULT NULL,
  `pontos_ganhos` int(11) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `cliente_id` (`cliente_id`,`data_compra`),
  KEY `restaurante_id` (`restaurante_id`),
  CONSTRAINT `cliente_historico_compras_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cliente_historico_compras_ibfk_2` FOREIGN KEY (`restaurante_id`) REFERENCES `compras_planos` (`restaurante_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cliente_password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cliente_password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cpr_cliente` (`cliente_id`),
  KEY `idx_cpr_token` (`token_hash`),
  CONSTRAINT `fk_cpr_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `senha_hash` varchar(255) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `data_nascimento` date DEFAULT NULL,
  `tipo_cliente` enum('comum','vip','corporativo','fornecedor') DEFAULT 'comum',
  `documento` varchar(20) DEFAULT NULL,
  `endereco` text DEFAULT NULL,
  `cidade` varchar(50) DEFAULT NULL,
  `provincia` varchar(50) DEFAULT NULL,
  `pais` varchar(50) DEFAULT NULL,
  `frequencia_media_dias` int(11) DEFAULT 0,
  `ticket_medio` decimal(10,2) DEFAULT 0.00,
  `data_primeira_visita` date DEFAULT NULL,
  `data_ultima_visita` date DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `score_fidelidade` int(11) DEFAULT 0,
  `bloqueado` tinyint(1) DEFAULT 0,
  `motivo_bloqueio` text DEFAULT NULL,
  `notas_internas` text DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `app_ativo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_login_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clientes_rest_email` (`restaurante_id`,`email`),
  KEY `restaurante_id` (`restaurante_id`,`tipo_cliente`,`ativo`),
  KEY `email` (`email`,`telefone`),
  CONSTRAINT `clientes_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `compras_planos` (`restaurante_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `combo_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `combo_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `combo_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `quantidade` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `combo_id` (`combo_id`),
  KEY `produto_id` (`produto_id`),
  CONSTRAINT `combo_items_ibfk_1` FOREIGN KEY (`combo_id`) REFERENCES `combos_promocionais` (`id`) ON DELETE CASCADE,
  CONSTRAINT `combo_items_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `combos_promocionais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `combos_promocionais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `preco_combo` decimal(10,2) NOT NULL,
  `preco_original` decimal(10,2) DEFAULT NULL,
  `data_inicio` date DEFAULT NULL,
  `data_fim` date DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `restaurante_id` (`restaurante_id`,`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `compras_planos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compras_planos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `plano_atual` varchar(20) NOT NULL,
  `plano_novo` varchar(20) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `metodo_pagamento` enum('DINHEIRO','MPESA','CARTAO','TRANSFERENCIA','TRIAL') NOT NULL,
  `ciclo` enum('MENSAL','TRIMESTRAL','ANUAL') NOT NULL DEFAULT 'MENSAL',
  `comprovativo_path` varchar(255) DEFAULT NULL,
  `hash_arquivo` char(64) DEFAULT NULL,
  `comprovante_path` varchar(255) DEFAULT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `status` enum('PENDENTE','PAGO','CANCELADO','APROVADO') DEFAULT 'PENDENTE',
  `referencia_pagamento` varchar(100) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `data_pagamento` datetime DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tempo_limite_minutos` int(11) DEFAULT 30,
  `data_expiracao` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_referencia` (`referencia`),
  KEY `idx_comprovante` (`comprovante_path`),
  KEY `idx_compras_planos_ciclo` (`restaurante_id`,`ciclo`,`status`),
  KEY `idx_compras_status_data` (`status`,`criado_em`),
  KEY `idx_compras_restaurante_status` (`restaurante_id`,`status`),
  KEY `idx_compras_hash_arquivo` (`hash_arquivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) DEFAULT NULL,
  `chave` varchar(100) NOT NULL,
  `valor` text NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `restaurante_id` (`restaurante_id`,`chave`),
  CONSTRAINT `config_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cupons_desconto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cupons_desconto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `campanha_id` int(11) DEFAULT NULL,
  `codigo` varchar(50) NOT NULL,
  `descricao` varchar(200) DEFAULT NULL,
  `tipo` enum('percentual','fixo') DEFAULT 'percentual',
  `valor_desconto` decimal(10,2) DEFAULT NULL,
  `quantidade_total` int(11) DEFAULT NULL,
  `quantidade_usada` int(11) DEFAULT 0,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  UNIQUE KEY `unique_cupom` (`restaurante_id`,`codigo`),
  KEY `campanha_id` (`campanha_id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `restaurante_id` (`restaurante_id`,`codigo`,`ativo`,`data_fim`),
  CONSTRAINT `cupons_desconto_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `compras_planos` (`restaurante_id`) ON DELETE CASCADE,
  CONSTRAINT `cupons_desconto_ibfk_2` FOREIGN KEY (`campanha_id`) REFERENCES `campanhas_marketing` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cupons_desconto_ibfk_3` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_subject_request_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_subject_request_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `event_detail` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_dsr_event_request` (`request_id`,`created_at`),
  KEY `idx_dsr_event_type` (`event_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_subject_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `data_subject_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` char(36) NOT NULL,
  `request_type` varchar(30) NOT NULL,
  `nome` text NOT NULL,
  `email` text NOT NULL,
  `email_lookup` char(64) NOT NULL,
  `restaurante` text DEFAULT NULL,
  `descricao` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'PENDENTE_VERIFICACAO',
  `admin_notes` text DEFAULT NULL,
  `handled_by` int(11) DEFAULT NULL,
  `identity_verified_at` datetime DEFAULT NULL,
  `identity_verified_by` int(11) DEFAULT NULL,
  `internal_notified_at` datetime DEFAULT NULL,
  `receipt_sent_at` datetime DEFAULT NULL,
  `export_generated_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `ip_hash` char(64) NOT NULL,
  `user_agent_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_id` (`public_id`),
  KEY `idx_dsr_email` (`email_lookup`),
  KEY `idx_dsr_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_alertas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_alertas` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `insumo_id` int(11) DEFAULT NULL,
  `tipo` enum('REPOSICAO','VALIDADE','CONSUMO_ANOMALO') NOT NULL,
  `severidade` enum('BAIXA','MEDIA','ALTA','CRITICA') NOT NULL DEFAULT 'MEDIA',
  `titulo` varchar(150) NOT NULL,
  `detalhe` varchar(255) DEFAULT NULL,
  `resolvido` tinyint(1) NOT NULL DEFAULT 0,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolvido_em` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alerta_rest_filial` (`restaurante_id`,`filial_id`,`resolvido`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_ficha_itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_ficha_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ficha_id` int(11) NOT NULL,
  `insumo_id` int(11) NOT NULL,
  `quantidade_base` decimal(14,3) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ficha_item_ficha` (`ficha_id`),
  KEY `idx_ficha_item_insumo` (`insumo_id`),
  CONSTRAINT `fk_ficha_item_ficha` FOREIGN KEY (`ficha_id`) REFERENCES `estoque_fichas_tecnicas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_fichas_tecnicas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_fichas_tecnicas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `produto_id` int(11) NOT NULL,
  `rendimento_por_produto` decimal(14,3) NOT NULL DEFAULT 1.000,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ficha_produto` (`restaurante_id`,`filial_id`,`produto_id`),
  KEY `idx_ficha_rest_filial` (`restaurante_id`,`filial_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_insumos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_insumos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `nome` varchar(140) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `unidade_base` enum('g','ml','un') NOT NULL DEFAULT 'un',
  `estoque_minimo_base` decimal(14,3) NOT NULL DEFAULT 0.000,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_insumo_sku` (`restaurante_id`,`filial_id`,`sku`),
  KEY `idx_insumo_rest_filial` (`restaurante_id`,`filial_id`),
  KEY `idx_insumo_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_lotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_lotes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `insumo_id` int(11) NOT NULL,
  `quantidade_base` decimal(14,3) NOT NULL,
  `saldo_base` decimal(14,3) NOT NULL,
  `custo_unitario_base` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `validade` date DEFAULT NULL,
  `referencia_compra` varchar(120) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lote_consumo` (`restaurante_id`,`filial_id`,`insumo_id`,`validade`,`criado_em`),
  KEY `idx_lote_validade` (`validade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_movimentacao`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_movimentacao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `tipo_movimentacao` enum('entrada','saida','devolucao','ajuste','perda') DEFAULT 'entrada',
  `quantidade` int(11) NOT NULL,
  `motivo` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `restaurante_id` (`restaurante_id`,`produto_id`,`criado_em`),
  CONSTRAINT `estoque_movimentacao_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `compras_planos` (`restaurante_id`) ON DELETE CASCADE,
  CONSTRAINT `estoque_movimentacao_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `estoque_movimentacao_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_movimentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_movimentos` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `insumo_id` int(11) NOT NULL,
  `tipo` enum('ENTRADA','SAIDA','PERDA','AJUSTE','TRANSFERENCIA') NOT NULL,
  `origem` enum('COMPRA','PEDIDO','COZINHA','CAIXA','MANUAL','SISTEMA') NOT NULL DEFAULT 'SISTEMA',
  `quantidade_base` decimal(14,3) NOT NULL,
  `custo_unitario_base` decimal(14,4) DEFAULT NULL,
  `custo_total` decimal(14,4) DEFAULT NULL,
  `unidade_origem` varchar(12) DEFAULT NULL,
  `pedido_id` int(11) DEFAULT NULL,
  `venda_id` int(11) DEFAULT NULL,
  `motivo` varchar(180) DEFAULT NULL,
  `responsavel_id` int(11) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mov_rest_filial_data` (`restaurante_id`,`filial_id`,`criado_em`),
  KEY `idx_mov_insumo` (`insumo_id`,`criado_em`),
  KEY `idx_mov_origem` (`origem`,`pedido_id`,`venda_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_perdas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_perdas` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `insumo_id` int(11) NOT NULL,
  `quantidade_base` decimal(14,3) NOT NULL,
  `custo_perdido` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `motivo` varchar(180) NOT NULL,
  `responsavel_id` int(11) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_perda_rest_filial_data` (`restaurante_id`,`filial_id`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estoque_saldos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estoque_saldos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `insumo_id` int(11) NOT NULL,
  `saldo_base` decimal(14,3) NOT NULL DEFAULT 0.000,
  `custo_medio` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_saldo_unico` (`restaurante_id`,`filial_id`,`insumo_id`),
  KEY `idx_saldo_rest_filial` (`restaurante_id`,`filial_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fatura_sequencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fatura_sequencias` (
  `restaurante_id` int(11) NOT NULL,
  `ano` smallint(5) unsigned NOT NULL,
  `ultimo` int(10) unsigned NOT NULL,
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`restaurante_id`,`ano`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `filiais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `filiais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_matriz_id` int(11) NOT NULL,
  `nome` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `telefone` varchar(50) DEFAULT NULL,
  `endereco` varchar(500) DEFAULT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `nuit` varchar(50) DEFAULT NULL,
  `status` enum('ATIVO','INATIVO','BLOQUEADO') DEFAULT 'ATIVO',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_matriz` (`restaurante_matriz_id`),
  CONSTRAINT `filiais_ibfk_1` FOREIGN KEY (`restaurante_matriz_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `funcionarios_turnos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `funcionarios_turnos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `restaurante_id` int(11) NOT NULL,
  `cargo` varchar(30) NOT NULL DEFAULT 'GARCOM',
  `data` date NOT NULL,
  `data_saida` date DEFAULT NULL,
  `turno` enum('manha','noite','integral') NOT NULL,
  `hora_entrada` time DEFAULT NULL,
  `hora_saida` time DEFAULT NULL,
  `status` enum('planejado','ativo','finalizado','ausente') DEFAULT 'planejado',
  `observacoes` text DEFAULT NULL,
  `motivo_intervencao` text DEFAULT NULL,
  `responsavel_abertura_id` bigint(20) unsigned DEFAULT NULL,
  `responsavel_fechamento_id` bigint(20) unsigned DEFAULT NULL,
  `abertura_manual` tinyint(1) NOT NULL DEFAULT 0,
  `fechamento_manual` tinyint(1) NOT NULL DEFAULT 0,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_turnos_restaurante_status` (`restaurante_id`,`status`,`data`),
  KEY `idx_turnos_usuario_status` (`usuario_id`,`status`),
  KEY `idx_turnos_restaurante_data` (`restaurante_id`,`data`),
  CONSTRAINT `funcionarios_turnos_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `funcionarios_turnos_ibfk_2` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `itens_pedido`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `itens_pedido` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedido_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `promocao_id` int(11) DEFAULT NULL,
  `produto_nome` varchar(150) NOT NULL DEFAULT '',
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `destino` enum('cozinha','bar') NOT NULL DEFAULT 'cozinha',
  `status` enum('pendente','em_preparo','pronto','entregue') NOT NULL DEFAULT 'pendente',
  `iniciado_preparo_em` datetime DEFAULT NULL,
  `pronto_em` datetime DEFAULT NULL,
  `entregue_em` datetime DEFAULT NULL,
  `preco_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `observacao` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `idx_itens_pedido_status_destino` (`status`,`destino`,`pedido_id`),
  KEY `idx_itens_pedido_pedido` (`pedido_id`),
  KEY `idx_itens_pedido_promocao` (`promocao_id`),
  CONSTRAINT `itens_pedido_ibfk_1` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `itens_pedido_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `itens_venda`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `itens_venda` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `venda_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `promocao_id` int(11) DEFAULT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `preco_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `venda_id` (`venda_id`),
  KEY `produto_id` (`produto_id`),
  KEY `idx_itens_venda_promocao` (`promocao_id`),
  CONSTRAINT `itens_venda_ibfk_1` FOREIGN KEY (`venda_id`) REFERENCES `vendas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `itens_venda_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `legal_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `legal_acceptances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `restaurante_id` int(11) DEFAULT NULL,
  `email_lookup` char(64) DEFAULT NULL,
  `contexto` varchar(40) NOT NULL,
  `terms_version` varchar(40) NOT NULL,
  `terms_hash` char(64) NOT NULL,
  `privacy_version` varchar(40) NOT NULL,
  `privacy_hash` char(64) NOT NULL,
  `cookie_version` varchar(40) DEFAULT NULL,
  `cookie_hash` char(64) DEFAULT NULL,
  `ip_hash` char(64) NOT NULL,
  `user_agent_hash` char(64) NOT NULL,
  `accepted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_legal_user` (`usuario_id`,`accepted_at`),
  KEY `idx_legal_restaurant` (`restaurante_id`,`accepted_at`),
  KEY `idx_legal_email` (`email_lookup`),
  KEY `idx_legal_versions` (`terms_version`,`privacy_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mesas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mesas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `numero` int(11) NOT NULL,
  `capacidade` int(11) DEFAULT 4,
  `status` enum('LIVRE','OCUPADA','RESERVADA') DEFAULT 'LIVRE',
  `qrcode` varchar(255) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  `filial_id` int(11) DEFAULT NULL,
  `garcom_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `restaurante_id` (`restaurante_id`),
  KEY `idx_mesa_filial` (`filial_id`),
  KEY `idx_mesas_garcom` (`garcom_id`),
  CONSTRAINT `fk_mesas_garcom` FOREIGN KEY (`garcom_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mesas_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offline_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offline_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `op_id` varchar(100) NOT NULL,
  `restaurante_id` int(11) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `endpoint` varchar(190) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'PROCESSING',
  `response_json` longtext DEFAULT NULL,
  `http_status` smallint(6) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offline_operation` (`restaurante_id`,`op_id`),
  KEY `idx_offline_operations_status` (`status`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offline_sync_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offline_sync_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `op_id` varchar(80) NOT NULL,
  `restaurante_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `device_id` varchar(120) DEFAULT NULL,
  `endpoint` varchar(255) NOT NULL,
  `method` varchar(12) NOT NULL DEFAULT 'POST',
  `status` enum('PENDING','DONE','FAILED') NOT NULL DEFAULT 'PENDING',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_error` varchar(255) DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_sync_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offline_op_id` (`op_id`),
  KEY `idx_offline_status` (`status`),
  KEY `idx_offline_restaurante` (`restaurante_id`),
  KEY `idx_offline_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` text NOT NULL,
  `email_lookup` char(64) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `used` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_email_lookup` (`email_lookup`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedido_app_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedido_app_meta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedido_id` int(11) NOT NULL,
  `restaurante_id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `idem_key` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_idem` (`restaurante_id`,`cliente_id`,`idem_key`),
  KEY `idx_app_pedido` (`pedido_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedido_itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedido_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedido_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  `preco` decimal(10,2) NOT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `pedido_id` (`pedido_id`),
  KEY `produto_id` (`produto_id`),
  CONSTRAINT `pedido_itens_ibfk_1` FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`),
  CONSTRAINT `pedido_itens_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedido_qr_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedido_qr_meta` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pedido_id` bigint(20) unsigned NOT NULL,
  `restaurante_id` int(11) NOT NULL,
  `token` varchar(128) NOT NULL,
  `idem_key` varchar(128) NOT NULL,
  `cliente_nome` varchar(160) DEFAULT NULL,
  `cliente_telefone` varchar(60) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qr_idem` (`restaurante_id`,`token`,`idem_key`),
  KEY `idx_qr_token_created` (`token`,`created_at`),
  KEY `idx_qr_pedido` (`pedido_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedido_sequencias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedido_sequencias` (
  `restaurante_id` int(11) NOT NULL,
  `dia` date NOT NULL,
  `ultimo` int(10) unsigned NOT NULL,
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`restaurante_id`,`dia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedido_status_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedido_status_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedido_id` int(11) NOT NULL,
  `status_anterior` varchar(50) DEFAULT NULL,
  `status_novo` varchar(50) DEFAULT NULL,
  `tempo_espera_minutos` int(11) DEFAULT NULL,
  `usuario_responsavel` int(11) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `usuario_responsavel` (`usuario_responsavel`),
  KEY `pedido_id` (`pedido_id`,`criado_em`),
  CONSTRAINT `pedido_status_log_ibfk_1` FOREIGN KEY (`pedido_id`) REFERENCES `vendas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pedido_status_log_ibfk_2` FOREIGN KEY (`usuario_responsavel`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pedidos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `mesa_id` int(11) DEFAULT NULL,
  `numero_pedido` varchar(30) NOT NULL,
  `status` enum('PENDENTE','NOVO','CONFIRMADO','PREPARANDO','ABERTO','EM_ANDAMENTO','PRONTO','ENTREGUE','CONTA','CONTA_SOLICITADA','PAGO','CANCELADO') NOT NULL DEFAULT 'PENDENTE',
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cliente_nome` varchar(100) DEFAULT NULL,
  `cliente_telefone` varchar(30) DEFAULT NULL,
  `endereco_entrega` varchar(255) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `origem` varchar(20) NOT NULL DEFAULT 'BALCAO',
  `tipo_pedido` varchar(20) DEFAULT 'TAKEAWAY',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `iniciado_preparo_em` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `garcom_id` int(11) DEFAULT NULL,
  `fonte` enum('GARCOM','QR','APP','BALCAO') NOT NULL DEFAULT 'GARCOM',
  `pronto_em` datetime DEFAULT NULL,
  `entregue_em` datetime DEFAULT NULL,
  `conta_em` datetime DEFAULT NULL,
  `pago_em` datetime DEFAULT NULL,
  `cancelado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mesa_id` (`mesa_id`),
  KEY `idx_pedidos_restaurante` (`restaurante_id`),
  KEY `idx_pedidos_status` (`status`),
  KEY `idx_pedidos_cozinha` (`restaurante_id`,`status`,`criado_em`),
  KEY `idx_pedidos_garcom` (`garcom_id`),
  KEY `idx_pedidos_fonte` (`restaurante_id`,`fonte`,`status`),
  KEY `idx_pedidos_preparo` (`restaurante_id`,`iniciado_preparo_em`),
  CONSTRAINT `fk_pedidos_garcom` FOREIGN KEY (`garcom_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pedidos_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pedidos_ibfk_2` FOREIGN KEY (`mesa_id`) REFERENCES `mesas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plano_cobranca_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plano_cobranca_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `evento` varchar(80) NOT NULL,
  `severidade` varchar(20) NOT NULL DEFAULT 'INFO',
  `detalhes_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`detalhes_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pcl_restaurante` (`restaurante_id`),
  KEY `idx_pcl_evento` (`evento`),
  KEY `idx_pcl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plano_downgrades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plano_downgrades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `plano_origem` varchar(30) NOT NULL,
  `plano_destino` varchar(30) NOT NULL,
  `aplicar_em` date NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'AGENDADO',
  `limites_json` longtext DEFAULT NULL,
  `criado_por` int(11) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `aplicado_em` datetime DEFAULT NULL,
  `cancelado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_downgrade_fila` (`status`,`aplicar_em`),
  KEY `idx_downgrade_restaurante` (`restaurante_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plano_funcionalidades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plano_funcionalidades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plano_id` int(11) NOT NULL,
  `funcionalidade` varchar(100) NOT NULL,
  `permitido` tinyint(1) DEFAULT 1,
  `limite_extra` decimal(10,2) DEFAULT 0.00,
  `descricao` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_plano_func` (`plano_id`,`funcionalidade`),
  CONSTRAINT `plano_funcionalidades_ibfk_1` FOREIGN KEY (`plano_id`) REFERENCES `planos_disponiveis` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plano_lifecycle_notificacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plano_lifecycle_notificacoes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `data_fim` date NOT NULL,
  `dias_restantes` int(11) NOT NULL,
  `canal` varchar(20) NOT NULL DEFAULT 'email',
  `destinatario` varchar(190) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lifecycle_notif` (`restaurante_id`,`data_fim`,`dias_restantes`,`canal`),
  KEY `idx_lifecycle_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plano_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plano_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `funcionalidade` varchar(100) NOT NULL,
  `recurso` varchar(50) NOT NULL,
  `acao` enum('TENTATIVA','SUCESSO','BLOQUEADO','CONSULTA') DEFAULT 'TENTATIVA',
  `quantidade` int(11) DEFAULT 1,
  `limite_anterior` int(11) DEFAULT NULL,
  `limite_restante` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `detalhes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_restaurante_data` (`restaurante_id`,`created_at`),
  KEY `idx_funcionalidade` (`funcionalidade`),
  KEY `idx_acao` (`acao`),
  KEY `idx_restaurante` (`restaurante_id`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `plano_logs_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plano_vencimento_notificacoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plano_vencimento_notificacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `data_fim` date NOT NULL,
  `dias_restantes` int(11) NOT NULL,
  `canal` varchar(20) NOT NULL DEFAULT 'email',
  `destinatario` varchar(190) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_plano_vencimento_email` (`restaurante_id`,`data_fim`,`dias_restantes`,`canal`),
  KEY `idx_plano_vencimento_criado_em` (`criado_em`),
  KEY `idx_plano_vencimento_restaurante` (`restaurante_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planos_disponiveis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `planos_disponiveis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `nome_display` varchar(100) NOT NULL,
  `descricao` text DEFAULT NULL,
  `cor` varchar(20) DEFAULT '#6c757d',
  `icone` varchar(50) DEFAULT 'fa-star',
  `preco_mensal` decimal(10,2) DEFAULT 0.00,
  `preco_trimestral` decimal(10,2) DEFAULT 0.00,
  `preco_anual` decimal(10,2) DEFAULT 0.00,
  `limite_produtos` int(11) DEFAULT -1,
  `limite_usuarios` int(11) DEFAULT -1,
  `limite_mesas` int(11) DEFAULT -1,
  `limite_filiais` int(11) DEFAULT 0,
  `ordem` int(11) DEFAULT 0,
  `ativo` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `planos_sistema`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `planos_sistema` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `preco` decimal(10,2) NOT NULL,
  `duracao` enum('mensal','trimestral','anual') NOT NULL,
  `duracao_dias` int(11) NOT NULL,
  `descricao` text DEFAULT NULL,
  `recursos` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pontos_fidelidade`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pontos_fidelidade` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `restaurante_id` int(11) NOT NULL,
  `saldo_pontos` int(11) DEFAULT 0,
  `pontos_totais_ganhos` int(11) DEFAULT 0,
  `pontos_totais_gastos` int(11) DEFAULT 0,
  `nivel_cliente` enum('bronze','prata','ouro','platina') DEFAULT 'bronze',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_fidelidade` (`cliente_id`,`restaurante_id`),
  KEY `restaurante_id` (`restaurante_id`),
  KEY `cliente_id` (`cliente_id`,`restaurante_id`),
  CONSTRAINT `pontos_fidelidade_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pontos_fidelidade_ibfk_2` FOREIGN KEY (`restaurante_id`) REFERENCES `compras_planos` (`restaurante_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `produtos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `produtos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `nome` varchar(150) NOT NULL,
  `descricao` text DEFAULT NULL,
  `preco` decimal(10,2) NOT NULL DEFAULT 0.00,
  `custo` decimal(10,2) DEFAULT 0.00,
  `estoque` int(11) DEFAULT 0,
  `estoque_minimo` int(11) DEFAULT 5,
  `imagem` varchar(255) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  `destaque` tinyint(1) NOT NULL DEFAULT 0,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  `filial_id` int(11) DEFAULT NULL,
  `categoria` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `categoria_id` (`categoria_id`),
  KEY `idx_produtos_restaurante` (`restaurante_id`),
  KEY `idx_produto_filial` (`filial_id`),
  CONSTRAINT `produtos_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `produtos_ibfk_2` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `promocao_itens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promocao_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `promocao_id` int(11) NOT NULL,
  `produto_id` int(11) NOT NULL,
  `quantidade` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_promocao_produto` (`promocao_id`,`produto_id`),
  KEY `idx_promocao_itens_produto` (`produto_id`),
  CONSTRAINT `fk_promocao_itens_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`),
  CONSTRAINT `fk_promocao_itens_promocao` FOREIGN KEY (`promocao_id`) REFERENCES `promocoes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `promocoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promocoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `descricao` varchar(500) DEFAULT NULL,
  `preco_promocional` decimal(12,2) NOT NULL,
  `imagem` varchar(255) DEFAULT NULL,
  `inicio_em` datetime DEFAULT NULL,
  `fim_em` datetime DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `horarios_utc` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_promocoes_ativas` (`restaurante_id`,`ativo`,`inicio_em`,`fim_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reserva_historico`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reserva_historico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reserva_id` int(11) NOT NULL,
  `status_anterior` varchar(50) DEFAULT NULL,
  `status_novo` varchar(50) DEFAULT NULL,
  `motivo` text DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `reserva_id` (`reserva_id`),
  CONSTRAINT `reserva_historico_ibfk_1` FOREIGN KEY (`reserva_id`) REFERENCES `reservas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reserva_pre_bloqueios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reserva_pre_bloqueios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `mesa_id` int(11) NOT NULL,
  `token_sessao` varchar(96) NOT NULL,
  `data_reserva` date NOT NULL,
  `hora_reserva` time NOT NULL,
  `quantidade_pessoas` int(11) NOT NULL DEFAULT 1,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_restaurante_data_hora` (`restaurante_id`,`data_reserva`,`hora_reserva`),
  KEY `idx_token_sessao` (`token_sessao`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reserva_qr_sessoes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reserva_qr_sessoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `mesa_id` int(11) DEFAULT NULL,
  `token` varchar(96) DEFAULT NULL,
  `token_hash` varchar(128) DEFAULT NULL,
  `token_cipher` text DEFAULT NULL,
  `nome_atendente` varchar(120) DEFAULT NULL,
  `telefone_cliente` varchar(40) DEFAULT NULL,
  `observacoes` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_restaurante_expires` (`restaurante_id`,`expires_at`),
  KEY `idx_token_expires` (`token`,`expires_at`),
  KEY `idx_token_hash_expires` (`token_hash`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reservas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reservas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `nome_cliente` varchar(100) NOT NULL,
  `email_cliente` varchar(100) DEFAULT NULL,
  `telefone_cliente` varchar(20) DEFAULT NULL,
  `data_reserva` date NOT NULL,
  `hora_reserva` time NOT NULL,
  `quantidade_pessoas` int(11) NOT NULL,
  `observacoes` text DEFAULT NULL,
  `status` enum('pendente','confirmado','chegou','concluido','no-show','cancelado') DEFAULT 'pendente',
  `origem` enum('app','telefone','presencial') DEFAULT 'app',
  `confirmacao_whatsapp` tinyint(1) DEFAULT 0,
  `confirmacao_data` datetime DEFAULT NULL,
  `mesa_atribuida` int(11) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reserva` (`restaurante_id`,`data_reserva`,`hora_reserva`,`id`),
  KEY `mesa_atribuida` (`mesa_atribuida`),
  KEY `restaurante_id` (`restaurante_id`,`data_reserva`,`status`),
  KEY `fk_reservas_clientes_real` (`cliente_id`),
  CONSTRAINT `fk_reservas_clientes_real` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reservas_restaurantes_real` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reservas_ibfk_3` FOREIGN KEY (`mesa_atribuida`) REFERENCES `mesas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `restaurante_planos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `restaurante_planos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `plano_id` int(11) NOT NULL,
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `status` enum('ATIVO','CANCELADO','EXPIRADO') DEFAULT 'ATIVO',
  `tipo_plano` enum('TRIAL','PAGO','GRATIS') DEFAULT 'GRATIS',
  `valor_pago` decimal(10,2) DEFAULT 0.00,
  `observacoes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `plano_id` (`plano_id`),
  KEY `idx_restaurante_status` (`restaurante_id`,`status`),
  KEY `idx_data_fim` (`data_fim`),
  KEY `idx_restaurante_planos_periodo` (`restaurante_id`,`status`,`data_fim`),
  KEY `idx_status` (`status`),
  CONSTRAINT `restaurante_planos_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `restaurante_planos_ibfk_2` FOREIGN KEY (`plano_id`) REFERENCES `planos_disponiveis` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `restaurantes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `restaurantes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `email` text NOT NULL,
  `email_lookup` char(64) DEFAULT NULL,
  `telefone` text DEFAULT NULL,
  `telefone_lookup` char(64) DEFAULT NULL,
  `endereco` text DEFAULT NULL,
  `cidade` varchar(100) DEFAULT NULL,
  `nuit` text DEFAULT NULL,
  `nuit_lookup` char(64) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `documento_titularidade_path` varchar(255) DEFAULT NULL,
  `plano` enum('BASICO','PROFISSIONAL','ENTERPRISE','EMPRESARIAL') DEFAULT 'BASICO',
  `plano_id` int(11) DEFAULT NULL,
  `filial_id` int(11) DEFAULT NULL,
  `is_matriz` tinyint(1) DEFAULT 1,
  `status` enum('ATIVO','BLOQUEADO','CANCELADO') DEFAULT 'ATIVO',
  `data_inicio` date NOT NULL,
  `data_inicio_plano` date DEFAULT NULL,
  `data_fim` date NOT NULL,
  `data_fim_plano` date DEFAULT NULL,
  `status_plano` varchar(20) NOT NULL DEFAULT 'ATIVO',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `plano_duracao` enum('mensal','trimestral','anual') DEFAULT 'mensal',
  `created_at` datetime DEFAULT current_timestamp(),
  `senha_admin` varchar(255) DEFAULT NULL,
  `senha_criada_em` datetime DEFAULT NULL,
  `senha_pode_alterar` tinyint(1) DEFAULT 0,
  `cor_primaria` varchar(7) DEFAULT NULL,
  `cor_secundaria` varchar(7) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_restaurantes_email_lookup` (`email_lookup`),
  UNIQUE KEY `uq_restaurantes_nuit_lookup` (`nuit_lookup`),
  UNIQUE KEY `uq_restaurantes_telefone_lookup` (`telefone_lookup`),
  KEY `idx_filial_matriz` (`filial_id`,`is_matriz`),
  KEY `idx_restaurantes_plano_id` (`plano_id`),
  KEY `idx_restaurantes_validade` (`status_plano`,`data_fim`),
  KEY `idx_restaurantes_nuit_lookup` (`nuit_lookup`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schema_migrations` (
  `id` varchar(190) NOT NULL,
  `checksum` char(64) NOT NULL,
  `description` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_name` varchar(80) NOT NULL,
  `attack_type` varchar(40) NOT NULL DEFAULT 'SUSPEITO',
  `severity` enum('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
  `ip` varchar(64) DEFAULT NULL,
  `endpoint` varchar(255) DEFAULT NULL,
  `method` varchar(12) DEFAULT NULL,
  `payload_excerpt` varchar(600) DEFAULT NULL,
  `source_log` varchar(60) NOT NULL DEFAULT 'security.log',
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_security_events_created` (`created_at`),
  KEY `idx_security_events_type` (`attack_type`),
  KEY `idx_security_events_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `super_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `super_admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `criado_em` datetime DEFAULT current_timestamp(),
  `atualizado_em` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_ticket_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_ticket_messages` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) NOT NULL,
  `restaurante_id` int(11) NOT NULL,
  `author_tipo` enum('CLIENTE','SUPORTE','SISTEMA') NOT NULL DEFAULT 'CLIENTE',
  `author_usuario_id` int(11) DEFAULT NULL,
  `mensagem` text DEFAULT NULL,
  `anexo_nome` varchar(180) DEFAULT NULL,
  `anexo_path` varchar(255) DEFAULT NULL,
  `anexo_mime` varchar(120) DEFAULT NULL,
  `anexo_size` int(11) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_support_msg_ticket` (`ticket_id`,`criado_em`),
  KEY `idx_support_msg_rest` (`restaurante_id`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `support_tickets` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `plano_codigo` varchar(40) NOT NULL,
  `ticket_hash` varchar(64) NOT NULL,
  `canal` enum('DASHBOARD','WHATSAPP','EMAIL') NOT NULL DEFAULT 'DASHBOARD',
  `prioridade` enum('BAIXA','MEDIA','ALTA','URGENTE') NOT NULL DEFAULT 'ALTA',
  `status` enum('ABERTO','EM_ANDAMENTO','RESOLVIDO','FECHADO') NOT NULL DEFAULT 'ABERTO',
  `assunto` varchar(160) NOT NULL,
  `descricao` text NOT NULL,
  `resposta_admin` text DEFAULT NULL,
  `respondido_por` int(11) DEFAULT NULL,
  `respondido_em` datetime DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_support_ticket_hash` (`ticket_hash`),
  KEY `idx_support_rest_status` (`restaurante_id`,`status`,`criado_em`),
  KEY `idx_support_status_prio` (`status`,`prioridade`,`criado_em`),
  KEY `idx_support_user` (`usuario_id`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transacoes_pontos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transacoes_pontos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pontos_id` int(11) NOT NULL,
  `tipo` enum('ganho','gasto','ajuste') DEFAULT 'ganho',
  `quantidade` int(11) DEFAULT NULL,
  `motivo` varchar(200) DEFAULT NULL,
  `referencia_venda` int(11) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `pontos_id` (`pontos_id`,`criado_em`),
  CONSTRAINT `transacoes_pontos_ibfk_1` FOREIGN KEY (`pontos_id`) REFERENCES `pontos_fidelidade` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `uploads_comprovantes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `uploads_comprovantes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `compra_id` int(11) NOT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `caminho` varchar(255) NOT NULL,
  `tamanho` int(11) NOT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `compra_id` (`compra_id`),
  CONSTRAINT `uploads_comprovantes_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras_planos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL DEFAULT 1,
  `filial_id` int(11) DEFAULT NULL,
  `nome` varchar(100) NOT NULL,
  `email` text NOT NULL,
  `email_lookup` char(64) DEFAULT NULL,
  `senha` varchar(255) NOT NULL,
  `perfil` enum('ADMIN','CAIXA','GARCOM','COZINHA','BAR','GERENTE') NOT NULL DEFAULT 'GARCOM',
  `ativo` tinyint(1) DEFAULT 1,
  `super_admin` tinyint(1) DEFAULT 0,
  `foto` varchar(255) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  `ultimo_acesso` datetime DEFAULT NULL,
  `tentativas_login` int(11) NOT NULL DEFAULT 0,
  `bloqueado_ate` datetime DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_email` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_email_lookup` (`email_lookup`),
  KEY `restaurante_id` (`restaurante_id`),
  KEY `idx_filial` (`filial_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vendas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `restaurante_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `caixa_id` int(11) NOT NULL,
  `mesa_id` int(11) DEFAULT NULL,
  `numero_fatura` varchar(30) NOT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `desconto` decimal(10,2) DEFAULT 0.00,
  `total_final` decimal(10,2) NOT NULL DEFAULT 0.00,
  `forma_pagamento` enum('DINHEIRO','MPESA','EMOLA','CARTAO','TRANSFERENCIA') DEFAULT 'DINHEIRO',
  `valor_recebido` decimal(10,2) DEFAULT NULL,
  `troco` decimal(10,2) DEFAULT NULL,
  `status` enum('PAGO','PENDENTE','CANCELADO') DEFAULT 'PAGO',
  `observacao` text DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  `filial_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendas_restaurante_fatura` (`restaurante_id`,`numero_fatura`),
  KEY `usuario_id` (`usuario_id`),
  KEY `mesa_id` (`mesa_id`),
  KEY `idx_vendas_restaurante` (`restaurante_id`),
  KEY `idx_vendas_data` (`criado_em`),
  KEY `idx_venda_filial` (`filial_id`),
  KEY `vendas_ibfk_3` (`caixa_id`),
  CONSTRAINT `vendas_ibfk_1` FOREIGN KEY (`restaurante_id`) REFERENCES `restaurantes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vendas_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `vendas_ibfk_3` FOREIGN KEY (`caixa_id`) REFERENCES `caixas` (`id`),
  CONSTRAINT `vendas_ibfk_4` FOREIGN KEY (`mesa_id`) REFERENCES `mesas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

