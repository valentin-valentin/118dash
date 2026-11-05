# Relecture des textes « Serveur SIP » (118dash)

Je n'ai rien modifié. Les textes sous Actif/Désactivé (`SipServerSettings.vue:254-256` et `:826-831`) et l'aide du pourcentage (`:895`) sont exclus, comme demandé.

## resources/js/pages/SipServer.vue

- **:109** — `'En service'` — `'Actif'` (même mot que l'écran Configuration)
- **:121** — `'service interne'` / `'vers la marque'` — `'Service interne'` / `'Vers la marque'`
- **:124** — « Tout transfert sur notre serveur part au service interne (la marque choisie reste enregistrée) » — « Sur notre serveur, tous les transferts vont au service interne. La marque choisie par l'agent reste notée sur l'appel. »
- **:125** — « Vers la marque choisie par l'agent ; si elle ne répond pas en 20 s ou refuse, service interne » — « Chaque transfert appelle la marque choisie par l'agent. Si elle ne décroche pas en 20 s, est occupée ou refuse, le client part au service interne. »
- **:131** — `'aucun'` — `'Aucun'`
- **:133** — « Seuls les numéros de ces fournisseurs peuvent arriver sur notre serveur (réglable dans la fiche du fournisseur) » — « Seuls les numéros de ces fournisseurs peuvent arriver sur notre serveur. Ce choix se règle dans la fiche de chaque fournisseur. »
- **:156** — `Données figées depuis ${s} s` — `Pas de mise à jour depuis ${s} s`
- **:199** — `Notre serveur est en panne : tout part chez Voxnode, retour après ${st.pbx_recovery_minutes} min de bonne santé.` — `Notre serveur est en panne. Tout part chez Voxnode. Notre serveur recevra de nouveau des numéros après ${st.pbx_recovery_minutes} min sans problème.` — ATTENTION : en mode simulation (pbx_failover_mode = simulate), rien ne part chez Voxnode : dans ce cas afficher plutôt « Notre serveur est en panne. Mode simulation : rien ne part chez Voxnode, les appels envoyés chez nous pendant la panne sont perdus. »
- **:203** — `Notre serveur a redémarré à ${…} : les appels en cours ont été coupés. ${reason}` — `Notre serveur a redémarré à ${…}. Les appels en cours à ce moment-là ont été coupés. ${reason}`
- **:204** — « La ligne OVH est déconnectée : le téléphone du panel ne peut plus appeler l'extérieur. » — « La ligne OVH est déconnectée. Le softphone du panel ne peut plus appeler l'extérieur. »
- **:205** — « Le disque de notre serveur est presque plein (détail dans Configuration). » — « Le disque de notre serveur est presque plein. Le détail est dans Configuration, onglet Serveur et stockage. »
- **:207** — `Le régulateur ne tourne plus : dernière décision ${à HH:MM | 'inconnue'}.` — `Le régulateur est arrêté. ${at ? `Dernière décision à ${formatTime(at)}.` : 'Aucune décision connue.'}`
- **:213** — `Passage sur notre serveur raté : ${failedOurs} (nouvel essai automatique dans quelques minutes).` — `Ces agents n'ont pas pu passer sur notre serveur : ${failedOurs}. Un nouvel essai aura lieu automatiquement dans quelques minutes.`
- **:214** — `Retour vers Voxnode raté : ${failedVoxnode} (nouvel essai dans 2 min).` — `Ces agents n'ont pas pu repasser chez Voxnode : ${failedVoxnode}. Nouvel essai dans 2 min.`
- **:215** — `Connectés mais sans téléphone actif depuis plus de 30 s : ${…}.` — `Ces agents sont connectés, mais leur téléphone n'est relié ni à notre serveur ni à Voxnode depuis plus de 30 s : ${…}.`
- **:343** — colonne `'Appelant'` — `'Client'`
- **:382 (sub)** — `"personne n'attend un agent"` — `"Personne n'attend"`
- **:382 (title)** — « Client en attente d'un agent depuis le plus longtemps » — « Le client qui attend un agent depuis le plus longtemps. La durée est comptée depuis le début de son appel. »
- **:387** — `` `Voxnode indisponible : ${voxnode.value.error}` `` — `` `Impossible de lire les appels Voxnode. Erreur : ${voxnode.value.error}` `` (évite de confondre avec une panne de Voxnode)
- **:389** — `` `+ ${legacy} chez Voxnode « En attente », sans détail` `` — `` `Plus ${legacy} en attente chez Voxnode, étape inconnue` ``
- **:443** — `` `${agent.username} : ${label}` `` — `` `${agent.username} est maintenant en mode « ${label} »` ``
- **:527** — « Impossible de joindre le panel : {{ error }} » — « Impossible de charger les données. Erreur : {{ error }} »
- **:529** — « API du serveur SIP non configurée : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis php artisan config:cache. » — « Le lien avec notre serveur n'est pas configuré. À faire par le développeur : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis lancer php artisan config:cache. »
- **:558** — « Réglages de téléphonie indisponibles ({{ telephony.error }}) : seuls les agents de notre serveur sont affichés. » — « Impossible de lire les réglages de téléphonie. Seuls les agents connectés à notre serveur sont affichés. Erreur : {{ telephony.error }} »
- **:580** — `nous {{ f.split.ours }}` — `chez nous {{ f.split.ours }}`
- **:601** — `` `Liste indisponible : ${data.calls.error}` `` — `` `Impossible de lire les appels de notre serveur. Erreur : ${data.calls.error}` ``
- **:652** — `` `bascule vers ${PLATFORMS[a.wanted_platform]}` `` — `` `passe chez ${PLATFORMS[a.wanted_platform]}` ``
- **:674** — « en bascule » — « change de plateforme »
- **:691** — « Chez nous / objectif » — « Agents chez nous / objectif »
- **:704** — `{{calls}} appels · {{natural}} en vraie attente · dont {{n}} depuis plus de 30 s` — `{{calls}} appels · {{natural}} attendent un agent après l'attente forcée · dont {{n}} depuis plus de 30 s`
- **:709** — « Mode simulation : si notre serveur tombe en panne, rien ne bascule vers Voxnode (la panne est seulement notée). Les déplacements d'agents ci-dessus, eux, sont bien appliqués. » — « Mode simulation activé. Si notre serveur tombe en panne, rien ne part chez Voxnode. La panne est seulement notée dans l'historique. Les déplacements d'agents ci-dessus, eux, sont bien réels. »
- **:715** — « Liste des agents remise à zéro (serveur désactivé, en panne ou Voxnode désactivé) » — « Le régulateur repart de zéro. Notre serveur était désactivé ou en panne, ou Voxnode était désactivé. »
- **:716** — `→ nous : …` — `→ chez nous : …`
- **:729** — `` `${n} forcé(s) sur une plateforme` `` / `'tous automatiques'` — `` `${n} agent(s) forcé(s) sur une plateforme` `` / `'Tous les agents en automatique'`
- **:733** — placeholder « Agent, nom ou centre… » — « Rechercher un agent, un nom ou un centre… »
- **:734** — « Automatique : l'agent suit la répartition et les bascules. Forcé : il reste sur la même plateforme, même en cas de panne. » — « Automatique : le régulateur envoie l'agent là où sont les clients, et le déplace en cas de panne ou de désactivation. Forcé : l'agent reste sur la même plateforme, même en cas de panne. »
- **:747** — `bascule ratée le {{ … }}` — `changement de plateforme raté le {{ … }}`
- **:780** — « À surveiller : réponse du serveur au-delà de 600 ms, 3 messages sans réponse ou plus, 2 coupures ou plus. » — « Une connexion est à surveiller dans trois cas. Le serveur met plus de 600 ms à répondre en moyenne. Ou 3 messages ou plus restent sans réponse. Ou la connexion coupe 2 fois ou plus. »
- **:788** — « Dernière mesure remontée par le softphone » — « Dernière mesure envoyée par le téléphone de l'agent »
- **:789** — « Réponse SIP OPTIONS du serveur, authentification comprise » — « Temps que met le serveur à répondre au téléphone de l'agent, identification comprise »
- **:791** — « OPTIONS envoyés sans réponse » — « Messages de contrôle envoyés au serveur et restés sans réponse »
- **:792** — « Coupures de l'enregistrement SIP » — « Nombre de fois où le téléphone de l'agent a perdu sa connexion au serveur »
- **:793** — « Aller-retour vers l'application » — « Temps de réponse du site, mesuré depuis l'ordinateur de l'agent »
- **:834** — « (simulation : rien n'a basculé) » — « Simulation, rien n'est parti chez Voxnode »

## resources/js/pages/SipServerSettings.vue

- **:180** — « Part des nouveaux numéros : un nombre entier entre 0 et 100. » — « La part des nouveaux numéros doit être un nombre entier entre 0 et 100. »
- **:181** — « Retour après une panne : entre 1 et 120 minutes. » — « Le délai de retour après une panne doit être entre 1 et 120 minutes. »
- **:188** — `Les nouveaux numéros iront à ${p} % chez nous. Les numéros déjà chez nous y restent jusqu'à la fin de leur attribution. Pour tout renvoyer chez Voxnode tout de suite : Désactiver.` — `Environ ${p} % des nouveaux numéros arriveront chez nous. Les numéros déjà chez nous y restent jusqu'à la fin de leur attribution. Pour tout renvoyer chez Voxnode tout de suite, désactivez plutôt notre serveur.`
- **:194** — « Durée d'appel : entre 1 et 3600 secondes. » — « La durée d'appel doit être entre 1 et 3 600 secondes. »
- **:195** — « Période : entre 1 et 720 heures. » — « La période doit être entre 1 et 720 heures. »
- **:198** — « Agents IA : entre 0 et 1000 appels par jour. » — « Le test des agents IA accepte entre 0 et 1 000 appels par jour. »
- **:207** — « En cas de panne de notre serveur, rien ne basculera plus sur Voxnode : la panne sera seulement notée dans l'historique. Les appels envoyés chez nous pendant une panne seront perdus. » — « Si notre serveur tombe en panne, rien ne partira chez Voxnode. La panne sera seulement notée dans l'historique. Les appels envoyés chez nous pendant la panne seront perdus. »
- **:211** — « En cas de panne de notre serveur, ses numéros et ses agents basculeront sur Voxnode. » — « Si notre serveur tombe en panne, ses numéros et ses agents partiront automatiquement chez Voxnode. »
- **:213** — `'Bascule en simulation'` — `'Mode simulation activé'`
- **:223** — « Sur notre serveur, tous les transferts iront au service interne, y compris ceux de l'agent IA, sans message avant transfert. La marque choisie par l'agent reste notée sur l'appel. Les appels Voxnode ne changent pas. » — « Sur notre serveur, tous les transferts iront au service interne, y compris ceux de l'agent IA. Le message avant transfert ne sera pas joué. La marque choisie par l'agent reste notée sur l'appel. Rien ne change pour les appels Voxnode. »
- **:228** — « Sur notre serveur, chaque transfert appellera la marque choisie par l'agent, avec le message avant transfert si la marque l'a. Si la marque ne décroche pas en 20 secondes, est occupée ou refuse l'appel, le client part automatiquement au service interne. Les appels Voxnode ne changent pas. » — « Sur notre serveur, chaque transfert appellera la marque choisie par l'agent. Si la marque a un message avant transfert, le client l'entend d'abord. Si la marque ne décroche pas en 20 secondes, est occupée ou refuse l'appel, le client part automatiquement au service interne. Rien ne change pour les appels Voxnode. »
- **:232** — `'Transferts : service interne'` / `'Transferts : vers les marques'` — `'Tous les transferts vont au service interne'` / `'Les transferts vont vers les marques'`
- **:236** — « Agents déplacés : entre 1 et 10. » — « Le nombre d'agents déplacés doit être entre 1 et 10. »
- **:237** — « Délai avant de redéplacer un agent : entre 0 et 60 minutes. » — « Le délai avant de redéplacer un agent doit être entre 0 et 60 minutes. »
- **:238** — « Seuil d'attente : entre 0 et 600 secondes. » — « L'attente qui déclenche un renfort doit être entre 0 et 600 secondes. »
- **:269** — « Ses numéros repartent immédiatement chez Voxnode. Ses agents partent progressivement : le dernier attend qu'il ne reste plus aucun client ni numéro chez nous (au moins 3 min 30). Les appels en cours chez nous continuent normalement. » — « Ses numéros repartent tout de suite chez Voxnode. Ses agents partent petit à petit. Le dernier agent reste tant qu'il y a encore un client ou un numéro chez nous, et au moins 3 min 30. Les appels en cours chez nous continuent normalement. »
- **:289** — « Ses numéros (fournisseurs autorisés chez nous) arrivent immédiatement chez nous. Ses agents viennent progressivement : Voxnode en garde un par client qui y attend encore. Si Voxnode ne répond plus du tout, tous les agents viennent tout de suite. Les numéros des autres fournisseurs restent chez Voxnode. Les transferts vers les marques passent par Voxnode : s'il est en panne, ils iront au service interne. » — « Les numéros des fournisseurs autorisés chez nous arrivent tout de suite chez nous. Les numéros des autres fournisseurs restent chez Voxnode. Les agents viennent chez nous petit à petit. Voxnode garde un agent pour chaque client qui y attend encore. Si Voxnode ne répond plus du tout, tous les agents viennent tout de suite. Attention, les transferts vers les marques passent par Voxnode. Si Voxnode est en panne, ils iront au service interne. »
- **:293** — `Notre serveur ne garde qu'environ ${p} % des numéros, choisis au hasard ; les autres repartent tout de suite chez Voxnode, les agents petit à petit.` — `Notre serveur ne garde qu'environ ${p} % des numéros, choisis au hasard. Les autres repartent tout de suite chez Voxnode. Les agents suivent petit à petit, là où sont les clients.`
- **:327** — `` `${label} : heures au format HH:MM (23:59 au plus tard).` `` — `` `${label} : écrire les heures au format HH:MM, 23:59 au plus tard.` ``
- **:340** — `` `${message}, appliqué par le serveur SIP sous 2 minutes` `` — `` `${message}. Notre serveur les applique d'ici 2 minutes.` ``
- **:404** — « Le fichier est supprimé définitivement du serveur. » — « Le fichier sera supprimé définitivement de notre serveur. »
- **:436** — « Fichier trop gros (50 Mo maximum). » — « Fichier trop gros. La taille maximum est de 50 Mo. »
- **:452** — `` `Envoi interrompu (morceau ${index + 1} sur ${total}) : ${e.message}` `` — `` `Envoi interrompu au morceau ${index + 1} sur ${total}. Erreur : ${e.message}` ``
- **:503** — « Durées en secondes, entre 0 et 1800. » — « Les durées sont en secondes, entre 0 et 1 800. »
- **:568** — `` `Code opérateur « ${r.code} » : 2 à 6 lettres ou chiffres.` `` — `` `Le code opérateur « ${r.code} » doit faire 2 à 6 lettres ou chiffres.` ``
- **:573** — `` `${code} : durées en secondes, entre 0 et 600.` `` — `` `${code} : les durées sont en secondes, entre 0 et 600.` ``
- **:610** — `` `Attente par opérateur : ${count} opérateur(s)` `` / `'Attente par opérateur : aucune exception'` — `` `Attente réglée pour ${count} opérateur(s)` `` / `'Même attente pour tous les opérateurs'`
- **:697** — `'Tourne'` — `'En marche'`
- **:740** — « Impossible de joindre le panel : {{ error }} » — « Impossible de charger les données. Erreur : {{ error }} »
- **:742** — « Configuration à faire par le développeur : API du serveur SIP non configurée : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis php artisan config:cache. » — « Le lien avec notre serveur n'est pas configuré. À faire par le développeur : renseigner SIP_API_URL et SIP_API_TOKEN dans le .env, puis lancer php artisan config:cache. »
- **:764** — « Les changements s'appliquent immédiatement en production. » — « Les changements s'appliquent tout de suite, sur les vrais appels. »
- **:773** — « Impossible de lire les réglages de téléphonie (vn.118.ae) : {{ telephony.error }} » — « Impossible de lire les réglages de téléphonie. Erreur : {{ telephony.error }} »
- **:779** — « S'applique tout de suite, après confirmation. » — « Chaque choix demande une confirmation, puis s'applique tout de suite. »
- **:854** — « Une panne de notre serveur est seulement notée dans l'historique : rien ne bascule sur Voxnode. » — « Si notre serveur tombe en panne, la panne est seulement notée dans l'historique. Rien ne part chez Voxnode. »
- **:855** — « Une panne de notre serveur envoie ses numéros et ses agents sur Voxnode. » — « Si notre serveur tombe en panne, ses numéros et ses agents partent automatiquement chez Voxnode. »
- **:865** — « Vers la marque (sinon service interne) » — « Vers la marque » (le texte en dessous explique la suite)
- **:878** — « Tous les transferts vont au service interne, sans message avant transfert, y compris ceux de l'agent IA. La marque choisie par l'agent reste notée sur l'appel. » — « Tous les transferts vont au service interne, y compris ceux de l'agent IA. Le message avant transfert n'est pas joué. La marque choisie par l'agent reste notée sur l'appel. »
- **:879** — « Chaque transfert appelle la marque choisie par l'agent (la marque voit le numéro du client). S'il n'aboutit pas (refus, occupé, pas de réponse en 20 s), le client part au service interne. » — « Chaque transfert appelle la marque choisie par l'agent. La marque voit le numéro du client. Si la marque refuse, est occupée ou ne décroche pas en 20 s, le client part au service interne. »
- **:905** — « Après une panne, durée de bon fonctionnement avant que notre serveur reçoive de nouveau des numéros (mode Bascule automatique). » — « Après une panne, notre serveur doit fonctionner sans problème pendant cette durée avant de recevoir de nouveau des numéros. Ce réglage ne sert qu'en mode Bascule automatique. »
- **:927** — « Environ {{N}} appels par jour de notre serveur (au plus {{N}}), jamais les appelants prioritaires. Si l'IA ne décroche pas en 5 s, nos agents prennent l'appel. 0 = test coupé. » — « Environ {{N}} appels par jour arrivant sur notre serveur sont envoyés à l'agent IA, jamais plus de {{N}}. Les clients prioritaires n'y sont jamais envoyés. Si l'agent IA ne décroche pas en 5 s, nos agents prennent l'appel. Mettre 0 pour arrêter le test. »
- **:945** — « forcer un agent sur une plateforme (page Serveur SIP) » — « Forcer un agent à rester sur notre serveur ou chez Voxnode. Se règle sur la page Serveur SIP. »
- **:955** — « Heure de Paris, appels arrivant sur notre serveur uniquement (Voxnode n'est pas concerné). En dehors des horaires, l'appel est refusé sans décrocher : il n'est pas facturé. Un appel déjà en cours n'est jamais coupé. » — « Heures de Paris. Ces horaires ne valent que pour les appels qui arrivent sur notre serveur, pas pour Voxnode. En dehors des horaires, l'appel est refusé sans décrocher, donc il n'est pas facturé. Un appel déjà en cours n'est jamais coupé. »
- **:974** — « Appliqué par le serveur » — « Appliqué par notre serveur »
- **:1000** — « Appliqué par le serveur SIP sous 2 minutes. » — « Notre serveur l'applique dans les 2 minutes après l'enregistrement. »
- **:1011** — « Parcours de l'appelant » — « Parcours du client »
- **:1012** — « Ce qu'entend chaque appel arrivant sur notre serveur, dans l'ordre, jusqu'à ce qu'un agent décroche, puis en cas de transfert. » — « Ce qu'entend un client qui appelle sur notre serveur, dans l'ordre, jusqu'à ce qu'un agent décroche. Puis ce qu'il entend en cas de transfert. »
- **:1033** — « Avant d'arriver dans la file, chaque appelant écoute l'audio d'attente pendant une durée tirée au hasard entre le minimum et le maximum. Les appelants prioritaires (blacklist, ou appel récent) n'ont pas d'attente forcée. » — « Avant d'entrer dans la file, chaque client écoute l'audio d'attente. La durée est tirée au hasard entre le minimum et le maximum. Par exemple, avec 30 et 90 s, un client peut attendre 47 s. Les clients prioritaires n'ont pas d'attente forcée. Ce sont les clients blacklistés et ceux qui ont appelé récemment. »
- **:1035** — « Pour tous les appelants : entre » — « Pour tous les clients : entre »
- **:1049** — « Selon l'opérateur de l'appelant » — « Selon l'opérateur du client »
- **:1050** — « Remplace la durée de base pour les appelants de ces opérateurs. Les autres gardent la durée de base. 0 et 0 = pas d'attente forcée. » — « Les clients de ces opérateurs ont cette durée à la place de la durée de base. Les autres clients gardent la durée de base. Mettre 0 et 0 pour supprimer l'attente forcée. »
- **:1078** — « Aucun opérateur réglé : tout le monde a la durée de base. » — « Aucun opérateur réglé. Tous les clients ont la durée de base. »
- **:1092** — « L'opérateur de l'appelant est recherché au moment de l'appel. Si ce service ne répond pas, on prend l'opérateur connu de ses appels précédents ; sinon la durée de base s'applique. » — « L'opérateur du client est recherché au moment de l'appel. Si la recherche échoue, on reprend l'opérateur trouvé lors de ses appels précédents. Si on ne le connaît pas, la durée de base s'applique. »
- **:1102 et :1162** — « Aucun son : musique d'attente par défaut du serveur. » — « Aucun son choisi. Le client entend la musique d'attente par défaut. »
- **:1114** — « Joué en entier au client avant un transfert vers une marque (l'agent est libéré tout de suite). Il se règle marque par marque, dans la fiche de la marque. Il n'est pas joué pour le service interne, ni quand tous les transferts vont au service interne. » — « Joué en entier au client avant un transfert vers une marque. L'agent est libéré tout de suite. Le message se règle marque par marque, dans la fiche de la marque. Il n'est pas joué vers le service interne, ni quand tous les transferts vont au service interne. »
- **:1130** — « Appelants prioritaires » — « Clients prioritaires »
- **:1134-1138** — « Les blacklistés, et ceux qui ont eu un appel d'au moins [X] s (attente comprise) dans les [Y] dernières heures. » — « Les clients blacklistés, et ceux qui ont déjà eu un appel d'au moins [X] s, attente comprise, dans les [Y] dernières heures. »
- **:1154** — « Ce qu'entend le client quand il est envoyé au service interne ({{ INTERNAL_SERVICE_NUMBER }}) : choix de l'agent, transfert vers une marque qui n'a pas abouti, ou tous les transferts si le réglage est actif. Joué jusqu'à la fin de l'appel (29 min 45 au plus depuis le décroché). » — « Ce qu'entend le client envoyé au service interne, au {{ INTERNAL_SERVICE_NUMBER }}. Il y arrive si l'agent l'y envoie, si un transfert vers une marque n'a pas abouti, ou si tous les transferts vont au service interne. Les sons sont joués jusqu'à la fin de l'appel, au plus 29 min 45 après le décroché. »
- **:1196** — « Seuls ces serveurs de fournisseurs peuvent nous envoyer des appels. L'IP de Voxnode est exclue volontairement (Voxnode n'envoie jamais nos appels ici). » — « Seuls les fournisseurs qui appellent depuis ces adresses IP peuvent envoyer des appels à notre serveur. L'IP de Voxnode n'est volontairement pas dans la liste, car Voxnode ne nous envoie jamais d'appels. »
- **:1203** — « Liste indisponible : {{ … }} » — « Impossible de lire la liste. Erreur : {{ … }} »
- **:1221** — « Arrêté : dernière décision {{ heure \| 'inconnue' }}. » — « Le régulateur est arrêté. Dernière décision à {{ heure }}. » ou, sans heure, « Le régulateur est arrêté. Aucune décision connue. »
- **:1222** — « Dernière décision il y a {{ s }} s : {{ reason }} » — « Dernière décision il y a {{ s }} s. {{ reason }} »
- **:1223** — « journal → » — « Voir le journal → »
- **:1229** — « Un agent qui vient de changer de plateforme n'est pas redéplacé avant. » — « Un agent qui vient de changer de plateforme reste où il est pendant ce délai. »
- **:1230** — « Au-delà de cette attente dans notre file (après l'attente forcée), un agent libre de Voxnode est envoyé en renfort. » — « Si un client attend plus longtemps que cela dans notre file, un agent libre de Voxnode vient en renfort. L'attente forcée n'est pas comptée. »
- **:1287** — « La taille n'est plus recalculée (normalement chaque jour à 05:17) : le chiffre peut être faux. » — « La taille n'a pas été recalculée depuis plus de 26 h. Elle l'est normalement chaque jour à 05:17. Le chiffre affiché peut donc être faux. »
- **:1291** — « Suppression automatique : enregistrements agent après 180 jours, transferts après 60 jours. Ceux des appelants blacklistés sont gardés. » — « Les enregistrements des agents sont supprimés automatiquement après 180 jours, ceux des transferts après 60 jours. Les enregistrements des clients blacklistés sont gardés. »
- **:1301** — « IP à donner aux fournisseurs (SIP / RTP) » — « IP à donner aux fournisseurs »
- **:1302** — « Connexion des softphones (agents et panel) » — « Adresse de connexion des téléphones des agents et du panel »
- **:1349** — « mp3, wav, ogg… (50 Mo maximum), converti automatiquement au format téléphone. » — « Formats mp3, wav, ogg… 50 Mo maximum. Le fichier est converti automatiquement au format téléphone. »
- **:1357** — « Conversion sur le serveur SIP… » — « Conversion sur notre serveur… »
- **:1361** — « Nom (facultatif, nettoyé automatiquement) » — « Nom, facultatif »
- **:1363** — « Sans nom, celui du fichier est utilisé. Un suffixe unique est ajouté : un envoi n'écrase jamais un son existant. » — « Sans nom, le nom du fichier est utilisé. Le nom est nettoyé automatiquement. Un code unique est ajouté à la fin, pour ne jamais écraser un son existant. »
- **:1385** — « Le fournisseur pourra nous envoyer des appels depuis cette IP, en environ 1 seconde. » — « Le fournisseur pourra envoyer des appels depuis cette IP environ 1 seconde après l'ajout. »
- **:1388** — « IP ou plage » — « IP ou plage d'IP »
- **:1390** — « C'est l'IP de Voxnode : elle est exclue volontairement, elle ne doit pas être ajoutée. » — « C'est l'IP de Voxnode. Elle est exclue volontairement et ne doit pas être ajoutée. »

## resources/js/pages/SoftphoneHistory.vue

- **:83** — « N'a pas atteint le serveur » — « N'a pas atteint notre serveur »
- **:90** — « Refusé : trop d'appels en même temps (5 au maximum) » — « Refusé : déjà 5 appels en cours, c'est le maximum »
- **:105** — « échec normal, non précisé » — « échec sans raison précise »
- **:110** — « erreur entre opérateurs, non précisée » — « erreur entre opérateurs, sans raison précise »
- **:226** — « Enregistrement (dès la composition) » — « Enregistrement, dès la composition du numéro »
- **:229** — « Pas d'audio » — « Pas d'enregistrement »
- **:236** — « Cause du raccroché » — « Raison de la fin d'appel »

## resources/js/components/Softphone.vue

- **:34** — « Annonce / sonnerie » — « Message ou sonnerie »
- **:75** — « Connecté au serveur téléphonique » — « Connecté à notre serveur »
- **:79** — « Appels du softphone : enregistrements et journaux » — « Historique des appels du softphone, avec enregistrements et journaux »
- **:137** — « Numéros français uniquement (ou 118 XXX) » — « Seuls les numéros français et les 118 XXX peuvent être appelés »
- **:192** — `` `Appeler ${n}` `` — `` `Reprendre le ${n}` ``. Le clic ne fait que remplir le champ, il n'appelle pas.
- **:201** — « Test du son : vous entendez votre propre voix en retour (appel gratuit, sans sortie vers l'extérieur). » — « Test du son. Vous entendez votre propre voix en retour. L'appel est gratuit, car il ne sort pas vers l'extérieur. »

## resources/js/pages/BrandChecks.vue

- **:32** — « L'appelant » — « Le client »
- **:229** — « durée du transfert mesurée par le serveur, depuis le décroché de la marque (sonnerie et message avant transfert exclus). Un transfert qui n'a pas abouti (refusé, occupé, sans réponse) part au service interne et n'est pas compté ici. L'enregistrement commence dès la sonnerie. » — « la durée est comptée à partir du moment où la marque décroche. La sonnerie et le message avant transfert ne sont pas comptés. Si la marque refuse, est occupée ou ne répond pas, le client part au service interne et le transfert n'est pas compté ici. L'enregistrement, lui, commence dès la sonnerie. »
- **:233** — « durée de l'enregistrement audio du transfert ; 0 s quand il n'y a pas d'enregistrement. » — « la durée est celle de l'enregistrement du transfert. Sans enregistrement, elle vaut 0 s. »
- **:237** — « à l'ouverture de la page et au changement de période, les transferts pas encore mesurés le sont, puis la liste est recalculée. Changer le seuil ou le nombre de transferts recalcule la liste aussitôt. » — « quand vous ouvrez la page ou changez de période, la page mesure d'abord les nouveaux transferts, puis recalcule la liste. Les autres filtres recalculent la liste tout de suite. » Le filtre « Raccroché par » recalcule aussi la liste sans attendre.
- **:245** — « marques dont la durée médiane des transferts sur la période est sous le seuil, à partir de {{ minMeasured }} transfert(s) mesuré(s). » — « seules les marques avec au moins {{ minMeasured }} transfert(s) mesuré(s) sur la période, et une durée médiane sous le seuil, sont affichées. Par exemple, avec un seuil de {{ threshold }} s, une marque apparaît si plus de la moitié de ses transferts durent moins de {{ threshold }} s. »
- **:282** — « Rien à vérifier » — « Aucune marque à vérifier »
- **:329** — info-bulle « Appelant » — « Client »

## app/Http/Controllers/TelephonyController.php

- **:38 et :234** — « API téléphonie injoignable » — « Impossible de joindre le serveur des réglages de téléphonie. »
- **:84** — « Part des nouveaux numéros : entre 0 et 100 %. » — « La part des nouveaux numéros doit être entre 0 et 100 %. »
- **:85** — « Retour après une panne : entre 1 et 120 minutes. » — « Le délai de retour après une panne doit être entre 1 et 120 minutes. »
- **:86** — « Agents IA : entre 0 et 1000 appels par jour. » — « Le test des agents IA accepte entre 0 et 1 000 appels par jour. »
- **:87** — « Agents déplacés toutes les 30 s : entre 1 et 10. » — « Le nombre d'agents déplacés toutes les 30 s doit être entre 1 et 10. »
- **:88** — « Délai avant de redéplacer un agent : entre 0 et 60 minutes. » — « Le délai avant de redéplacer un agent doit être entre 0 et 60 minutes. »
- **:89** — « Seuil d'attente : entre 0 et 600 secondes. » — « L'attente qui déclenche un renfort doit être entre 0 et 600 secondes. »
- **:90** — « Durée minimale d'appel : entre 1 et 3600 secondes. » — « La durée minimale d'appel doit être entre 1 et 3 600 secondes. »
- **:91** — « Période : entre 1 et 720 heures. » — « La période doit être entre 1 et 720 heures. »
- **:109** — « code opérateur « {$code} » invalide (2 à 6 lettres majuscules ou chiffres). » — « le code opérateur « {$code} » doit faire 2 à 6 lettres majuscules ou chiffres. »
- **:114** — « {$code} : durées entières entre 0 et 600 secondes, minimum inférieur ou égal au maximum. » — « pour {$code}, les durées doivent être des nombres entiers entre 0 et 600 secondes, et le minimum ne doit pas dépasser le maximum. » Sinon, avec le préfixe de la ligne 74, on obtient trois deux-points à la suite.
- **:158** — « {$label} : ouverture et fermeture attendues. » — « il manque l'heure d'ouverture ou de fermeture du {$label}. » (préfixe « Horaires d'ouverture : » de la ligne 80)
- **:162** — « {$label} : heure invalide (format HH:MM, 23:59 au plus tard). » — « une heure du {$label} est invalide. Format HH:MM, 23:59 au plus tard. »
- **:166** — « {$label} : l'ouverture doit précéder la fermeture. » — « le {$label}, l'ouverture doit précéder la fermeture. »
- **:221** — « Mode inconnu : Automatique, Toujours notre serveur ou Toujours Voxnode. » — « Mode inconnu. Choisir Automatique, Toujours notre serveur ou Toujours Voxnode. »
- **:244** — « Erreur de l'API téléphonie (HTTP {status}) » — « Le serveur des réglages de téléphonie a renvoyé une erreur (HTTP {status}). »

---
