<?php
/*
 * La Casquette (ex-Sorpresa) — petit serveur des listes d'envies entre proches
 *
 * Principes (repris de Grazie v2) :
 *  - un « groupe » = un code secret partagé par lien ; sans code, on ne voit rien ;
 *    la gestion peut changer ce code : l'ancien lien ne mène plus nulle part,
 *    et les membres retrouvent seuls le nouveau grâce à leur place de membre ;
 *  - pour entrer, il suffit du lien et d'un prénom ; le téléphone reçoit un jeton
 *    que lui seul connaît (le serveur n'en garde qu'une empreinte) ;
 *  - chacun tient sa liste d'envies : un titre, une précision, un lien facultatif ;
 *  - on réserve dans les listes des autres ; LA PERSONNE CONCERNÉE NE LE VOIT JAMAIS :
 *    le serveur ne lui envoie aucune information de réservation sur ses propres envies ;
 *  - si quelqu'un retire une envie déjà réservée, celle ou celui qui l'avait réservée
 *    le voit (« Retirée ») jusqu'à ce qu'elle ou il le note comme vu ;
 *  - rien ne s'efface tout seul : une envie reste tant qu'on ne la retire pas ;
 *  - chacun peut aussi tenir des NOTES PRIVÉES (idées de cadeaux, pour quelqu'un du groupe
 *    ou non, avec prix et « acheté ») et suivre prix et achat de ses réservations :
 *    tout cela n'est jamais envoyé qu'à la personne qui l'a noté ;
 *  - quitter le groupe (ou en être retiré) efface tout ce qui concerne la personne ;
 *  - TIRAGE AU SORT : la personne qui gère le groupe le lance (participants, exclusions à sens unique) ;
 *    le serveur tire au sort lui-même et ne montre à chacun que la personne à qui il offre —
 *    personne, gestionnaire comprise, ne voit l'ensemble des paires ;
 *  - aucune adresse IP ni autre trace n'est enregistrée par ce script.
 *
 * Emplacement : www/sorpresa/api.php — les données vivent hors du site,
 * dans ~/sorpresa-data/sorpresa.sqlite (créé automatiquement).
 */

date_default_timezone_set('Europe/Brussels');

const ORIGINES_AUTORISEES = ['https://azertida.github.io'];
const MAX_PRENOM = 20;
const MAX_TITRE = 80;
const MAX_PRECISION = 200;
const MAX_LIEN = 500;
const MAX_NOM_GROUPE = 40;
const MAX_MEMBRES_PAR_GROUPE = 30;
const MAX_ENVIES_PAR_PERSONNE = 60;
const MAX_REQUETE_OCTETS = 200000;          // un import depuis Mes Cadeaux peut compter des dizaines d'idées
const MAX_NOTES_PAR_PERSONNE = 300;
const MAX_TIRAGES_PAR_GROUPE = 20;
const MAX_NOM_TIRAGE = 40;
const MAX_MOT_TIRAGE = 200;
const MAX_POUR = 40;
const MAX_PRIX = 100000;
/* Les animaux que l'on peut choisir (les images vivent dans l'appli, dossier animaux/). */
const ANIMAUX = ['poulpe', 'capybara', 'loutre', 'tortue', 'meduse', 'dauphin', 'phoque', 'manchot', 'baleine', 'grenouille', 'raie', 'hippocampe',
                 'renard', 'herisson', 'ecureuil', 'chouette', 'chat', 'chien', 'abeille', 'panda', 'koala', 'paresseux',
                 'perroquet', 'paon', 'papillon'];

/* ---------- En-têtes ---------- */

$origine = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origine, ORIGINES_AUTORISEES, true) || getenv('SORPRESA_TEST') === '1') {
    header('Access-Control-Allow-Origin: ' . ($origine !== '' ? $origine : '*'));
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ---------- Outils ---------- */

function repondre($donnees, int $code = 200): void {
    http_response_code($code);
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

function erreur(string $message, int $code = 400): void {
    repondre(['erreur' => $message], $code);
}

function aleatoire(int $octets): string {
    return bin2hex(random_bytes($octets));
}

function empreinte(string $jeton): string {
    return hash('sha256', $jeton);
}

function jeton_ok(string $hash, $jeton): bool {
    return $hash !== '' && is_string($jeton) && $jeton !== '' && hash_equals($hash, empreinte($jeton));
}

function texte($valeur, int $max): string {
    $t = trim(preg_replace('/\s+/u', ' ', (string)($valeur ?? '')));
    $t = preg_replace('/[\x00-\x1F\x7F]/u', '', $t);
    if (mb_strlen($t) > $max) $t = mb_substr($t, 0, $max);
    return $t;
}

function prenom_valide($valeur): string {
    $p = texte($valeur, MAX_PRENOM);
    if ($p === '') erreur('Prénom manquant');
    return $p;
}

/* Un lien facultatif : http ou https seulement. */
function lien_valide($valeur): string {
    $l = texte($valeur, MAX_LIEN);
    if ($l === '') return '';
    if (!preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $l) || !filter_var($l, FILTER_VALIDATE_URL)) erreur('Ce lien n\'est pas une adresse web valable');
    return $l;
}

/* Un prix facultatif, en euros : nombre positif, ou rien. */
function prix_valide($valeur): ?float {
    if ($valeur === null || $valeur === '') return null;
    if (is_string($valeur)) $valeur = str_replace([',', ' ', '€'], ['.', '', ''], $valeur);
    if (!is_numeric($valeur)) erreur('Ce prix n\'est pas un nombre');
    $p = round((float)$valeur, 2);
    if ($p < 0 || $p > MAX_PRIX) erreur('Ce prix semble étrange');
    return $p;
}

/* Un animal facultatif, pris dans la liste, et pas déjà choisi par quelqu'un d'autre du groupe. */
function animal_valide(PDO $db, string $groupe, $valeur, string $sauf = ''): string {
    $a = (string)($valeur ?? '');
    if ($a === '') return '';
    if (!in_array($a, ANIMAUX, true)) erreur('Animal inconnu');
    $st = $db->prepare('SELECT 1 FROM membres WHERE groupe = ? AND animal = ? AND id <> ?');
    $st->execute([$groupe, $a, $sauf]);
    if ($st->fetchColumn()) erreur('Cet animal vient d\'être choisi par quelqu\'un d\'autre', 409);
    return $a;
}

/* Mélange équitable (Fisher-Yates) avec un hasard cryptographique. */
function melanger(array $t): array {
    for ($i = count($t) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$t[$i], $t[$j]] = [$t[$j], $t[$i]];
    }
    return $t;
}

/* Tirage uniforme parmi les combinaisons valides : on mélange jusqu'à en trouver une qui respecte
   toutes les règles ; si c'est trop rare, une recherche systématique tranche entre « rare » et « impossible ».
   $autorises[donneur] = liste des receveurs permis. Renvoie [donneur => receveur] ou null. (Repris de La Casquette v1.) */
function tirer(array $noms, array $autorises): ?array {
    $n = count($noms);
    $permis = [];
    foreach ($autorises as $g => $l) $permis[$g] = array_flip($l);
    for ($essai = 0; $essai < 200000; $essai++) {
        $p = melanger($noms);
        $ok = true;
        for ($i = 0; $i < $n; $i++) { if (!isset($permis[$noms[$i]][$p[$i]])) { $ok = false; break; } }
        if ($ok) return array_combine($noms, $p);
    }
    $ordre = $noms;
    usort($ordre, fn($a, $b) => count($autorises[$a]) <=> count($autorises[$b]));
    $pris = []; $r = [];
    $etape = function (int $i) use (&$etape, $ordre, $autorises, &$pris, &$r): bool {
        if ($i === count($ordre)) return true;
        $g = $ordre[$i];
        foreach (melanger($autorises[$g]) as $x) {
            if (isset($pris[$x])) continue;
            $pris[$x] = true; $r[$g] = $x;
            if ($etape($i + 1)) return true;
            unset($pris[$x], $r[$g]);
        }
        return false;
    };
    return $etape(0) ? $r : null;
}

function joli_liste(array $t): string {
    if (count($t) <= 1) return implode('', $t);
    $dernier = array_pop($t);
    return implode(', ', $t) . ' et ' . $dernier;
}

function aujourdhui(): string { return date('Y-m-d'); }
function maintenant(): string { return date('Y-m-d H:i:s'); }

/* Un alias stable pour désigner une personne sans jamais montrer son identifiant de membre. */
function alias(string $groupe, string $membre): string {
    return substr(hash('sha256', $groupe . '/' . $membre), 0, 10);
}

function base(): PDO {
    $dossier = getenv('SORPRESA_DATA') ?: dirname(__DIR__, 2) . '/sorpresa-data';
    if (!is_dir($dossier) && !mkdir($dossier, 0700, true)) {
        erreur('Stockage indisponible', 500);
    }
    $db = new PDO('sqlite:' . $dossier . '/sorpresa.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 3000');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('
        CREATE TABLE IF NOT EXISTS groupes (
            code        TEXT PRIMARY KEY,            -- identifiant interne, ne change jamais
            lien        TEXT NOT NULL UNIQUE,        -- code public du lien, peut changer
            admin_hash  TEXT NOT NULL,
            nom         TEXT NOT NULL DEFAULT \'\'
        );
        CREATE TABLE IF NOT EXISTS membres (
            id          TEXT PRIMARY KEY,
            groupe      TEXT NOT NULL REFERENCES groupes(code) ON DELETE CASCADE,
            prenom      TEXT NOT NULL,
            jeton_hash  TEXT NOT NULL,
            arrivee     TEXT NOT NULL,
            parrain     TEXT NOT NULL DEFAULT \'\'
        );
        CREATE TABLE IF NOT EXISTS envies (
            id          TEXT PRIMARY KEY,
            groupe      TEXT NOT NULL REFERENCES groupes(code) ON DELETE CASCADE,
            membre      TEXT NOT NULL,               -- à qui est cette envie
            prenom      TEXT NOT NULL,               -- prénom au moment du retrait (pour « Retirée par … »)
            titre       TEXT NOT NULL,
            precision   TEXT NOT NULL DEFAULT \'\',
            lien        TEXT NOT NULL DEFAULT \'\',
            cree        TEXT NOT NULL,
            modifiee    TEXT NOT NULL DEFAULT \'\',
            res_membre  TEXT NOT NULL DEFAULT \'\',   -- qui a réservé (jamais montré à la personne concernée)
            res_date    TEXT NOT NULL DEFAULT \'\',
            retiree     TEXT NOT NULL DEFAULT \'\'    -- date du retrait d\'une envie réservée
        );
        CREATE INDEX IF NOT EXISTS idx_envies ON envies(groupe, membre);
        CREATE TABLE IF NOT EXISTS notes (
            id          TEXT PRIMARY KEY,
            groupe      TEXT NOT NULL REFERENCES groupes(code) ON DELETE CASCADE,
            membre      TEXT NOT NULL,               -- à qui est cette note (seule personne à la voir)
            pour        TEXT NOT NULL,               -- pour qui est le cadeau (prénom libre)
            titre       TEXT NOT NULL,
            prix        REAL,
            achete      INTEGER NOT NULL DEFAULT 0,
            cree        TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_notes ON notes(groupe, membre);
        CREATE TABLE IF NOT EXISTS tirages (
            id          TEXT PRIMARY KEY,
            groupe      TEXT NOT NULL REFERENCES groupes(code) ON DELETE CASCADE,
            nom         TEXT NOT NULL,
            mot         TEXT NOT NULL DEFAULT \'\',
            cree        TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS paires (
            tirage      TEXT NOT NULL REFERENCES tirages(id) ON DELETE CASCADE,
            donneur     TEXT NOT NULL,               -- membre qui offre
            receveur    TEXT NOT NULL,               -- membre à qui il offre (vide s\'il a quitté le groupe)
            prenom      TEXT NOT NULL,               -- prénom de la personne tirée, au moment du tirage
            PRIMARY KEY (tirage, donneur)
        );
    ');
    // Suivi privé des réservations : prix et « acheté », vus seulement par qui a réservé.
    $cols = array_column($db->query('PRAGMA table_info(envies)')->fetchAll(), 'name');
    if (!in_array('res_prix', $cols, true))   $db->exec('ALTER TABLE envies ADD COLUMN res_prix REAL');
    if (!in_array('res_achete', $cols, true)) $db->exec('ALTER TABLE envies ADD COLUMN res_achete INTEGER NOT NULL DEFAULT 0');
    $cm = array_column($db->query('PRAGMA table_info(membres)')->fetchAll(), 'name');
    if (!in_array('animal', $cm, true)) $db->exec('ALTER TABLE membres ADD COLUMN animal TEXT NOT NULL DEFAULT \'\'');
    return $db;
}

function groupe(PDO $db, string $lien): array {
    $st = $db->prepare('SELECT * FROM groupes WHERE lien = ?');
    $st->execute([$lien]);
    $g = $st->fetch();
    if (!$g) erreur('Groupe inconnu', 404);
    return $g;
}

function est_admin(array $groupe, $cle): bool {
    return is_string($cle) && $cle !== '' && hash_equals($groupe['admin_hash'], empreinte($cle));
}

function membre(PDO $db, string $groupe, $id, $jeton): array {
    $st = $db->prepare('SELECT * FROM membres WHERE id = ? AND groupe = ?');
    $st->execute([(string)($id ?? ''), $groupe]);
    $m = $st->fetch();
    if (!$m || !jeton_ok($m['jeton_hash'], $jeton)) erreur('Membre inconnu', 403);
    return $m;
}

function envie(PDO $db, string $groupe, $id): array {
    $st = $db->prepare('SELECT * FROM envies WHERE id = ? AND groupe = ?');
    $st->execute([(string)($id ?? ''), $groupe]);
    $e = $st->fetch();
    if (!$e) erreur('Cette envie n\'existe plus', 404);
    return $e;
}

/* Retirer une envie : effacée si personne ne l'a réservée,
   sinon gardée pour prévenir la personne qui l'avait réservée. */
function retirer_envie(PDO $db, array $e, string $prenom): void {
    if ($e['res_membre'] === '') {
        $db->prepare('DELETE FROM envies WHERE id = ?')->execute([$e['id']]);
    } else {
        $db->prepare('UPDATE envies SET retiree = ?, prenom = ? WHERE id = ?')->execute([aujourdhui(), $prenom, $e['id']]);
    }
}

/* Quitter le groupe, ou en être retiré : ses envies partent (en prévenant qui les avait réservées),
   ses réservations sont libérées, et ce qu'on lui signalait encore est effacé. */
function effacer_membre(PDO $db, string $groupe, string $id): void {
    $st = $db->prepare('SELECT * FROM envies WHERE groupe = ? AND membre = ? AND retiree = \'\'');
    $st->execute([$groupe, $id]);
    foreach ($st->fetchAll() as $e) retirer_envie($db, $e, $e['prenom']);
    $db->prepare('DELETE FROM envies WHERE groupe = ? AND res_membre = ? AND retiree <> \'\'')->execute([$groupe, $id]);
    $db->prepare('UPDATE envies SET res_membre = \'\', res_date = \'\', res_prix = NULL, res_achete = 0 WHERE groupe = ? AND res_membre = ?')->execute([$groupe, $id]);
    $db->prepare('DELETE FROM notes WHERE groupe = ? AND membre = ?')->execute([$groupe, $id]);
    // Tirages : on retire ce qu'elle devait offrir ; qui l'avait tirée verra qu'elle a quitté le groupe.
    $db->prepare('DELETE FROM paires WHERE donneur = ? AND tirage IN (SELECT id FROM tirages WHERE groupe = ?)')->execute([$id, $groupe]);
    $db->prepare('UPDATE paires SET receveur = \'\' WHERE receveur = ? AND tirage IN (SELECT id FROM tirages WHERE groupe = ?)')->execute([$id, $groupe]);
    $db->prepare('DELETE FROM membres WHERE groupe = ? AND id = ?')->execute([$groupe, $id]);
    // Un groupe dont la dernière personne est partie disparaît avec tout son contenu.
    $db->prepare('DELETE FROM groupes WHERE code = ? AND NOT EXISTS (SELECT 1 FROM membres WHERE groupe = ?)')->execute([$groupe, $groupe]);
}

/* ---------- Lecture de la requête ---------- */

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$entree = [];
if ($methode === 'POST') {
    $brut = file_get_contents('php://input', false, null, 0, MAX_REQUETE_OCTETS);
    $entree = json_decode($brut ?: '[]', true);
    if (!is_array($entree)) erreur('Requête illisible');
} elseif ($methode !== 'GET') {
    erreur('Méthode non prise en charge', 405);
}
$source = $methode === 'GET' ? $_GET : $entree;
$action = (string)($source['action'] ?? '');
$code   = $source['g'] ?? '';

try {
    $db = base();

    /* ----- Créer un groupe -----
       Le tout premier groupe peut être créé librement ; ensuite, il faut la clé de gestion d'un groupe existant. */
    if ($action === 'creer_groupe' && $methode === 'POST') {
        // Un groupe resté sans personne (créé puis quitté) ne compte pas.
        $db->exec('DELETE FROM groupes WHERE NOT EXISTS (SELECT 1 FROM membres WHERE membres.groupe = groupes.code)');
        $nb = (int)$db->query('SELECT COUNT(*) FROM groupes')->fetchColumn();
        if ($nb > 0) {
            $ok = false;
            $cle = $entree['cle_existante'] ?? '';
            if (is_string($cle) && $cle !== '') {
                $st = $db->prepare('SELECT 1 FROM groupes WHERE admin_hash = ?');
                $st->execute([empreinte($cle)]);
                $ok = (bool)$st->fetchColumn();
            }
            if (!$ok) erreur('Création réservée', 403);
        }
        $interne = aleatoire(9);
        $lien = aleatoire(9);
        $admin = aleatoire(16);
        $db->prepare('INSERT INTO groupes (code, lien, admin_hash, nom) VALUES (?, ?, ?, ?)')
           ->execute([$interne, $lien, empreinte($admin), texte($entree['nom'] ?? '', MAX_NOM_GROUPE)]);
        repondre(['g' => $lien, 'admin' => $admin]);
    }

    /* ----- Retrouver son groupe après un changement de lien : la place de membre suffit ----- */
    if ($action === 'retrouver' && $methode === 'POST') {
        $st = $db->prepare('SELECT m.jeton_hash, g.lien, g.nom FROM membres m JOIN groupes g ON g.code = m.groupe WHERE m.id = ?');
        $st->execute([(string)($entree['m'] ?? '')]);
        $r = $st->fetch();
        if (!$r || !jeton_ok($r['jeton_hash'], $entree['j'] ?? '')) erreur('Membre inconnu', 403);
        repondre(['g' => $r['lien'], 'nom' => $r['nom']]);
    }

    if (!is_string($code) || !preg_match('/^[0-9a-f]{18}$/', $code)) erreur('Code de groupe manquant', 400);
    $grp = groupe($db, $code);
    $code = $grp['code'];   // à partir d'ici : l'identifiant interne
    $cle_admin = $source['admin'] ?? '';

    if ($action !== 'liste' && $action !== 'apercu' && $methode !== 'POST') erreur('POST attendu', 405);

    /* ----- Avant d'entrer : le nom du groupe, pour l'écran d'accueil ----- */
    if ($action === 'apercu') {
        repondre(['nom' => $grp['nom']]);
    }

    /* ----- Entrer dans le groupe : il suffit du lien et d'un prénom ----- */
    if ($action === 'rejoindre') {
        $prenom = prenom_valide($entree['prenom'] ?? '');
        $st = $db->prepare('SELECT COUNT(*) FROM membres WHERE groupe = ?');
        $st->execute([$code]);
        if ((int)$st->fetchColumn() >= MAX_MEMBRES_PAR_GROUPE) erreur('Ce groupe est complet');
        $parrain = '';
        $alias_parrain = (string)($entree['parrain'] ?? '');
        if ($alias_parrain !== '') {
            $st = $db->prepare('SELECT id FROM membres WHERE groupe = ?');
            $st->execute([$code]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                if (alias($code, $mid) === $alias_parrain) { $parrain = $mid; break; }
            }
        }
        $animal = animal_valide($db, $code, $entree['animal'] ?? '');
        $id = aleatoire(6);
        $jeton = aleatoire(16);
        $db->prepare('INSERT INTO membres (id, groupe, prenom, jeton_hash, arrivee, parrain, animal) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$id, $code, $prenom, empreinte($jeton), aujourdhui(), $parrain, $animal]);
        repondre(['m' => $id, 'j' => $jeton]);
    }

    /* Toutes les autres actions demandent d'être membre. */
    $moi = membre($db, $code, $source['m'] ?? '', $source['j'] ?? '');

    switch ($action) {

        /* ----- Tout ce que ce téléphone a le droit de voir ----- */
        case 'liste':
            $st = $db->prepare('SELECT id, prenom, animal FROM membres WHERE groupe = ? ORDER BY prenom COLLATE NOCASE');
            $st->execute([$code]);
            $personnes = [];
            $prenoms = [];
            foreach ($st->fetchAll() as $p) {
                $prenoms[$p['id']] = $p['prenom'];
                $personnes[] = ['p' => alias($code, $p['id']), 'prenom' => $p['prenom'], 'animal' => $p['animal'], 'moi' => $p['id'] === $moi['id']];
            }

            $st = $db->prepare('SELECT * FROM envies WHERE groupe = ? ORDER BY cree DESC, rowid DESC');
            $st->execute([$code]);
            $envies = [];
            foreach ($st->fetchAll() as $e) {
                $mienne = $e['membre'] === $moi['id'];
                $ma_res = $e['res_membre'] !== '' && $e['res_membre'] === $moi['id'];
                if ($e['retiree'] !== '') {
                    // Une envie retirée n'est montrée qu'à la personne qui l'avait réservée.
                    if (!$ma_res) continue;
                    $envies[] = ['id' => $e['id'], 'p' => alias($code, $e['membre']), 'prenom' => $prenoms[$e['membre']] ?? $e['prenom'],
                                 'titre' => $e['titre'], 'precision' => $e['precision'], 'lien' => $e['lien'],
                                 'retiree' => $e['retiree'], 'ma_reservation' => true,
                                 'prix' => $e['res_prix'] !== null ? (float)$e['res_prix'] : null, 'achete' => (int)$e['res_achete'] === 1];
                    continue;
                }
                $sortie = ['id' => $e['id'], 'p' => alias($code, $e['membre']), 'titre' => $e['titre'],
                           'precision' => $e['precision'], 'lien' => $e['lien'], 'cree' => $e['cree'], 'mienne' => $mienne];
                if (!$mienne) {
                    // Les informations de réservation ne quittent le serveur que pour les autres.
                    $sortie['reservee'] = $e['res_membre'] !== '';
                    $sortie['ma_reservation'] = $ma_res;
                    $sortie['res_prenom'] = $e['res_membre'] !== '' ? ($prenoms[$e['res_membre']] ?? '') : '';
                    $sortie['modifiee_apres'] = $e['res_membre'] !== '' && $e['modifiee'] !== '' && $e['modifiee'] > $e['res_date'];
                    if ($ma_res) {
                        $sortie['prix'] = $e['res_prix'] !== null ? (float)$e['res_prix'] : null;
                        $sortie['achete'] = (int)$e['res_achete'] === 1;
                    }
                }
                $envies[] = $sortie;
            }
            $st = $db->prepare('SELECT id, pour, titre, prix, achete FROM notes WHERE groupe = ? AND membre = ? ORDER BY pour COLLATE NOCASE, rowid');
            $st->execute([$code, $moi['id']]);
            $notes = array_map(function ($n) {
                return ['id' => $n['id'], 'pour' => $n['pour'], 'titre' => $n['titre'],
                        'prix' => $n['prix'] !== null ? (float)$n['prix'] : null, 'achete' => (int)$n['achete'] === 1];
            }, $st->fetchAll());
            // Tirages : seulement la personne à qui JE offre.
            $st = $db->prepare('SELECT t.id, t.nom, t.mot, t.cree, p.receveur, p.prenom FROM tirages t JOIN paires p ON p.tirage = t.id
                                WHERE t.groupe = ? AND p.donneur = ? ORDER BY t.cree DESC, t.rowid DESC');
            $st->execute([$code, $moi['id']]);
            $tirages = array_map(function ($t) use ($code) {
                return ['id' => $t['id'], 'nom' => $t['nom'], 'mot' => $t['mot'], 'cree' => $t['cree'],
                        'p' => $t['receveur'] !== '' ? alias($code, $t['receveur']) : '', 'prenom' => $t['prenom']];
            }, $st->fetchAll());
            $rep = ['nom' => $grp['nom'], 'moi' => alias($code, $moi['id']), 'personnes' => $personnes, 'envies' => $envies, 'notes' => $notes, 'tirages' => $tirages];
            if (est_admin($grp, $cle_admin)) {
                $rep['admin'] = true;
                // La gestion voit les tirages et leur nombre de participants, jamais les paires.
                $st = $db->prepare('SELECT t.id, t.nom, t.cree, COUNT(p.donneur) AS nb FROM tirages t LEFT JOIN paires p ON p.tirage = t.id
                                    WHERE t.groupe = ? GROUP BY t.id ORDER BY t.cree DESC, t.rowid DESC');
                $st->execute([$code]);
                $rep['tirages_gestion'] = array_map(fn($t) => ['id' => $t['id'], 'nom' => $t['nom'], 'cree' => $t['cree'], 'nb' => (int)$t['nb']], $st->fetchAll());
            }
            repondre($rep);

        /* ----- Ajouter une envie à sa liste ----- */
        case 'ajouter':
            $titre = texte($entree['titre'] ?? '', MAX_TITRE);
            if ($titre === '') erreur('Il manque le titre');
            $precision = texte($entree['precision'] ?? '', MAX_PRECISION);
            $lien = lien_valide($entree['lien'] ?? '');
            $st = $db->prepare('SELECT COUNT(*) FROM envies WHERE groupe = ? AND membre = ? AND retiree = \'\'');
            $st->execute([$code, $moi['id']]);
            if ((int)$st->fetchColumn() >= MAX_ENVIES_PAR_PERSONNE) erreur('Ta liste est déjà bien longue');
            $id = aleatoire(8);
            $db->prepare('INSERT INTO envies (id, groupe, membre, prenom, titre, precision, lien, cree) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$id, $code, $moi['id'], $moi['prenom'], $titre, $precision, $lien, aujourdhui()]);
            repondre(['id' => $id]);

        /* ----- Modifier une de ses envies ----- */
        case 'modifier':
            $e = envie($db, $code, $entree['id'] ?? '');
            if ($e['membre'] !== $moi['id'] || $e['retiree'] !== '') erreur('Ce n\'est pas ton envie', 403);
            $titre = texte($entree['titre'] ?? '', MAX_TITRE);
            if ($titre === '') erreur('Il manque le titre');
            $precision = texte($entree['precision'] ?? '', MAX_PRECISION);
            $lien = lien_valide($entree['lien'] ?? '');
            if ($titre !== $e['titre'] || $precision !== $e['precision'] || $lien !== $e['lien']) {
                $db->prepare('UPDATE envies SET titre = ?, precision = ?, lien = ?, modifiee = ? WHERE id = ?')
                   ->execute([$titre, $precision, $lien, maintenant(), $e['id']]);
            }
            repondre(['ok' => true]);

        /* ----- Retirer une de ses envies (reçue, ou plus envie) ----- */
        case 'retirer':
            $e = envie($db, $code, $entree['id'] ?? '');
            if ($e['membre'] !== $moi['id'] || $e['retiree'] !== '') erreur('Ce n\'est pas ton envie', 403);
            retirer_envie($db, $e, $moi['prenom']);
            repondre(['ok' => true]);   // même réponse, réservée ou non : rien ne trahit la surprise

        /* ----- Réserver l'envie de quelqu'un d'autre ----- */
        case 'reserver':
            $e = envie($db, $code, $entree['id'] ?? '');
            if ($e['membre'] === $moi['id']) erreur('C\'est ta propre envie');
            if ($e['retiree'] !== '') erreur('Cette envie a été retirée', 404);
            // La première réservation l'emporte.
            $st = $db->prepare('UPDATE envies SET res_membre = ?, res_date = ? WHERE id = ? AND res_membre = \'\' AND retiree = \'\'');
            $st->execute([$moi['id'], maintenant(), $e['id']]);
            if ($st->rowCount() === 0) erreur('Quelqu\'un l\'a réservée juste avant toi', 409);
            repondre(['ok' => true]);

        /* ----- Annuler sa réservation ----- */
        case 'liberer':
            $e = envie($db, $code, $entree['id'] ?? '');
            if ($e['res_membre'] !== $moi['id']) erreur('Ce n\'est pas ta réservation', 403);
            if ($e['retiree'] !== '') {
                $db->prepare('DELETE FROM envies WHERE id = ?')->execute([$e['id']]);
            } else {
                $db->prepare('UPDATE envies SET res_membre = \'\', res_date = \'\', res_prix = NULL, res_achete = 0 WHERE id = ?')->execute([$e['id']]);
            }
            repondre(['ok' => true]);

        /* ----- « Compris » : la personne qui avait réservé a vu que l'envie était retirée ----- */
        case 'vu':
            $e = envie($db, $code, $entree['id'] ?? '');
            if ($e['res_membre'] !== $moi['id'] || $e['retiree'] === '') erreur('Rien à noter', 403);
            $db->prepare('DELETE FROM envies WHERE id = ?')->execute([$e['id']]);
            repondre(['ok' => true]);

        /* ----- Prix et « acheté » d'une de ses réservations (privé) ----- */
        case 'suivi':
            $e = envie($db, $code, $entree['id'] ?? '');
            if ($e['res_membre'] !== $moi['id']) erreur('Ce n\'est pas ta réservation', 403);
            $champs = []; $valeurs = [];
            if (array_key_exists('prix', $entree))   { $champs[] = 'res_prix = ?';   $valeurs[] = prix_valide($entree['prix']); }
            if (array_key_exists('achete', $entree)) { $champs[] = 'res_achete = ?'; $valeurs[] = !empty($entree['achete']) ? 1 : 0; }
            if ($champs) {
                $valeurs[] = $e['id'];
                $db->prepare('UPDATE envies SET ' . implode(', ', $champs) . ' WHERE id = ?')->execute($valeurs);
            }
            repondre(['ok' => true]);

        /* ----- Notes privées : idées de cadeaux que soi seul voit ----- */
        case 'note_ajouter':
        case 'importer':
            $lot = $action === 'importer' ? ($entree['notes'] ?? null) : [$entree];
            if (!is_array($lot) || !$lot) erreur('Rien à ajouter');
            $st = $db->prepare('SELECT COUNT(*) FROM notes WHERE groupe = ? AND membre = ?');
            $st->execute([$code, $moi['id']]);
            if ((int)$st->fetchColumn() + count($lot) > MAX_NOTES_PAR_PERSONNE) erreur('Ça fait beaucoup de notes');
            $propres = [];
            foreach ($lot as $n) {
                if (!is_array($n)) erreur('Note illisible');
                $pour = texte($n['pour'] ?? '', MAX_POUR);
                $titre = texte($n['titre'] ?? '', MAX_TITRE);
                if ($pour === '') erreur('Il manque pour qui');
                if ($titre === '') erreur('Il manque l\'idée');
                $propres[] = [$pour, $titre, prix_valide($n['prix'] ?? null), !empty($n['achete']) ? 1 : 0];
            }
            $ins = $db->prepare('INSERT INTO notes (id, groupe, membre, pour, titre, prix, achete, cree) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $db->beginTransaction();
            foreach ($propres as [$pour, $titre, $prix, $achete]) {
                $ins->execute([aleatoire(8), $code, $moi['id'], $pour, $titre, $prix, $achete, aujourdhui()]);
            }
            $db->commit();
            repondre(['ok' => true, 'nb' => count($propres)]);

        case 'note_modifier':
            $st = $db->prepare('SELECT * FROM notes WHERE id = ? AND groupe = ? AND membre = ?');
            $st->execute([(string)($entree['id'] ?? ''), $code, $moi['id']]);
            $n = $st->fetch();
            if (!$n) erreur('Cette note n\'existe plus', 404);
            $pour = array_key_exists('pour', $entree) ? texte($entree['pour'], MAX_POUR) : $n['pour'];
            $titre = array_key_exists('titre', $entree) ? texte($entree['titre'], MAX_TITRE) : $n['titre'];
            if ($pour === '' || $titre === '') erreur('Il manque l\'idée ou pour qui');
            $prix = array_key_exists('prix', $entree) ? prix_valide($entree['prix']) : $n['prix'];
            $achete = array_key_exists('achete', $entree) ? (!empty($entree['achete']) ? 1 : 0) : (int)$n['achete'];
            $db->prepare('UPDATE notes SET pour = ?, titre = ?, prix = ?, achete = ? WHERE id = ?')->execute([$pour, $titre, $prix, $achete, $n['id']]);
            repondre(['ok' => true]);

        case 'note_retirer':
            $st = $db->prepare('DELETE FROM notes WHERE id = ? AND groupe = ? AND membre = ?');
            $st->execute([(string)($entree['id'] ?? ''), $code, $moi['id']]);
            repondre(['ok' => true]);

        /* ----- Choisir (ou changer) son animal ----- */
        case 'mon_animal':
            $a = animal_valide($db, $code, $entree['animal'] ?? '', $moi['id']);
            $db->prepare('UPDATE membres SET animal = ? WHERE id = ?')->execute([$a, $moi['id']]);
            repondre(['ok' => true]);

        /* ----- Changer son prénom ----- */
        case 'mon_prenom':
            $p = prenom_valide($entree['prenom'] ?? '');
            $db->prepare('UPDATE membres SET prenom = ? WHERE id = ?')->execute([$p, $moi['id']]);
            $db->prepare('UPDATE envies SET prenom = ? WHERE membre = ? AND retiree = \'\'')->execute([$p, $moi['id']]);
            repondre(['ok' => true]);

        /* ----- Quitter le groupe ----- */
        case 'quitter':
            effacer_membre($db, $code, $moi['id']);
            repondre(['ok' => true]);

        /* ----- Gestion : qui est dans le groupe ----- */
        case 'membres':
            if (!est_admin($grp, $cle_admin)) erreur('Réservé à la personne qui gère le groupe', 403);
            $st = $db->prepare('SELECT m.id, m.prenom, m.animal, m.arrivee, p.prenom AS parrain_prenom
                                FROM membres m LEFT JOIN membres p ON p.id = m.parrain AND p.groupe = m.groupe
                                WHERE m.groupe = ? ORDER BY m.arrivee, m.rowid');
            $st->execute([$code]);
            $liste = array_map(function ($m) use ($code, $moi) {
                return ['p' => alias($code, $m['id']), 'prenom' => $m['prenom'], 'animal' => $m['animal'], 'arrivee' => $m['arrivee'],
                        'parrain' => $m['parrain_prenom'] ?? '', 'moi' => $m['id'] === $moi['id']];
            }, $st->fetchAll());
            repondre(['membres' => $liste]);

        /* ----- Gestion : retirer quelqu'un du groupe ----- */
        case 'retirer_membre':
            if (!est_admin($grp, $cle_admin)) erreur('Réservé à la personne qui gère le groupe', 403);
            $cible = (string)($entree['p'] ?? '');
            $st = $db->prepare('SELECT id FROM membres WHERE groupe = ?');
            $st->execute([$code]);
            $id = null;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                if (alias($code, $mid) === $cible) { $id = $mid; break; }
            }
            if ($id === null) erreur('Personne introuvable', 404);
            if ($id === $moi['id']) erreur('Pour partir toi-même, utilise « Quitter le groupe »');
            effacer_membre($db, $code, $id);
            repondre(['ok' => true]);

        /* ----- Gestion : lancer un tirage au sort ----- */
        case 'tirer':
            if (!est_admin($grp, $cle_admin)) erreur('Réservé à la personne qui gère le groupe', 403);
            $nom = texte($entree['nom'] ?? '', MAX_NOM_TIRAGE);
            if ($nom === '') erreur('Donne un nom au tirage (par exemple « Noël 2026 »)');
            $mot = texte($entree['mot'] ?? '', MAX_MOT_TIRAGE);
            $st = $db->prepare('SELECT COUNT(*) FROM tirages WHERE groupe = ?');
            $st->execute([$code]);
            if ((int)$st->fetchColumn() >= MAX_TIRAGES_PAR_GROUPE) erreur('Ce groupe a déjà beaucoup de tirages : annule les anciens');
            // Les membres, désignés par leur alias.
            $st = $db->prepare('SELECT id, prenom FROM membres WHERE groupe = ?');
            $st->execute([$code]);
            $par_alias = []; $prenom_de = [];
            foreach ($st->fetchAll() as $m) { $par_alias[alias($code, $m['id'])] = $m['id']; $prenom_de[$m['id']] = $m['prenom']; }
            $noms = [];
            foreach ((array)($entree['participants'] ?? []) as $a) {
                if (!is_string($a) || !isset($par_alias[$a])) erreur('Une des personnes n\'est plus dans le groupe');
                $noms[$par_alias[$a]] = true;
            }
            $noms = array_keys($noms);
            if (count($noms) < 3) erreur('Il faut au moins trois personnes pour garder le secret');
            $exclusions = is_array($entree['exclusions'] ?? null) ? $entree['exclusions'] : [];
            $autorises = [];
            foreach ($noms as $g) {
                $ex = [];
                foreach ((array)($exclusions[array_search($g, $par_alias, true)] ?? []) as $a) if (isset($par_alias[$a])) $ex[$par_alias[$a]] = true;
                $autorises[$g] = array_values(array_filter($noms, fn($r) => $r !== $g && !isset($ex[$r])));
            }
            $bloques = array_values(array_map(fn($g) => $prenom_de[$g], array_filter($noms, fn($g) => !$autorises[$g])));
            if ($bloques) erreur(joli_liste($bloques) . (count($bloques) > 1 ? ' ne peuvent' : ' ne peut') . ' offrir à personne : retire une exclusion');
            $r = tirer($noms, $autorises);
            if ($r === null) erreur('Avec ces exclusions, aucun tirage n\'est possible : retire une exclusion et réessaie');
            $id = aleatoire(8);
            $db->beginTransaction();
            $db->prepare('INSERT INTO tirages (id, groupe, nom, mot, cree) VALUES (?, ?, ?, ?, ?)')->execute([$id, $code, $nom, $mot, maintenant()]);
            $ins = $db->prepare('INSERT INTO paires (tirage, donneur, receveur, prenom) VALUES (?, ?, ?, ?)');
            foreach ($r as $g => $x) $ins->execute([$id, $g, $x, $prenom_de[$x]]);
            $db->commit();
            repondre(['ok' => true, 'id' => $id, 'nb' => count($r)]);   // la réponse ne dit rien des paires

        /* ----- Gestion : annuler un tirage ----- */
        case 'annuler_tirage':
            if (!est_admin($grp, $cle_admin)) erreur('Réservé à la personne qui gère le groupe', 403);
            $db->prepare('DELETE FROM tirages WHERE id = ? AND groupe = ?')->execute([(string)($entree['id'] ?? ''), $code]);
            repondre(['ok' => true]);

        /* ----- Gestion : changer le lien du groupe ----- */
        case 'changer_lien':
            if (!est_admin($grp, $cle_admin)) erreur('Réservé à la personne qui gère le groupe', 403);
            $nouveau = aleatoire(9);
            $db->prepare('UPDATE groupes SET lien = ? WHERE code = ?')->execute([$nouveau, $code]);
            repondre(['g' => $nouveau]);

        /* ----- Gestion : renommer le groupe ----- */
        case 'renommer':
            if (!est_admin($grp, $cle_admin)) erreur('Réservé à la personne qui gère le groupe', 403);
            $db->prepare('UPDATE groupes SET nom = ? WHERE code = ?')->execute([texte($entree['nom'] ?? '', MAX_NOM_GROUPE), $code]);
            repondre(['ok' => true]);

        default:
            erreur('Action inconnue');
    }
} catch (Throwable $e) {
    erreur('Erreur du serveur', 500);
}
