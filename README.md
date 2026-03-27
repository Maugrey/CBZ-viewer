# 📚 CBZ-Viewer

Application web de lecture en ligne de mangas aux formats **CBZ** et **CBR**.  
Conçue pour être déployée sur un hébergement PHP — **aucun build step, aucune base de données**, déploiement par simple copie FTP/SFTP.

---

## Fonctionnalités

### Bibliothèque
- Grille des séries avec miniatures de couverture générées automatiquement
- Vue détaillée des tomes d'une série

### Lecteur
| Feature | Description |
|---|---|
| **Modes de lecture** | RTL (manga, défaut), LTR (occidental), Webtoon (scroll vertical) |
| **Navigation** | Swipe tactile, flèches clavier, zones cliquables, slider de pages, champ numérique |
| **Zoom** | Pinch-to-zoom, boutons +/−, double-tap reset, persisté par volume |
| **Pan** | Glissement au doigt ou à la souris quand zoom > 1 |
| **Double page** | 2 pages côte à côte (LTR/RTL), option de décalage de couverture |
| **Reprise de lecture** | Dernière page sauvegardée par tome en `localStorage` |
| **Préchargement** | Pages suivante et précédente préchargées en arrière-plan |
| **Navigation inter-tomes** | Boutons Tome précédent / Tome suivant |
| **Mode nuit / jour** | Thème sombre par défaut, basculable, persisté |
| **Plein écran** | Fullscreen API (bouton + touche `F`) |
| **Raccourcis clavier** | `←/→` navigation · `F` plein écran · `S` paramètres · `+/-` zoom · `Échap` fermer |

---

## Stack technique

| Composant | Choix |
|---|---|
| Serveur | **PHP 8.1+** — ZipArchive natif, Imagick/GD, compatible cPanel |
| Base de données | **Aucune** — scan dynamique + cache JSON sur disque |
| Frontend | **HTML5 / CSS3 / JS ES2022 vanilla** — aucune dépendance externe |
| CBZ | `ZipArchive` (PHP natif) — streaming page par page sans extraction |
| CBR | PECL `rar` › `exec(unrar)` › `exec(7z)` › erreur gracieuse |
| Miniatures | Imagick (préféré) ou GD (fallback) |
| Serveur web | Apache + `.htaccess` (mod_rewrite) |

---

## Structure du projet

```
CBZ-viewer/
├── index.php               ← Bibliothèque : grille des séries
├── series.php              ← Tomes d'une série (?s=slug)
├── reader.php              ← Lecteur plein écran (?file=serie/tome.cbz)
├── api/
│   ├── lib.php             ← Fonctions partagées (CBZ/CBR, cache, miniatures)
│   ├── meta.php            ← API JSON : nombre de pages + liste ordonnée
│   ├── page.php            ← Stream une image : ?file=…&page=N (0-indexé)
│   └── thumb.php           ← Miniature JPEG de couverture (mise en cache)
├── assets/
│   ├── css/style.css
│   └── js/reader.js
├── data/                   ← Vos archives CBZ/CBR (non versionnées)
│   └── {serie}/
│       └── {tome}.cbz / .cbr
├── cache/                  ← Généré automatiquement par PHP
│   ├── thumbnails/
│   └── metadata/
└── .htaccess               ← Rewrite + protection /data et /cache
```

---

## Installation et déploiement

### Prérequis
- PHP **8.1+** avec les extensions : `zip` (standard), `gd` ou `imagick`
- Serveur Apache avec `mod_rewrite` et support `.htaccess`
- Extension PECL `rar` pour la lecture de fichiers `.cbr` (optionnel, voir ci-dessous)

### Déploiement

1. **Sélectionner PHP 8.1+** sur l'hébergeur ou un hébergeur compatible
2. **Transférer** le projet (sans `data/` ni `cache/`) via FTP/SFTP dans `public_html/`
3. **Créer** les dossiers `data/{serie}/` et y copier les fichiers CBZ/CBR
4. **Vérifier les droits** : `cache/` doit être accessible en écriture par PHP (`755` suffit avec suPHP/FastCGI)
5. **Premier accès** : le cache se génère automatiquement lors de la navigation

### Ajout de contenu

Placez vos archives dans `data/` en respectant la structure :

```
data/
├── one-piece/
│   ├── One_Piece_T001.cbz
│   └── One_Piece_T002.cbz
└── death-note/
    ├── Death Note T01.cbr
    └── Death Note T02.cbr
```

- Chaque **sous-répertoire** = une série
- Nommage libre pour les dossiers et les fichiers
- Les pages à l'intérieur des archives sont triées par **ordre naturel**

---

## Support CBR

La lecture de fichiers `.cbr` utilise une cascade de méthodes :

1. Extension PHP **PECL `rar`** *(recommandé)*
2. Binaire **`unrar`** via `exec()`
3. Binaire **`7z`** via `exec()`
4. Message d'erreur explicite avec suggestion de conversion si aucune méthode n'est disponible

### Activer PECL `rar` sur l'hébergeur

### Convertir CBR → CBZ localement
```bash
# Linux/macOS
for f in *.cbr; do
  mkdir tmp_cbr
  unrar x "$f" tmp_cbr/
  zip -j "${f%.cbr}.cbz" tmp_cbr/*
  rm -rf tmp_cbr
done
```
Sur Windows : utiliser **CDisplayEx** ou **Calibre**, ou renommer `.cbr` → `.cbz` si l'archive est un ZIP déguisé.

---

## Cache

Le cache est géré automatiquement dans le dossier `cache/` :

- **Miniatures** : `cache/thumbnails/` — images JPEG générées à la première visite
- **Métadonnées** : `cache/metadata/` — JSON indexant les pages de chaque archive
- **Invalidation** : automatique si le fichier source change (clé `md5(chemin + filemtime)`)
- **Préchauffage manuel** (optionnel, en SSH) :

```bash
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

## Licence

Ce projet est distribué sous licence **[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)** (Creative Commons Attribution — Non Commercial — ShareAlike 4.0 International).

**Vous pouvez :**
- Utiliser et modifier librement le code

**Sous les conditions suivantes :**
- **Attribution (BY)** : citer l'auteur original ([Maugrey](https://github.com/Maugrey)) et inclure un lien vers le dépôt source ([github.com/Maugrey/CBZ-viewer](https://github.com/Maugrey/CBZ-viewer)) en cas de redistribution ou de modification publiée
- **Non commercial (NC)** : toute utilisation — y compris après modification — doit rester personnelle et non commerciale
- **ShareAlike (SA)** : toute redistribution d'une version modifiée doit se faire sous cette même licence CC BY-NC-SA

Aucune obligation de publier vos modifications — la clause SA ne s'applique qu'en cas de redistribution.
