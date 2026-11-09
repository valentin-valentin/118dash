# 118dash : système de design

Outil professionnel, utilisé toute la journée. Chaque écran doit être **lisible d'un coup d'œil, cohérent avec les autres, sans surprise**.
Ces règles ne sont pas des suggestions. Un écran qui en enfreint une n'est pas terminé.

## 1. Principes

1. **Rien d'inventé.** Chaque élément reprend un élément existant (section 3), avec ses classes exactes. Si aucun ne convient, on demande avant d'en créer un.
2. **Une décision par élément.** Pour chaque bouton, libellé, couleur, bloc ou texte, on sait dire pourquoi il est là, à cette place, sous cette forme (section 5). Rien par réflexe, rien « au cas où ».
3. **Le plus simple qui répond au besoin.** Pas de case, de motif, d'option, de colonne, d'étape ou de texte en plus pour un cas rare.
4. **Les faits, avec les mots du métier.** Pas de terme inventé (« Perdu », « Pénalisé »…), pas de phrase creuse, rien de temporaire (« en attendant », date de mise en service). « — » quand c'est vide.

## 2. Mise en page

| Règle | Détail |
|---|---|
| Largeur | Décidée par la page via le layout, jamais à la main. `<AppLayout>` = toute la largeur (tableaux, statistiques, grilles). `<AppLayout width="form">` = 896 px (`max-w-4xl`) pour les formulaires, fiches, réglages et pages d'action. |
| Page | `<div class="space-y-6 p-6">`, `PageHeader` avec le fil d'Ariane. Les boutons de l'en-tête (`#actions`) servent à naviguer ou ouvrir une autre page (« Nouvelle marque »), jamais à valider ce qui est sur la page. |
| Blocs | Un sujet par bloc. Les blocs suivent l'ordre dans lequel l'utilisateur en a besoin. Les actions qui terminent la page sont en bas. |
| Ligne de formulaire | `grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]`. En largeur `form`, la colonne de droite fait environ **620 px** : tout ce qui est sur une ligne (champ + bouton) doit y **tenir sur une seule ligne**. Sinon : raccourcir le libellé, réduire le champ, ou passer sur une ligne séparée exprès. |
| Tableaux | Compacts : valeurs près des libellés. Plusieurs petits tableaux par thème plutôt qu'un grand tableau large. Jamais de cartes à gros chiffres façon modèle de tableau de bord. |
| Texte d'aide | Sous le titre du bloc (`p text-sm text-gray-500`) ou sous le champ. Un fait concret (ce qui se passe, une règle, une adresse) ou rien. |

### Largeurs des champs (dans une ligne de formulaire)

| Champ | Classe |
|---|---|
| Nom, libellé court | `h-9 w-72` |
| Numéro de téléphone | `h-9 w-56 tabular-nums` |
| Liste courte (pays…) | `<select>` natif `w-40` |
| Liste moyenne (catégorie…) | `<select>` natif `w-56` |
| Nombre | `h-9 w-24 text-right tabular-nums` + unité à droite |
| Texte long (note, autres noms) | `Textarea` pleine largeur de la colonne |

## 3. Composants et modèles à copier

| Élément | Modèle |
|---|---|
| Fiche (créer ou modifier un enregistrement : marque, source, numéro…) | `Card` / `CardHeader` + `CardTitle` / `CardContent class="grid gap-4 md:grid-cols-2"`, champs `space-y-2` avec `Label` au-dessus, interrupteurs `Switch`, boutons en bas à droite hors carte (`flex justify-end gap-4`). **Tout écran qui crée ou modifie le même type d'enregistrement reprend exactement la même fiche** (mêmes cartes, mêmes libellés, mêmes contrôles, même ordre) — `BrandForm.vue` (repris par `BrandRequest.vue`) |
| Réglages | carte `rounded-lg border border-gray-100 bg-white` ; en-tête `border-b border-gray-100 px-5 py-3` (`h2 font-semibold text-gray-900`, `p text-sm text-gray-500`) ; lignes `divide-y divide-gray-50` ; pied `flex items-center justify-end gap-3 border-t border-gray-100 px-5 py-3` — `SipServerSettings.vue` |
| Tableau de données | titre `h2 mb-2 text-sm font-semibold text-gray-900` au-dessus d'un `rounded-lg border border-gray-100 bg-white` contenant `DataTable` — `SipServer.vue` (Agents en ligne) |
| Ligne sélectionnée (tableau qui pilote un bloc en dessous) | `DataTable` avec `:row-click` (curseur main sur les lignes) et `:row-class` : `bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)]` — `BrandRequest.vue` (appel dont la transcription est affichée) |
| Filtres | `FilterBar` + `FilterSelect` / `Input h-8`, tous visibles — `Brands.vue`, `Calls.vue` |
| Liste de choix fixe | `<select>` natif `flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm` — `BrandForm.vue`. `FilterSelect` seulement pour un filtre ou une recherche dans une longue liste. |
| Boutons de choix (période…) | `h-8 rounded-md px-3 text-xs font-medium`, actif `bg-gray-900 text-white`, sinon `border border-gray-200 bg-white text-gray-600 hover:bg-gray-100` — `WaitLoss.vue` |
| Alerte en bloc | `flex items-start gap-3 rounded-lg border p-4 text-sm`, icône `h-5 w-5`, titre `font-medium`, ligne d'explication, lien souligné. Rouge (`border-red-200 bg-red-50 text-red-800`) pour une erreur — `RsvaAudit.vue` ; ambre pour une information à traiter — `BrandRequestsNotice.vue` |
| Alerte sur une ligne | `flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700` + `AlertTriangle h-4 w-4` — `SipServer.vue` |
| Statut | pastille `inline-flex rounded-full px-2 py-1 text-xs font-medium` (`bg-green-100 text-green-700`, `bg-gray-100 text-gray-700`, `bg-amber-100 text-amber-700`, `bg-blue-100 text-blue-700`) — `Brands.vue` |
| Contrôles | `Button`, `Input`, `Label`, `Checkbox`, `Textarea` de `components/ui`, le `<select>` natif, `FilterSelect`. Jamais de bouton ou de liste stylés à la main. |
| Chargement | squelettes `animate-pulse rounded bg-gray-50`, ou ceux de `DataTable` |

## 4. Boutons

| Variante | Quand |
|---|---|
| `default` (noir) | **L'action la plus probable de l'écran, une seule.** Si deux actions se valent, on choisit celle que l'utilisateur fera le plus souvent, et on sait dire pourquoi. Jamais noir par réflexe (« c'est un submit »). |
| `outline` (blanc à bordure) | Les autres actions, et les actions répétées sur chaque ligne d'une liste. |
| `ghost` | Actions discrètes qui ne changent rien d'important (effacer un historique, rouvrir). |
| `destructive` | Raccrocher, supprimer : ce qui coupe ou détruit tout de suite. Avec confirmation si c'est irréversible. |

Taille `sm` dans les cartes et les tableaux ; taille normale dans l'en-tête de page.
**Libellé = verbe + objet, qui dit exactement ce qui se passe au clic** : « Associer à cette marque », « Marquer la demande comme traitée », « Créer la marque ». Jamais « C'est celle-ci », « OK », « Valider », « Traiter » sans objet si le contexte ne le rend pas évident. Court : il doit tenir sur la ligne (section 2).

## 5. Avant d'écrire un élément : les questions

1. **Modèle** : quel élément existant je reprends (fichier, classes) ?
2. **Hiérarchie** : est-ce l'action principale de l'écran ? Sinon, quelle variante ?
3. **Libellé** : dit-il exactement ce qui se passe ?
4. **Nécessité** : sert-il vraiment ? Que perd-on sans lui ?
5. **Place** : est-il dans le bon bloc, au bon moment du parcours ?
6. **Taille** : tient-il sur sa ligne à la largeur de la page (section 2) ?

## 6. Vérification obligatoire avant d'annoncer que c'est fini

**Outil : `npm run ui:check -- /chemin [/autre-chemin…]`** (`scripts/ui-check.mjs`). Pour chaque page touchée, à 1440 px, il fait la capture (dans `storage/ui-check/`) et signale : débordement de la page, retours à la ligne, contenu qui déborde ou texte coupé, plus d'un bouton principal noir, erreurs JavaScript. Il doit finir sans aucun « ✗ ». Un retour à la ligne ou un texte coupé **voulu** se déclare dans le template avec `data-ui-ok` (sur l'élément ou un parent), avec un commentaire qui dit pourquoi. Le script ne remplace pas l'œil : on regarde ensuite chaque capture bloc par bloc.


1. Capture à **1440 px**, page entière, avec des données réalistes (les plus longues qu'on aura : noms longs, beaucoup de lignes, et aussi l'état vide).
2. **Regarder chaque bloc** de la capture, pas seulement l'ensemble. Pour chacun :
   - rien n'est passé à la ligne sans le vouloir (bouton sous un champ, texte coupé, en-tête sur 3 lignes) ;
   - rien ne déborde, rien n'est tronqué ;
   - alignements : libellés dans la même colonne, champs alignés, boutons alignés à droite dans les pieds ;
   - une seule action en noir ;
   - les espacements sont ceux du modèle.
3. Lancer la détection automatique des retours à la ligne dans la page (console du navigateur, ou `page.evaluate` dans Playwright) :
   ```js
   [...document.querySelectorAll('.flex-wrap')].filter(el => {
     const r = [...el.children].filter(c => c.offsetParent).map(c => c.getBoundingClientRect())
     return r.length > 1 && r.some(x => x.top >= r[0].bottom - 1)
   }).map(el => el.innerText.replace(/\s+/g, ' ').slice(0, 80))
   ```
   Et vérifier qu'aucun élément ne déborde de sa ligne : `document.documentElement.scrollWidth` doit valoir la largeur de la fenêtre.
   Chaque résultat doit être un retour à la ligne **voulu**. Sinon, corriger.
4. Comparer côte à côte avec la page modèle reprise (même carte, même espacement, mêmes boutons).
5. **Écran qui en reprend un autre** (même enregistrement, même fonction) : lister les éléments de l'original (cartes, champs, libellés, contrôles, boutons, dans l'ordre), lister ceux du nouvel écran, comparer ligne à ligne. Chaque différence est corrigée, ou justifiée dans le compte rendu. Une différence non justifiée = écran non terminé.
6. Si un élément manque ou diffère et qu'on demande pourquoi : dire ce qui s'est réellement passé (décision, ou absence de vérification), pas « oublié ».
7. Dans le compte rendu au propriétaire : les décisions prises (action principale et pourquoi, modèle repris, ce qui a été écarté) et ce qui a été vérifié.
