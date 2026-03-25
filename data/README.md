# Data Directory

Ce répertoire contient les fichiers de mangas au format `.cbz` ou `.cbr`.

## Structure

```
data/
├── <nom-de-la-série>/
│   ├── <Tome 1>.cbz
│   ├── <Tome 2>.cbz
│   └── ...
├── <autre-série>/
│   └── ...
└── README.md
```

## Conventions

- Chaque série est placée dans un **sous-répertoire dédié**, nommé en minuscules avec des tirets (ex: `one-piece`, `death-note`).
- Les fichiers de manga doivent être au format **CBZ** (`.cbz`) ou **CBR** (`.cbr`).
- Un fichier `.gitkeep` peut être placé dans un sous-répertoire vide pour le conserver dans le dépôt Git.

## Exemple

```
data/
├── one-piece/
│   ├── One_Piece_T001.cbz
│   └── One_Piece_T002.cbz
├── death-note/
│   ├── Death Note T01.cbr
│   └── Death Note T02.cbr
└── README.md
```

> ⚠️ Les fichiers présents dans ce répertoire sont ignorés par Git (voir `.gitignore`).
> Seuls ce fichier `README.md` et les éventuels `.gitkeep` sont versionnés.
