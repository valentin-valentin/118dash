# 118dash : règles pour Claude

## Langage visuel : obligatoire, sans exception

Le panel a déjà son langage visuel. **Ne rien inventer** : ni bandeau, ni bouton, ni marge, ni couleur, ni mise en page.
Avant d'écrire un élément visuel, chercher son équivalent dans `resources/js/pages` et en reprendre **les classes exactes**.
Une maquette fournie (par exemple par la session voxnode118) s'adapte à ce langage : on ne la recopie pas telle quelle.

| Élément | Modèle à copier |
|---|---|
| Page | `<div class="space-y-6 p-6">` + `PageHeader` (fil d'Ariane ; boutons dans `#actions`) — `Brands.vue` |
| Largeur | décidée par la page via le layout, jamais à la main : `<AppLayout>` (toute la largeur : tableaux, statistiques, grilles) ou `<AppLayout width="form">` (formulaires, fiches, réglages, pages d'action) — `layouts/AppLayout.vue` |
| Liste de choix fixe | `<select>` natif `flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm` — `BrandForm.vue` ; `FilterSelect` seulement pour les filtres ou une recherche dans une longue liste |
| Filtres | `FilterBar` + `FilterSelect` / `Input h-8`, tous visibles — `Brands.vue`, `Calls.vue` |
| Tableau de données | titre `h2 mb-2 text-sm font-semibold text-gray-900` au-dessus d'un `rounded-lg border border-gray-100 bg-white` contenant `DataTable` — `SipServer.vue` (Agents en ligne) |
| Formulaire / réglages | carte `rounded-lg border border-gray-100 bg-white` ; en-tête `border-b border-gray-100 px-5 py-3` (`h2 font-semibold`, `p text-sm text-gray-500`) ; lignes `divide-y divide-gray-50`, `grid gap-3 px-5 py-4 sm:grid-cols-[13rem_1fr]` ; pied `border-t px-5 py-3` avec `Button size="sm"` — `SipServerSettings.vue` |
| Alerte en bloc | `flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800`, icône `h-5 w-5`, titre `font-medium`, ligne d'explication, lien souligné — `RsvaAudit.vue` (ambre si ce n'est pas une erreur : `BrandRequestsNotice.vue`) |
| Alerte sur une ligne | `flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700` + `AlertTriangle h-4 w-4` — `SipServer.vue` |
| Statut | pastille `inline-flex rounded-full px-2 py-1 text-xs font-medium` (`bg-green-100 text-green-700`, `bg-gray-100 text-gray-700`…) — `Brands.vue` |
| Boutons de choix | `h-8 rounded-md px-3 text-xs font-medium`, actif `bg-gray-900 text-white`, sinon `border border-gray-200 bg-white text-gray-600 hover:bg-gray-100` — `WaitLoss.vue` |
| Contrôles | `Button`, `Input`, `Label`, `Checkbox`, `Textarea` de `components/ui`, le `<select>` natif ci-dessus, `FilterSelect` — jamais de bouton ou de liste stylés autrement |
| Chargement | squelettes `animate-pulse rounded bg-gray-50` (ou ceux de `DataTable`) |

Et toujours :
- Tableaux compacts : valeurs près des libellés ; plusieurs petits tableaux par thème plutôt qu'un grand tableau large.
- Jamais de cartes à gros chiffres façon modèle de tableau de bord.
- Un sujet par bloc ; ne pas mélanger des blocs sans rapport.
- Texte d'aide : un fait concret (une adresse, une règle) ou rien. Pas de phrase creuse, pas de « dans un autre onglet ».
- Rien de temporaire dans l'interface : pas de date de mise en service, pas de « en attendant ». « — » si vide.
- Pas de nouveaux termes inventés (« Perdu », « Pénalisé »…) : les faits, avec les mots du métier.
- Vérifier le rendu par une capture à 1440 px avant d'annoncer que c'est fini, et le comparer à une page existante.

## Façon de travailler
- La solution la plus simple d'abord : pas de file d'attente, de cache long, de colonne ni de règle en plus pour un cas rare.
- Ne pas commiter ni pousser : le propriétaire le fait.
- Tester le softphone avec le 0891244993, jamais le 0891242331.
