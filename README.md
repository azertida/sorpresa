# La Casquette

*Anciennement Sorpresa.* En hommage au patriarche et à sa casquette en tweed.

Les cadeaux en famille, sans gâcher la surprise.

1. **Ajoute tes envies** : un titre, une précision, un lien si tu veux.
2. **Réserve ce que tu offres** dans les listes des autres. Seuls les autres le voient, jamais la personne concernée.
3. **Quand tu reçois un cadeau**, retire-le de ta liste.

L'onglet **À offrir** reprend aussi l'usage de Mes Cadeaux : tes réservations et tes propres
idées (pour n'importe qui, du groupe ou non), avec prix, « acheté » et budget, visibles par toi seule.
Un fichier exporté de Mes Cadeaux s'importe depuis ⚙︎.

## Comment c'est fait

- `index.html`, `manifest.webmanifest`, `sw.js`, `icones/` : l'appli (PWA), servie par GitHub Pages.
- `serveur/api.php` : le petit serveur, sur alwaysdata, à côté de Grazie et Corsia.
  Les données vivent hors du site, dans `~/sorpresa-data/sorpresa.sqlite`.

Le secret est tenu **par le serveur** : quand quelqu'un regarde sa propre liste,
aucune information de réservation n'est envoyée à son téléphone.
Si une envie déjà réservée est retirée, la personne qui l'avait réservée en est prévenue.
Rien ne s'efface tout seul.

## Mise en ligne

1. Créer le dépôt `azertida/sorpresa` et y déposer tous les fichiers.
2. Activer GitHub Pages (branche `main`, dossier racine).
3. Copier `serveur/api.php` sur alwaysdata dans `www/sorpresa/api.php`.
4. L'appli appelle `https://azertida.alwaysdata.net/sorpresa/api.php`
   (constante `API_PAR_DEFAUT` dans `index.html`).
5. Ouvrir l'appli, « Créer un groupe », puis ⚙︎ → « Partager le lien ».

Le tout premier groupe se crée librement ; ensuite, seule une personne qui gère déjà
un groupe (sa clé de gestion est gardée sur son téléphone) peut en créer un autre.

## Animaux

Chacun choisit son animal (un par personne dans le groupe) : les 12 animaux aquatiques de Corsia
et 13 autres, soit 25. Images **Fluent Emoji** de Microsoft, licence MIT (`animaux/LICENCE-Fluent-Emoji.txt`).

## Plusieurs groupes

Un même téléphone peut faire partie de plusieurs groupes (famille, amies…) : on passe de l'un à l'autre
en touchant le nom du groupe, en haut. Le lien de secours les contient tous.

## Tirage au sort

La personne qui gère le groupe lance un tirage (⚙︎ → Tirage au sort) : participants, exclusions à sens unique,
un mot pour tout le monde. Le serveur tire au sort lui-même (personne ne tombe sur son propre nom, au moins trois personnes) ;
chacun découvre dans l'appli à qui il offre, et personne — gestionnaire comprise — ne voit l'ensemble des paires.
