-- Utilisateur MySQL en lecture seule pour l'assistant Claude du panel.
-- Lecture seule (SELECT), sans accès aux tables techniques (users, sessions, jobs, cache, tokens…)
-- ni aux colonnes sensibles (PIN, mots de passe, tokens fournisseurs, clés API des sources, IBAN/PayPal,
-- payloads bruts). Les tables à colonnes exclues sont accordées colonne par colonne : une colonne
-- ajoutée plus tard à ces tables ne sera visible qu'après avoir relancé ce script.
-- Remplacer MOT_DE_PASSE, puis renseigner DB_ASSISTANT_USERNAME / DB_ASSISTANT_PASSWORD dans le .env.
-- Si MySQL n'est pas sur la même machine que le panel, remplacer 127.0.0.1 par l'IP du panel.

CREATE USER IF NOT EXISTS 'assistant_ro'@'127.0.0.1' IDENTIFIED BY 'MOT_DE_PASSE' WITH MAX_USER_CONNECTIONS 5;
ALTER USER 'assistant_ro'@'127.0.0.1' IDENTIFIED BY 'MOT_DE_PASSE' WITH MAX_USER_CONNECTIONS 5;
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'assistant_ro'@'127.0.0.1';

GRANT SELECT ON `118`.`agent_attendances` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`agent_console_logs` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`agent_logs` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`agent_network_quality` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`agent_ratings` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT (`id`, `callcenter_id`, `enabled`, `name`, `lang`, `theme`, `image`, `icon`, `voxnode_username`, `last_ping_at`, `online`, `active_call_id`, `break_requested_at`, `break_active_at`, `break_type`, `logout_requested_at`, `created_at`, `updated_at`, `sip_current_platform`, `sip_registered`, `sip_in_call`, `sip_switch_failed`, `sip_switch_failed_at`, `sip_platform_pin`, `net_http_ms`, `net_measured_at`, `net_sip_ms`, `net_sip_measured_at`) ON `118`.`agents` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`assignment_history` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`blacklist` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`brands` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`call_quality` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`call_ratings` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`call_weblogs` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT (`id`, `enabled`, `name`, `lang`, `manager_lang`, `manager_theme`, `manager_timezone`, `created_at`, `updated_at`) ON `118`.`callcenters` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`calls` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`companies` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT (`id`, `phonenumber_id`, `blacklist_id`, `call_sid`, `from`, `to`, `call_status`, `direction`, `forwarded_from`, `caller_name`, `caller_country`, `caller_city`, `caller_state`, `caller_zip`, `called_at`, `created_at`, `updated_at`) ON `118`.`disabled_calls` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`email_calls` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`email_message_attachments` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`email_messages` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`email_phonenumbers` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`email_refunds` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`emails` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`individual_searches` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`js_snippet_logs` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`js_snippets` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`monitoring_checks` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`monitorings` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`package_carriers` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`package_tracking_updates` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`package_trackings` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`phonenumbers` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT (`id`, `color`, `enabled`, `name`, `driver`, `sip_number_format`, `payout`, `created_at`, `updated_at`) ON `118`.`providers` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT (`id`, `provider_id`, `company_id`, `payout`, `created_at`, `updated_at`) ON `118`.`providers_companies` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`refund_phonenumbers` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT (`id`, `called`, `requested`, `requested_at`, `validated`, `validated_at`, `refunded`, `refunded_at`, `refund_method`, `refund_calls`, `refund_amount`, `created_at`, `updated_at`) ON `118`.`refunds` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT (`id`, `phonenumber_id`, `source_id`, `company_id`, `provider_id`, `endpoint`, `reason`, `status`, `message`, `duration_ms`, `attempt`, `job_id`, `created_at`, `updated_at`) ON `118`.`routing_logs` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`settings` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`source_payments` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT (`id`, `color`, `name`, `fingerprint`, `only_dedicated_phonenumber`, `max_concurrent_numbers`, `display_duration_minutes`, `real_duration_minutes`, `payout_call`, `payout_minute`, `solde`, `created_at`, `updated_at`) ON `118`.`sources` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`sources_providers_companies` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`visitor_assignments` TO 'assistant_ro'@'127.0.0.1';
GRANT SELECT ON `118`.`zipcodes` TO 'assistant_ro'@'127.0.0.1';

FLUSH PRIVILEGES;
