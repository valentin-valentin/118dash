# 118dash : règles pour Claude

## Interface : système de design obligatoire

Toute modification d'interface suit **le système de design du panel**, sans exception :

@docs/design-system.md

À relire avant d'écrire la moindre ligne de template. Un écran n'est terminé qu'une fois la vérification de la section 6 faite : `npm run ui:check -- <pages touchées>` sans aucun « ✗ », chaque capture regardée bloc par bloc, et les décisions listées dans le compte rendu.

## Façon de travailler
- La solution la plus simple d'abord : pas de file d'attente, de cache long, de colonne ni de règle en plus pour un cas rare.
- Ne pas commiter ni pousser : le propriétaire le fait.
- Tester le softphone avec le 0891244993, jamais le 0891242331.
