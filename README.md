# Sorpresa

Des listes d'envies entre proches, sans gâcher la surprise.

1. **Ajoute tes envies** : un titre, une précision, un lien si tu veux.
2. **Réserve ce que tu offres** dans les listes des autres. Seuls les autres le voient, jamais la personne concernée.
3. **Quand tu reçois un cadeau**, retire-le de ta liste.

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
