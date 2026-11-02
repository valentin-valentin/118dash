-- Historique de l'assistant Claude du panel (une ligne par conversation).
-- messages : l'échange complet tel qu'envoyé à l'API (questions, réponses, requêtes SQL et résultats).
CREATE TABLE IF NOT EXISTS `assistant_conversations` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `user_id` bigint unsigned DEFAULT NULL,
    `title` varchar(255) NOT NULL,
    `messages` longtext NOT NULL,
    `input_tokens` int unsigned NOT NULL DEFAULT 0,
    `cache_read_tokens` int unsigned NOT NULL DEFAULT 0,
    `cache_write_tokens` int unsigned NOT NULL DEFAULT 0,
    `output_tokens` int unsigned NOT NULL DEFAULT 0,
    `cost_usd` decimal(10,4) NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `assistant_conversations_updated_at_index` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
