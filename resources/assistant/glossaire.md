Règles de calcul utilisées par les pages du panel. Les valeurs marquées « observé » sont des constats faits sur les données à un moment donné, pas des règles : vérifie-les si elles comptent pour la réponse.
Les pages du panel comportent quelques incohérences de fuseau (signalées ci-dessous) : pour toute nouvelle analyse, raisonne en heure de Paris.

---

## Vocabulaire des gérants

| Ils disent | En base |
|---|---|
| **Voxnode** (plateforme Voxnode) | `platform IS NULL OR platform = 'voxnode'`, c'est-à-dire tout ce qui n'est pas `'selfhosted'` : `(platform IS NULL OR platform <> 'selfhosted')` |
| **Notre serveur** (selfhosted, PBX, Asterisk, sip.118.ae) | `platform = 'selfhosted'` |
| **Backup** (service interne, 01 84 13 15 72) | transferts vers le service interne : `duration_transfer > 0 AND RIGHT(brand_phonenumber, 9) = '184131572'`. Sur notre serveur, un transfert vers une marque qui n'a pas abouti part aussi au backup : il est enregistré avec ce numéro (`brand_name` garde la marque choisie par l'agent). |
| **Transfert vers une marque** | `duration_transfer > 0 AND brand_phonenumber <> '' AND RIGHT(brand_phonenumber, 9) <> '184131572'` |

## 0. Pièges globaux (à lire en premier)

| Piège | Règle |
|---|---|
| Fuseau | `calls.called_at` (et tous les `*_at`) est un `TIMESTAMP`. Ta session MySQL est déjà en UTC (`time_zone = '+00:00'`) : les valeurs lues sont en UTC. |
| CONVERT_TZ | Les tables de fuseaux MySQL peuvent ne pas être chargées : `CONVERT_TZ(x,'+00:00','Europe/Paris')` renvoie alors **NULL**. Teste-le une fois ; sinon utilise des bornes UTC calculées à l'avance ou la formule du §9. |
| Centre d'appels | Il se déduit de **`calls.agent_name`** (préfixe ou nom exact, §3). `calls.callcenter_id`, `calls.agent_id`, `agents.callcenter_id` et la table `callcenters` sont **obsolètes**. Les filtres `callcenter_id` du Dashboard et de la page Appels les utilisent encore. |
| Numéro → company/provider/source | Le panel fait la jointure `phonenumbers.phonenumber = calls.called`, **numéros supprimés compris** (`withTrashed`), sauf sur la page Partenaires. Il n'utilise **pas** `calls.phonenumber_id`, `calls.company_id` ni `calls.provider_id`, qui existent pourtant (observé : environ 300 écarts sur 30 jours). 4 numéros sont en double dans `phonenumbers` : une jointure peut compter un appel deux fois. |
| Source d'un appel | `COALESCE(calls.source_id, phonenumbers.source_id)` (affichage, export, filtres). Exception : soldes et réconciliation utilisent **seulement `calls.source_id`**. |
| Agent vide | Un appel perdu a `agent_name = ''` (chaîne vide), rarement NULL. Observé : 137 k appels avec `''` sur 180 jours. |
| Appels de test | `is_test = 1` = appels du testeur de softphone, décrochés par les comptes test01 / test02. **Toutes les pages du panel les excluent** : toujours ajouter `is_test = 0` sur `calls`. |
| Appels IA | `agent_name = 'ai'` = tests d'agents IA. Seule la page **Attente et pertes** les exclut : `(agent_name IS NULL OR agent_name <> 'ai')`. Le Dashboard ne les exclut pas. La page Performance les écarte de fait, car `'ai'` ne correspond à aucun centre. |
| Soft deletes | `phonenumbers`, `agent_logs` et `agent_attendances` ont `deleted_at`. Eloquent ajoute `deleted_at IS NULL` par défaut ; les requêtes `DB::table` ne le font pas. |
| Montants | Il n'y a **aucune conversion HT/TTC** dans le code. Les montants sont en € tels que stockés. |
| Durées | Toutes en **secondes** (`int`). |

---

## 1. Appel : définitions (table `calls`)

| Terme | Définition SQL | Où |
|---|---|---|
| Appel | 1 ligne `calls` (appel abouti chez nous ; un rejet est dans `disabled_calls`, §5) | partout |
| Décroché | `duration_agent > 0` | WaitLoss |
| Perdu | WaitLoss : `COALESCE(duration_agent,0) <= 0`. Dashboard (colonne « Perdus ») : `SUM(COALESCE(duration_agent,0) = 0)`. Libellé : « le client a raccroché avant qu'un agent décroche » | WaitLoss, Dashboard |
| Taux de perte | `lost / calls` (Dashboard : `lost_calls / calls * 100`, 1 décimale) | |
| Durée totale | `total_duration` | |
| Durée agent | `duration_agent` (agent en ligne, conversation + pauses/mises en attente côté agent) | |
| Durée transfert | `duration_transfer` (après transfert vers la marque) ; transféré ⇔ `duration_transfer > 0`. Notre serveur : un client qui raccroche pendant la sonnerie de la marque donne `duration_transfer = 0` ; un transfert vers la marque qui n'aboutit pas est enregistré comme service interne (`brand_phonenumber = +33184131572`, `brand_name` reste la marque choisie) | |
| Attente (Performance) | `total_duration - duration_agent - duration_transfer`, **sans correction** ; `wait_s = SUM(total_duration - duration_agent - duration_transfer)` | Performance |
| Anomalie | `SUM(total_duration < duration_agent + duration_transfer)` (attente négative, laissée dans les sommes) | Performance |
| Attente avant décroché (WaitLoss) | appels décrochés : `GREATEST(0, total_duration - duration_agent - duration_transfer)` ; médiane et moyenne (arrondies à l'entier) | WaitLoss |
| Attente avant abandon | appels perdus : `total_duration` ; tranches `[0,5) [5,30) [30,60) [60,120) [120,180) [180,300) [300,+∞)` s | WaitLoss |
| Conversation / pause | `phone_duration_agent` (parlé) et `phone_duration_pause` (pause), renseignés seulement si le softphone a le détail (`phone_calls = COUNT(phone_duration_agent)`). Part parlée = `SUM(phone_duration_agent) / (SUM(phone_duration_agent)+SUM(phone_duration_pause))`, appliquée à `SUM(duration_agent)` : conversation = `agent_s × part`, pause = `agent_s × (1-part)` | Performance |
| Minutes parlées | `SUM(duration_agent) × part_parlée / 60` (pause et transfert exclus) | Performance |
| Durée moyenne | `AVG(total_duration)`. Totaux (tableau jour par jour, par company, par heure) = `SUM(total_duration)/COUNT(*)`, moyenne de tous les appels | Dashboard |
| Durée minimale « ≥ 10 s » | `total_duration >= 10`. Filtre **optionnel** du Dashboard (`min_duration=true`), **toujours appliqué** sur la page Partenaires | Dashboard, Partenaires |
| Facturable / comptabilisé | Aucune notion explicite dans le code. Le plus proche : les appels partenaires `total_duration >= 10`. Observé : les appels < 10 s ont un payout moyen de 0,03 € (contre 5,87 € au-delà) | — |
| Qui raccroche | `who_hangup` ∈ `caller`, `callee`, `undefined`, `call rejected`, `''` (observé) | filtre Appels |
| Enregistrements | `record_agent`, `record_transfer` (URL) | |
| Opérateur appelant | `carrier` : codes `BOUY`=Bouygues, `FREE`/`FRMO`=Free, `FRTE`=Orange, `SFR0`=SFR (CarrierHelper), les autres codes bruts | |
| Marque | `brand_name`, `brand_phonenumber` (→ `brands.phonenumber`) | |
| Formats | `caller` / `called` en `+33…`. Service interne des transferts = numéro de marque `+33184131572` (01 84 13 15 72). Un `agent_name` de forme `+33118500999@…` désigne le service interne 118 500 côté agents | observé |

### forced_wait / queue_wait (notre serveur uniquement)
- `forced_wait` : attente forcée imposée (s). `queue_wait` : vraie attente faute d'agent (s). Renseignés seulement pour `platform='selfhosted'`. Le commentaire du code dit « depuis fin octobre 2026 » (migration du 2026-10-31) ; observé : renseignés sur les appels selfhosted depuis le 2026-10-01.
- Détail WaitLoss, sur `platform='selfhosted' AND forced_wait IS NOT NULL AND queue_wait IS NOT NULL` :
  - décroché : temps d'attente = `queue_wait` ; « décroché en ≤ 30 s » : `queue_wait <= 30` (idem ≤ 60) ;
  - perdu avec `queue_wait > 0` → **lost_queue** (« faute d'agent ») ;
  - sinon perdu avec `forced_wait > 0` → **lost_forced** (« pendant l'attente forcée ») ;
  - sinon perdu → **lost_welcome** (« pendant l'accueil ») ;
  - médiane de l'attente forcée = médiane de `forced_wait` sur les appels où `forced_wait > 0`.

### Plateforme
- `platform = 'selfhosted'` → notre serveur Asterisk (sip.118.ae) ; `platform IS NULL` (ou toute autre valeur) → **Voxnode**.
- SQL : `CASE WHEN platform='selfhosted' THEN 'selfhosted' ELSE 'voxnode' END`. Filtre Voxnode : `(platform IS NULL OR platform <> 'selfhosted')`.
- Observé : selfhosted depuis le 2026-10-01. Export CSV : « notre serveur » / « voxnode ».

### Qualité audio
- `calls.audio_quality` `decimal(2,1)` = score MOS 1–5 estimé par le softphone agent (stats WebRTC). NULL = non mesuré.
- Mesurables (« eligible ») : `duration_agent >= 5`. Mesurés : `audio_quality IS NOT NULL`. Couverture = mesurés / appels.
- MOS moyen = `SUM(audio_quality)/COUNT(audio_quality)`, 2 décimales. On recalcule toujours depuis les sommes, jamais en moyennant des moyennes.
- **Dégradé : `audio_quality < 3.1`**.
- Barème : ≥ 4,3 excellent ; [4,0 ; 4,3) très bon ; [3,6 ; 4,0) correct ; [3,1 ; 3,6) médiocre ; < 3,1 mauvais.
- **MAX_MOS = 4,4** (appel parfait = 100 %). Pourcentage affiché : `LEAST(100, ROUND(mos/4.4*100))`, au format « 97 % (4,27) ».
- Cause réseau : `call_quality` (1 ligne par appel, `call_quality.call_id = calls.id`, LEFT JOIN) : `packets_received`, `packets_lost`, `loss_pct`, `jitter_max_ms`, `jitter_median_ms`, `sent_packets(_lost)`, `sent_loss_pct`, `rtt_avg_ms`, `rtt_max_ms`, `codec`, `samples`, `platform` (`voxnode`/`selfhosted`).
- Top agents dégradés : `GROUP BY agent_name` sur les appels mesurés, `SUM(audio_quality<3.1) AS degraded HAVING degraded>0`, les 5 premiers.

---

## 2. Argent

| Terme (libellé page) | Colonne / formule | Notes |
|---|---|---|
| **CA** (chiffre d'affaires) = payout | `COALESCE(SUM(payout),0)` | `calls.payout decimal(15,5)`, calculé par l'API (`payout_computed`=1). Ce que nous rapporte l'appel |
| **Reverse** = payout_source | `COALESCE(SUM(payout_source),0)` | `decimal(15,2)`, reversé à la source de trafic (`payout_source_computed`). NULL pour les appels sans source (observé : environ 63 % de NULL sur l'historique) |
| **Bénéfice** | `COALESCE(SUM(COALESCE(payout,0) - COALESCE(payout_source,0)),0)` | Dashboard, tableaux company/marque (tri par défaut) |
| Coût mensuel d'un centre | `config/callcenters.php` → `costs` : Madagascar 13 000 €, Madagascar Remote 0 €, Lamia 10 000 €, Maroc 400 € (effet `2020-01`). Pour un mois M : dernière entrée dont la clé `YYYY-MM` ≤ M | n'existe pas en base |
| Coût alloué sur une période | mois entier : coût mensuel ; mois partiel : `coût_mensuel × jours_couverts / jours_du_mois` (jours de Paris), marqué « estimé ». Somme sur les mois. NULL si aucune entrée | Performance |
| Coût d'un agent | `coût_centre × jours_actifs_agent / journées_agent_du_centre` | Performance (prorata jours) |
| **Coût sources** (Performance) | `COALESCE(SUM(payout_source),0)` | mêmes appels que le payout (global, centre, agent, jour) |
| **Marge nette** (Performance) | `payout - payout_source - coût_centre` | marge mise en avant. Pour un agent : coût d'agent (prorata jours) |
| Taux de marge nette | `marge_nette / payout` | |
| Marge nette par appel | `marge_nette / appels` | |
| **Marge après coût centre (hors sources)** (Performance) | `payout - coût_centre` | ancienne « Marge » : **ne déduit pas payout_source** (≠ Bénéfice du Dashboard) |
| Taux de marge (hors sources) | `marge_hors_sources / payout` | |
| Payout / coût | `payout / coût_centre` | |
| Coût par appel, marge par appel (hors sources) | `coût_centre / appels`, `marge_hors_sources / appels` | |
| Payout / appel | `payout / appels` | |
| Payout / heure agent | `payout / (SUM(duration_agent)/3600)` | |
| Payout / journée-agent | `payout / agent_days` | |
| **Coût par minute parlée** | `coût / minutes_parlées` (§1) | |
| Tarif RSVA attendu | code `D080` = 0,80 €/min (audit RSVA) | aucun calcul de CA ne l'utilise |
| Tarifs de référence (configuration API, jamais recalculés par le panel) | `providers.payout`, `providers_companies.payout`, `sources_providers_companies.payout`, `sources.payout_call` (€/appel, observé 3,00), `sources.payout_minute` | unité de `providers.payout` (25,80–28,20) non documentée |

Variation affichée partout : `(cur - prev) / prev × 100`, 1 décimale, NULL si `prev <= 0` (Performance : `/ ABS(prev)`).

### Soldes des sources (`sources.solde`, `source_payments`)
- `source_payments.type` ∈ `credit`, `debit` ; `amount > 0` ; `created_at` = date du mouvement.
- Saisie manuelle : `credit` → `solde += amount` ; `debit` → `solde -= amount`. Un transfert entre sources crée 1 `debit` (« Transfert vers X ») + 1 `credit` (« Transfert depuis Y »).
- **Solde attendu** = `SUM(calls.payout_source WHERE calls.source_id = S) + SUM(credit) - SUM(debit)`. Sans filtre de date, de durée ni de fallback numéro. Concorde si `ABS(attendu - sources.solde) < 0.01`. Le bouton « sync » écrit l'attendu dans `sources.solde`.
- Le solde est donc le montant cumulé dû à la source : les appels l'augmentent, un `debit` (paiement versé) le diminue.
- Solde à un instant T (page Partenaires `?at=`) : `solde_actuel - SUM(payout_source après T) - SUM(credit après T) + SUM(debit après T)`.
- Dernier paiement : `MAX(source_payments.created_at)`.

---

## 3. Attribution call center / agent (`config/callcenters.php`)

Ordre d'affichage : madagascar, madagascar_remote, lamia, maroc.

| Clé | Libellé | Règle | Couleur |
|---|---|---|---|
| `madagascar` | Madagascar | préfixe `dedi` | #2a78d6 |
| `madagascar_remote` | Madagascar Remote | noms exacts `dedi005`, `dedi015`, **optional** : seulement si la case « Séparer Madagascar Remote » est cochée (`split_remote=1`), sinon ils restent dans Madagascar | #eda100 |
| `lamia` | Lamia | préfixe `prepose` | #eb6834 |
| `maroc` | Maroc | nom exact `prepose9TEST` | #1baf7a |

Algorithme : on teste les noms exacts d'abord, puis le préfixe le plus long. Aucun centre → NULL (« non attribué »).
SQL équivalent (`split_remote` = 0) :
```sql
CASE WHEN calls.agent_name = 'prepose9TEST' THEN 'maroc'
     WHEN calls.agent_name LIKE 'prepose%' THEN 'lamia'
     WHEN calls.agent_name LIKE 'dedi%'    THEN 'madagascar' END
```
Avec `split_remote` = 1, ajouter en tête `WHEN calls.agent_name IN ('dedi005','dedi015') THEN 'madagascar_remote'`.
- Agent = `calls.agent_name` (nom de ligne : dedi003, prepose995…). `agents.voxnode_username = calls.agent_name` (= `agents.name`) permet la jointure vers `agents`.
- Non attribués : `CASE … END IS NULL` → `COUNT(*)`, `SUM(payout)` affichés à part (« appels sans agent dedi… ou prepose… »). Cela inclut les appels perdus (`agent_name=''`), `'ai'` et `+33118500999@…` (observé).
- Collation `utf8mb4_unicode_ci` : en SQL, `=` et `LIKE` ignorent la casse ; le code PHP (`centerLabel`) est sensible à la casse.
- `callcenters` (observé : CC1, Lyscom, Lamia, InHouse, CC5, CC6, Sante) et `calls.callcenter_id` sont obsolètes.

### Agrégats de la page Performance (toutes les sommes)
```sql
COUNT(*) calls, COALESCE(SUM(payout),0) payout, COALESCE(SUM(payout_source),0) payout_source,
COALESCE(SUM(total_duration),0) total_s,
COALESCE(SUM(duration_agent),0) agent_s, COALESCE(SUM(duration_transfer),0) transfer_s,
COALESCE(SUM(total_duration-duration_agent-duration_transfer),0) wait_s,
COALESCE(SUM(phone_duration_agent),0) talk_s, COALESCE(SUM(phone_duration_pause),0) pause_s,
COUNT(phone_duration_agent) phone_calls, SUM(total_duration < duration_agent+duration_transfer) anomalies,
COUNT(audio_quality) quality_calls, COALESCE(SUM(audio_quality),0) quality_sum,
COALESCE(SUM(audio_quality < 3.1),0) quality_degraded
```
- Agents actifs : `COUNT(DISTINCT agent_name)`. **Journées-agent** : `COUNT(DISTINCT agent_name, date_paris)`. Jours actifs : `COUNT(DISTINCT date_paris)`. Heures-agent (vue horaire) : `COUNT(DISTINCT agent_name, DATE(paris))` par heure.
- Agents par jour = `agent_days/days` ; appels par jour = `calls/days` ; appels par journée-agent = `calls/agent_days`.
- Ratios : temps agent = `agent_s/total_s`, transfert = `transfer_s/total_s`, attente = `wait_s/total_s`. Moyennes = somme/appels.
- Aucun filtre de durée ni d'exclusion, hormis `centre IS NOT NULL`. Jours = jours calendaires de Paris ; bornes `[from 00:00 Paris, to 23:59:59.999 Paris]` en UTC.

---

## 4. Pages et filtres par défaut

### Dashboard (`DashboardController`)
- Filtres communs (multi-sélection, séparés par des virgules) : `brand_name IN`, `agent_name IN`, `callcenter_id IN` (obsolète), `carrier IN`, `platform`, `provider_id`/`company_id` via `EXISTS phonenumbers (withTrashed) WHERE phonenumber = calls.called`, `source_id` : `(calls.source_id IN (…) OR (calls.source_id IS NULL AND phonenumbers.source_id IN (…)))`, `min_duration` → `total_duration >= 10` (sauf chartData et brandDistribution, qui n'ont pas ce filtre).
- **KPI du haut** (`stats`) : périodes `today`, `this_week` (depuis lundi), `this_month`, comparées à hier ou S-1 à la même heure, à la semaine -1 et -2, au mois -1 et -2 à jour égal. **Bornes en UTC, pas en heure de Paris.** Aucun filtre.
- **Graphique** (`chartData`) : mois courant et précédent, `GROUP BY DATE(called_at)` (date UTC), bornes UTC.
- **Répartition par marque** : `brand_name` non vide, `HAVING COUNT(*) >= 5`, tri par bénéfice, bornes de mois UTC. Compteur = `COUNT(DISTINCT brand_name)`.
- **Tableau jour par jour** (`dailyBreakdown`) : jours de Paris, du 1er du mois jusqu'à aujourd'hui (aujourd'hui s'arrête à maintenant). Colonnes : appels, CA, Reverse, Bénéfice, durée totale, durée moyenne, qualité (`COUNT(audio_quality)`, MOS, dégradés), perdus (option), rejetés (option `with_rejected`, §5). Comparaison avec J-7. Totaux = somme des jours ; durée moyenne = `SUM(total_duration)/COUNT(*)` sur le mois. Mois précédent comparé jusqu'au même jour du mois. Bugs : la borne de fin J-7 et les bornes du mois précédent ne sont pas converties en UTC, d'où un décalage de 1–2 h.
- % rejetés = `rejetés / (appels + rejetés) × 100`.
- **Par company** : `LEFT JOIN phonenumbers ON phonenumbers.phonenumber = calls.called` (numéros supprimés inclus), `LEFT JOIN companies`, `WHERE phonenumbers.company_id IS NOT NULL`, `GROUP BY company_id`, même fenêtre que le tableau jour par jour, comparaison avec le mois précédent à jour égal.
- **Par heure** : heures de Paris 8 h–21 h (chaque heure `HH:00:00–HH:59:59`), comparées à J-7 ; total du jour sur 0 h–24 h de Paris.
- **Qualité audio du mois / appels dégradés** : mois de Paris (jusqu'à maintenant), mêmes filtres ; le filtre agent est un préfixe (`agent_name LIKE 'dedi01%'`).

### Appels (`CallController`)
- Pas de filtre par défaut (tous les appels, tri `called_at DESC`).
- `date_from` / `date_to` : `DATE(called_at)` **dans le fuseau de session (UTC)**, pas Paris.
- Filtres : `caller LIKE`, `called LIKE`, `total_duration` min/max, `who_hangup IN`, `has_rating` (`ratings_note IS [NOT] NULL`), `ratings_warning`, `ratings_danger`, `ratings_not_rated`, `ratings_reviewer IN`, `phone_agent_hangup`, `phone_reported_by_agent`, `phone_cant_provide_service`, `audio_quality` (`degraded` <3.1 / `measured` / `unmeasured`), `blacklist_id` (`any` = NOT NULL, `none` = NULL, ou un id).
- Compteurs : aujourd'hui, semaine, mois, durée moyenne du mois, tous en bornes UTC.

### Attente et pertes (`WaitLossController`)
- Jours de Paris, bornes via `FROM_UNIXTIME(epoch)` (indépendant du fuseau de session), regroupement heure/jour en Paris.
- **Exclut `agent_name = 'ai'`.** Groupes : `all`, `voxnode`, `selfhosted`. Pas de filtre de durée.

### Appels à vérifier (`CallReviewController`, `/calls-to-review`)
- Jours de Paris sur `called_at` (bornes converties en UTC) : aujourd'hui, hier, 7 jours, 30 jours (aujourd'hui compris). Appels de test exclus. Filtre agent = `agent_name` exact.
- Un appel par ligne, avec tous ses cas. **Signalé par l'agent** : `phone_reported_by_agent = 1` (motif `phone_reported_by_agent_reason`). **Raccroché par l'agent** : `phone_agent_hangup = 1` (motif `phone_agent_hangup_reason`). **Liste noire** : `blacklist_id IS NOT NULL` (motif `blacklist.note`). **Transfert contre la suggestion** : `transfer_against_advice = 1` (colonne absente avant la migration voxnode118 : cas masqué) : Backup suggéré (premier appel du client pour la marque), l'agent a choisi la marque `brand_name`. « (renvoyé au service interne) » si `brand_phonenumber` se termine par les 9 derniers chiffres du numéro de la marque `BACKUP` (marque injoignable par notre serveur, ex. numéros courts Free). **Rappel vers la marque** : `transfer_recall_to_brand = 1` (le client avait déjà appelé pour cette marque dans les 48 h, Backup était proposé d'abord, l'agent a transféré vers la marque ; seulement si la marque et Backup étaient transférables ; colonne absente avant la migration voxnode118 : cas masqué). **Audio mauvais** : `audio_quality < 3.6`.
- Nombre de chaque cas : sur la période et l'agent choisis, quels que soient les cas cochés.
- **Pastille rouge du menu** « Appels à vérifier » : appels signalés par l'agent aujourd'hui (jour de Paris sur `called_at`, tests exclus), le même nombre que la case « Signalé par l'agent » sur « Aujourd'hui ».
- **Par agent** : tous les agents de la période (même avec un agent choisi). Nombre de chaque cas, **à vérifier** = appels avec au moins un des cas cochés (tri décroissant), **appels décrochés** = `phone_answered_at` non nul, même période (`called_at`), tests exclus.

### Alertes agents (`AgentAlertController`, `/agent-alerts`)
- Table `agent_logs`, jours de Paris sur `created_at` (UTC), bornés par une plage d'id (recherche dichotomique sur la clé primaire, gardée 1 h). Centre de l'agent par son nom (`config/callcenters.php`, centres non optionnels) ; comptes `test` + chiffres (testeur de softphone) dans le centre « Test », inclus dans « Tous ».
- **Rechargé pendant une sonnerie** (rouge, volontaire) : `danger` / `reload_during_ring` avec `value.key` non nul (touche, affichée « Touche F5 », « Touche Ctrl+R ») ou `key` nul et `left_page` vrai (« Bouton du navigateur »). **Plantage du navigateur** (problème de poste) : `key` nul et `left_page` faux. `value.ring_s` = secondes de sonnerie avant le rechargement. « (PIN demandé) » : un `call_timeout` `reload` du même agent à 2 min près.
- **Appel manqué** : `danger` / `call_timeout`, `value` NULL (softphone) ou `server` (file de notre serveur). `value = 'reload'` n'est pas compté : c'est le même événement que le rechargement.
- **Échec de fin** : `warning` / `end_work_failed` ou `end_break_failed`.
- Classement par agent : rechargements, puis plantages, appels manqués, échecs de fin. Appels décrochés = `calls.agent_id` avec `phone_answered_at` dans la période (pas `called_at`, rempli seulement à l'import et jamais pour le testeur), bornés par `id > MAX(id) − 15 000 × jours`, appels de test compris (ceux des comptes de test).

### Marques demandées (`BrandRequestController`, `/brands/requests`)
- Demandes `brand_requests` en attente, regroupées par `name_key`. Ordre de la liste « À traiter » (et de « passer à la suivante ») : d'abord celles dont ChatGPT a déjà cherché le numéro (`brand_number_searches.status = 'done'`), puis le plus d'appels, puis la dernière demande la plus ancienne.
- **Numéro trouvé** (`brand_number_searches`, voxnode118, une ligne par `name_key`) : `found` = au moins un numéro +33 gardé ; `suggestions` = jusqu'à 5 numéros, le meilleur d'abord (le premier pré-remplit le numéro de transfert) ; pas de ligne = pas encore cherché ; `status = 'failed'` = recherche impossible.

### Partenaires (`PartnerStatsController`, URL `/partners/{ids}/{hash}`)
- Sources limitées à la liste de l'URL. Appel retenu : `(calls.source_id IN (…) OR (calls.source_id IS NULL AND phonenumbers.source_id IN (…)))`. Ici **sans** les numéros supprimés (jointure sans `withTrashed` : `phonenumbers.deleted_at IS NULL`).
- **Toujours `total_duration >= 10`.**
- Seuls indicateurs : `COUNT(*)` et `SUM(payout_source)` (« reverse »). Pas de CA.
- Jour : jours de Paris, comparés au même jour du mois précédent. Heure : 8 h–21 h de Paris, comparée à J-7. Mois : année de Paris, comparée au même mois de l'année précédente.
- `?at=` (heure de Paris) : `called_at <= at` et `source_payments.created_at <= at`.

### Performance call centers : voir §3. CSV : niveaux `centre`, `agent`, `centre_jour`, `agent_jour`.

### Numéros (`PhonenumberController`, `Phonenumber::scopeFilter`, partagé avec l'audit RSVA)
- **Par défaut : numéros non supprimés** (`deleted_at IS NULL`) ; `include_deleted` / `only_deleted`.
- Recherche normalisée en `+33`. Assigné : `assigned_at IS NOT NULL`, non assigné : `IS NULL`. Erreur de routage : `routing_error IS NOT NULL`.
- Stats : total = actifs = `deleted_at IS NULL` ; supprimés = `deleted_at IS NOT NULL`.

### Sources / Companies / Providers
- Numéros assignables d'un couple source → provider-company : `provider_id = pc.provider_id AND company_id = pc.company_id AND assigned_at IS NULL AND will_be_deleted = 0 AND deleted_at IS NULL`.
- Nombre de numéros par company ou provider : `COUNT(*)` sur les numéros non supprimés.
- `companies.enabled`, `providers.enabled` : actif = 1. Les pages ne filtrent pas dessus par défaut (filtre `enabled=yes/no` optionnel). Les listes de filtres du Dashboard affichent toutes les companies et tous les providers ; seuls les callcenters sont filtrés `enabled=1`.

---

## 5. Appels rejetés (`disabled_calls`) et blacklist

- `disabled_calls` : appel arrivé sur un numéro non routé vers un agent (webhook entrant ; observé : `call_status='ringing'`, `direction='inbound'`). Colonnes : `phonenumber_id`, `blacklist_id`, `call_sid`, `from`, `to`, `called_at`, `caller_*`, `raw_payload`.
- **Règles communes (Dashboard et page Rejetés)** :
  1. **Dédup des relances opérateur** : une ligne est une relance si la ligne précédente du même couple (`from`,`to`) date de ≤ 300 s. La fenêtre est glissante : chaque relance repousse la fenêtre. On compte 10 min de marge avant la borne.
  2. On ne garde que les appels **entre 8 h et 20 h (heure de Paris, `HOUR` dans 8..19), hors dimanche**.
  SQL équivalent (session UTC) :
  ```sql
  WITH d AS (
    SELECT id, `from`, `to`, phonenumber_id, called_at,
           TIMESTAMPDIFF(SECOND, LAG(called_at) OVER (PARTITION BY `from`,`to` ORDER BY called_at, id), called_at) AS gap
    FROM disabled_calls WHERE called_at BETWEEN :debut_utc - INTERVAL 10 MINUTE AND :fin_utc)
  SELECT * FROM d
  WHERE (gap IS NULL OR gap > 300) AND called_at >= :debut_utc
    AND HOUR(<paris(called_at)>) BETWEEN 8 AND 19 AND DAYOFWEEK(<paris(called_at)>) <> 1
  ```
  `retries` = nombre de relances rattachées à l'appel gardé.
- Filtres applicables : uniquement `provider_id`, `company_id`, `source_id` via `phonenumber_id IN (SELECT id FROM phonenumbers WHERE …)` (supprimés inclus).
- **Appelant existant / nouveau** : existant si `EXISTS (SELECT 1 FROM calls WHERE caller = d.from AND called_at < d.called_at)`. Cela porte sur tout appel abouti antérieur, sans filtre de durée. Sinon nouveau.
- **Raison du rejet** (via `assignment_history` du `phonenumber_id`) :
  - `active_assignment` : une assignation couvre l'instant (`start_at <= t AND (end_at IS NULL OR end_at > t)`). C'est une anomalie.
  - `not_assigned_today` : aucune assignation terminée avant t le même jour (Paris).
  - sinon `expired_*` selon les minutes depuis le dernier `end_at <= t` : `<5`, `5_10`, `10_15`, `15_20`, `20_30`, `30_45`, `45_60`, `1h_2h`, `2h_4h`, `4h_8h`, `gt_8h` (bornes en minutes : 5, 10, 15, 20, 30, 45, 60, 120, 240, 480).
- **Blacklist** (`blacklist`) : `phonenumber` normalisé `+33XXXXXXXXX` (unique), `source` (texte libre, origine), `note`. Lien vers `calls.blacklist_id` et `disabled_calls.blacklist_id`. Aucun calcul : filtre Appels `blacklist_id`.

---

## 6. Numéros, assignations, routage

- `phonenumbers` : `phonenumber` E.164 `+33[1-9]XXXXXXXX` ; `company_id`, `provider_id` (obligatoires) ; `only_source_id` (numéro réservé à une source) ; `source_id` (source de l'assignation en cours ou de la dernière) ; `assigned_at` (assigné en ce moment si NOT NULL) ; `display_expires_at` (fin affichée au visiteur) ; `real_expires_at` (fin réelle du routage) ; `last_assigned_at` ; compteurs `total_assignments`, `total_calls`, `total_duration` ; `current_endpoint` ; `target` ; `last_routed_at` ; `routing_error(_at)` ; `will_be_deleted` (suppression différée d'un numéro assigné) ; `sip_registered` ; `deleted_at`.
- Durées d'assignation : `sources.display_duration_minutes` / `real_duration_minutes`. Valeurs par défaut de l'API, ou en repli 20 et 40 min.
- Endpoints : `scr.sip.twilio.com` = parking (numéro non assigné ou expiré, les appels y deviennent des rejets) ; `13.36.113.246` = IP Voxnode.
- Routage incohérent (badge) : `routing_error IS NOT NULL`, ou jamais assigné et endpoint ≠ twilio, ou assigné non expiré (`real_expires_at IS NULL OR > NOW()`) et endpoint = twilio, ou expiré depuis plus de 2 min et endpoint ≠ twilio.
- `assignment_history` : 1 ligne par assignation (`phonenumber_id`, `source_id`, `company_id`, `provider_id`, `start_at`, `end_at` NULL = en cours, `endpoint`, `target`). Filtres de page : `DATE(start_at)` en UTC.
- `visitor_assignments` : **non utilisée par le panel**. Elle enregistre un numéro attribué à un visiteur web (`fingerprint`, `ip`, `source_id`, `phonenumber_id`, `assigned_at`, `expires_at`). Observé : environ 10 min en moyenne, données de mars à juillet 2026.
- `providers_companies` : couple provider ↔ company (`payout`, `config` JSON). `sources_providers_companies` : couples utilisables par une source, avec un **`weight`** (entier ≥ 1, poids relatif de répartition utilisé par l'API) et un `payout` optionnel.
- `providers` : `driver`, `sip_number_format` (`e164`, `e164_no_plus`, `local`), `config` JSON (`config.selfhosted` = routable sur notre serveur SIP), `enabled`.
- `sources` : `api_key` (secret, ne pas exposer), `fingerprint`, `only_dedicated_phonenumber`, `max_concurrent_numbers`, `payout_call`, `payout_minute`, `solde`.
- `routing_logs` : `status` (`success` ; erreurs = `status IN ('error','failed','failure')`, comparaison insensible à la casse) ; `reason` ∈ `assignment`, `expiry`, `reactivation`, `manual_force` (observé) ; `endpoint`, `message`, `duration_ms`, `attempt`, `job_id`, `request_data`/`response_data` JSON. Stats « aujourd'hui » : `DATE(created_at) = CURDATE()` en UTC. Badge : une erreur dans les dernières 24 h.

---

## 7. SMS, notations, RSVA

- **SMS support** (`support_sms_*`) : `to_be_sent(_at)`, `content`, `sent`, `clicked(_at)`, `clicked_count`, `filled(_at)` (formulaire rempli), `emotion`, avis booléens `review_wait_too_long`, `review_agent_need_to_improve`, `review_agent_bad`, `review_service_price`, `review_didnt_know_price`, `review_didnt_transfered`, `review_call_ended_before_transfer`, `review_free` (texte libre), `request_recontact`, `email`, `recontact_at`, `recontacted`. `call_weblogs` (`type='support'`, `ip`, `user_agent`) = visites du lien (déduit). **Le panel ne calcule aucun indicateur SMS.**
- **SMS info** (`info_sms_*`) : `sent`, `to_be_sent_at`, `content`, `clicked`, `clicked_count`, `clicked_at`.
- **Notation qualité** (`calls.ratings_*`) : `ratings_note` ∈ `up`/`down` (« a une note » = `ratings_note IS NOT NULL`), `ratings_reviewer`, `ratings_reviewed_at`, `ratings_review_started_at`, `ratings_review_duration`, `ratings_locked_at`, `ratings_not_rated(+_reason)`, `ratings_warning`, `ratings_danger(+_reason)`, `ratings_info` JSON, problèmes `ratings_issue_microphone_quality`, `bad_lang`, `bad_understanding`, `bad_answers`, `not_happy`, `swearing`, `duration`.
- `call_ratings` : `call_id`, `who_rate` (évaluateur, observé : Lam, Lamia, Ly, LamSante), `rate` ∈ `up`/`down`/NULL (NULL = écouté sans note, déduit). `agent_ratings` est vide.
- Autres champs agent (`phone_*`) : `phone_agent_hangup(+_reason)` (l'agent a raccroché), `phone_reported_by_agent(+_reason)`, `phone_cant_provide_service(+_reason/_data/_sms/_email)`, `phone_expected_min/max_*` (bornes attendues), `phone_answered_at`, `phone_ended_at`, `callback_for_offer`, `call_listened_by`.
- **RSVA** : l'audit interroge l'API Numero08 numéro par numéro, avec les mêmes filtres que la page Numéros, triés par provider, company puis numéro. Groupes : `active` (en service et tarif `D080`), `bad_tariff` (en service, autre tarif), `inactive` (`withdrawn`, `closed`, `undeclared`), `unknown` (inconnu ou sans état en vigueur), `error`. **Rien n'est stocké en base.**

---

## 8. Agents : présence, logs, réseau (tables non exploitées par les pages, sauf le réseau)

- `agents` : `name` = `voxnode_username` = valeur de `calls.agent_name` ; `online`, `last_ping_at`, `break_*`, `sip_current_platform`, `sip_registered`, `sip_in_call`, `sip_platform_pin` (mode forcé `selfhosted`/`voxnode`, NULL = automatique), `net_http_ms`, `net_sip_ms`. `callcenter_id` obsolète.
- `agent_attendances` (soft delete) : `action='work'` (session de travail, `type` NULL) ou `action='break'` avec `type` ∈ `pause`, `lunch`, `manager` ; `duration` en s (= `ended_at - created_at`, NULL tant que c'est en cours) ; `initiator` (`agent`). Observé : pause ≈ 7 min, déjeuner ≈ 58 min. Temps de pause d'un agent : `SUM(duration) WHERE action='break' AND deleted_at IS NULL`.
- `agent_logs` (soft delete, 18 M lignes) : `type`/`action` observés : `ping/ping`, `browser/visibilitychange`, `browser/loaded`, `log/{init_twilio,request_break,end_break,login,logout,request_logout,cancel_break}`, `NULL/start_break`, `danger/call_timeout`, `warning/{end_work_failed,end_break_failed}` ; `call_id`, `brand_id`, `value`.
- `agent_console_logs` : logs JSON du navigateur de l'agent.
- **`agent_network_quality`** (page Téléphonie, réseau) : tranches de 5 min par `agent_id` × `platform`, `period_start` TIMESTAMP. Agrégats : HTTP moyen = `SUM(http_sum)/SUM(http_count)`, HTTP max = `MAX(http_max)`, SIP moyen = `SUM(sip_sum)/SUM(sip_count)`, SIP max = `MAX(sip_max)`, `SUM(sip_lost)` (OPTIONS sans réponse), `SUM(disconnects)` (coupures SIP), `SUM(samples)`. Fenêtres : 24, 168 ou 720 h, `period_start >= FROM_UNIXTIME(now - N h)`. Seuil d'alerte : SIP > 600 ms, ou OPTIONS perdus / coupures répétés.
- `settings` (clé → JSON) : `opening_hours` (7 jours, `{open, close}` ou null = fermé), `selfhosted_transfers_internal`, `selfhosted_percent`, `ai_daily_calls`, `priority_min_duration`, réglages du régulateur… (ligne absente = valeur par défaut). Seuls l'attente forcée de base et les sons sont sur le serveur SIP (`/welcome`).

---

## 9. Heure de Paris en SQL

Recommandation : `SET time_zone = '+00:00'`, puis bornes UTC calculées pour la période de Paris, en intervalle semi-ouvert : `called_at >= '2026-10-31 23:00:00' AND called_at < '2026-11-01 23:00:00'` pour le 1er novembre 2026. C'est la méthode du panel (`startOfDay()->utc()` / `endOfDay()->utc()`).
Si on a besoin de la date ou de l'heure de Paris par ligne (`GROUP BY` jour ou heure), la page Performance applique `DATE_ADD(called_at, INTERVAL offset SECOND)`, avec l'offset +3600 (hiver) ou +7200 (été) selon les changements d'heure. Formule générique (heure d'été = du dernier dimanche de mars 01:00 UTC au dernier dimanche d'octobre 01:00 UTC) :
```sql
DATE_ADD(c.called_at, INTERVAL IF(
  c.called_at >= TIMESTAMP(DATE_SUB(LAST_DAY(MAKEDATE(YEAR(c.called_at),1) + INTERVAL 2 MONTH),
                 INTERVAL DAYOFWEEK(LAST_DAY(MAKEDATE(YEAR(c.called_at),1) + INTERVAL 2 MONTH)) - 1 DAY), '01:00:00')
  AND c.called_at < TIMESTAMP(DATE_SUB(LAST_DAY(MAKEDATE(YEAR(c.called_at),1) + INTERVAL 9 MONTH),
                 INTERVAL DAYOFWEEK(LAST_DAY(MAKEDATE(YEAR(c.called_at),1) + INTERVAL 9 MONTH)) - 1 DAY), '01:00:00'),
  7200, 3600) SECOND)
```
Par page :

| Page | Fuseau |
|---|---|
| Dashboard jour par jour, par company, par heure, qualité, rejetés | Paris → UTC (avec les petits décalages de comparaison du §4) |
| Dashboard KPI, graphique, marques ; Appels (`whereDate`) ; Historique d'assignation ; Logs de routage | **UTC** |
| Performance | Paris (offset DST en SQL) |
| Attente et pertes, Réseau agents | Paris via epoch (`FROM_UNIXTIME` / `UNIX_TIMESTAMP`) |
| Partenaires | Paris → UTC |
| Soldes | `created_at` brut (UTC), `to` + `' 23:59:59'` |
