# CBZ-Viewer — Instructions de pilotage (Copil)

## Présentation du projet

Application web de lecture en ligne de mangas au format CBZ et CBR.  
Déployable sur hébergement mutualisé PHP (PlanetHoster "The World" / cPanel).  
Aucun build step, aucune base de données — déploiement par simple copie FTP/SFTP.

---

## Stack technique

| Composant       | Choix                                          | Raison                                                          |
|-----------------|------------------------------------------------|-----------------------------------------------------------------|
| Serveur         | **PHP 8.1+**                                   | ZipArchive natif, Imagick/GD dispo, cPanel compatible           |
| Base de données | **Aucune**                                     | Scan dynamique + cache JSON sur disque                          |
| Frontend        | **HTML5 / CSS3 / JS ES2022 vanilla**           | Aucun build step, déploiement FTP simple                        |
| CBZ             | `ZipArchive` (PHP natif)                       | Streaming page par page sans extraction                         |
| CBR             | PECL `rar` > `exec(unrar)` > `exec(7z)` > 501 | Cascade de fallbacks, erreur gracieuse                          |
| Miniatures      | Imagick (préféré) ou GD (fallback)             | Imagick confirmé disponible sur PlanetHoster N0C                |
| Serveur web     | Apache + mod_rewrite                           | `.htaccess` actif sur cPanel PlanetHoster                       |

---

## Architecture des fichiers

```
CBZ-viewer/
├── index.php               ← Bibliothèque : grille des séries
├── series.php              ← Tomes d'une série (?s=slug)
├── reader.php              ← Lecteur (?file=serie/tome.cbz)
├── api/
│   ├── lib.php             ← Fonctions partagées (CBZ/CBR, cache, miniatures)
│   ├── meta.php            ← JSON : pages totales + liste ordonnée
│   ├── page.php            ← Stream une image : ?file=…&page=N (0-indexé)
│   └── thumb.php           ← JPEG miniature de couverture (mise en cache)
├── data/                   ← CBZ/CBR (gitignorés — copie manuelle)
│   └── {serie}/
│       └── {tome}.cbz / .cbr
├── cache/                  ← Généré automatiquement par PHP à l'exécution
│   ├── .htaccess           ← Bloque l'accès HTTP direct au cache
│   ├── thumbnails/         ← Miniatures JPEG persistées
│   └── metadata/           ← Métadonnées JSON (invalidation par filemtime)
├── assets/
│   ├── css/style.css
│   └── js/reader.js
├── .htaccess               ← Rewrite + blocage /data /cache + sécurité
└── .github/
    └── copilot-instructions.md
```

---

## Conventions

- **Répertoires de séries** : nommage libre (ex : `death-note`, `one-piece`)
- **Fichiers CBZ/CBR** : nommage libre — les pages sont triées par ordre naturel (`strnatcasecmp`) à l'intérieur de l'archive
- **Cache** : auto-invalidé si le fichier source change (clé = `md5(path + filemtime)`)
- **Aucune base de données** : toutes les listes sont scannées depuis le système de fichiers

---

## Déploiement sur cPanel / PlanetHoster

1. **Sélectionner PHP 8.1+** dans cPanel → MultiPHP Manager pour le domaine/sous-domaine
2. **Transférer** le projet (sans `data/` ni `cache/`) via FTP/SFTP dans `public_html/` ou un sous-dossier
3. **Créer** les dossiers `data/{serie}/` et y copier les fichiers CBZ/CBR
4. **Vérifier les droits** : `cache/` doit être accessible en écriture par PHP (généralement `755` suffit avec suPHP/FastCGI)
5. **Premier accès** : le cache se génère automatiquement — pas d'action manuelle requise

### Commandes utiles en SSH (port 5022)
```bash
# Vérifier la version PHP CLI
php -v

# Pré-générer tout le cache (optionnel, après ajout de tomes)
php -r "
require 'api/lib.php';
foreach (getAllSeries() as \$s) {
    foreach (getSeriesFiles(\$s['path']) as \$f) {
        getFileMeta(\$f);
        \$thumb = getThumbnailCachePath(\$f);
        if (!file_exists(\$thumb)) generateThumbnail(\$f);
    }
}
echo 'Cache préchauffé.' . PHP_EOL;
"
```

---

## Support CBR — activation PECL `rar` (optionnel mais recommandé)

1. cPanel → **MultiPHP Extensions Manager** → sélectionner la version PHP active → cocher `rar`
2. Si `rar` n'est pas dans la liste : ouvrir un ticket PlanetHoster pour demander son activation
3. **Sans PECL** : tentative automatique via `exec('unrar')` puis `exec('7z')` si non désactivés
4. **Si aucune méthode** : message d'erreur explicite affiché dans le lecteur, suggestion de convertir en CBZ

### Convertir CBR → CBZ localement (solution de facilité)
```bash
# Sur Linux/macOS avec unrar et zip installés
for f in *.cbr; do
  mkdir tmp_cbr
  unrar x "$f" tmp_cbr/
  zip -j "${f%.cbr}.cbz" tmp_cbr/*
  rm -rf tmp_cbr
done
```
Sur Windows : utiliser CDisplayEx, Calibre, ou renommer `.cbr` → `.cbz` (fonctionne si l'archive est en réalité un ZIP).

---

## Fonctionnalités du lecteur

| Feature                | Description                                                                              |
|------------------------|------------------------------------------------------------------------------------------|
| Modes de lecture       | **LTR** (gauche→droite), **RTL** (droite→gauche, défaut manga), **Webtoon** (scroll vertical) |
| Navigation             | Swipe tactile, flèches clavier, zones cliquables gauche/droite, slider, input page numérique |
| Zoom                   | Pinch-to-zoom, boutons +/−, double-tap reset, valeur persistée en localStorage           |
| Pan                    | Glissement quand zoom > 1 (toucher ou souris)                                            |
| Reprise de lecture     | Dernière page sauvegardée par tome (localStorage), reprise automatique à l'ouverture     |
| Préchargement          | Pages suivante et précédente préchargées en arrière-plan                                 |
| Double page            | 2 pages côte à côte (modes LTR/RTL), option de décalage couverture                      |
| Mode nuit / jour       | Thème sombre par défaut, basculable, persisté en localStorage                            |
| Plein écran            | Fullscreen API (bouton + touche `F`)                                                     |
| Navigation inter-tomes | Boutons Tome précédent / Tome suivant dans la barre d'outils                             |
| Sélection de page      | Slider + champ numérique cliquable                                                       |
| Raccourcis clavier     | `←/→` navigation, `F` plein écran, `S` paramètres, `Échap` fermer, `+/-` zoom           |

---

## Variables d'environnement / Configuration

Pas de fichier de config dédié. Les chemins sont relatifs au fichier `lib.php` :

```php
define('DATA_DIR',  realpath(__DIR__ . '/../data'));   // dossier des archives
define('CACHE_DIR', __DIR__ . '/../cache');            // dossier de cache
```

Pour changer l'emplacement des données, modifier ces deux constantes dans `api/lib.php`.

---

## Roadmap / Améliorations futures possibles

- [ ] Recherche textuelle dans les titres de séries/tomes
- [ ] Marque-pages (pages favorites) par tome
- [ ] Tri personnalisé des tomes (date, nom)
- [ ] Mode hors-ligne (Service Worker / PWA)
- [ ] Export du cache de progression (JSON download)
- [ ] Protection par mot de passe (`.htpasswd` Apache)
