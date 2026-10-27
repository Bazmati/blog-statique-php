# Blog de Basile Malin — générateur statique en PHP pur

Mon blog personnel, construit de zéro avec un **générateur de sites statiques en PHP pur** : aucun framework, aucune dépendance, aucun tracker. Un seul fichier `build.php` transforme des articles Markdown en site complet, optimisé et prêt à servir par n'importe quel serveur web.

> Zéro dépendance composer. Zéro JavaScript côté client (3 lignes pour le menu mobile). Zéro cookie. Rien à mettre à jour.

## ✨ Fonctionnalités

- **Articles en Markdown** avec frontmatter : titre, date, description, catégorie, tags, image de couverture
- **Générateur statique** : pages HTML, URLs propres (`/article/mon-article/`), chemins relatifs (fonctionne partout)
- **Images optimisées au build** : redimension à 1500 px, conversion WebP, réécriture automatique des URLs
- **Taxonomie** : catégories et tags, chacun avec sa page indexable (`/categorie/…/`, `/tag/…/`)
- **SEO intégré** : meta descriptions, canonicals, Open Graph, données structurées JSON-LD (Person, BlogPosting, BreadcrumbList), sitemap.xml, robots.txt, flux RSS
- **UX soignée** : layout 2 colonnes avec sidebar, fil d'Ariane, filtres avec bouton « retirer », pagination (10 articles/page), nav sticky, burger mobile sans framework, focus clavier visible, contrastes WCAG AA
- **Conformité LCEN** : page mentions légales générée, adaptée à un site sans cookie ni collecte de données

## 🚀 Démarrage rapide

```bash
# Prérequis : PHP 8+ en CLI (extension GD recommandée pour la conversion WebP)
php build.php
```

Le site est généré dans `dist/` — ouvrez `dist/index.html` dans un navigateur, ou servez le dossier avec Live Server / `php -S localhost:8000 -t dist`.

## 📁 Structure

```
Blog_basile_malin/
├── build.php     # le générateur complet (un seul fichier)
├── content/      # articles : un fichier .md par article
├── assets/       # images brutes (converties en WebP au build)
└── dist/         # site généré (à rsync sur le serveur)
```

Exemple d'article (`content/2026-10-27-mon-article.md`) :

```markdown
---
title: Mon article
date: 2026-10-27
description: Une courte accroche pour les résultats de recherche.
category: Développement
tags: php, blog
image: assets/mon-image.jpg
image_alt: Description de l'image pour l'accessibilité
---

## Un titre

Le corps de l'article, en Markdown.
```

## 🔁 Workflow

```
écrire un .md → php build.php → vérifier en local → rsync dist/ sur le serveur
```

Déploiement :

```bash
rsync -rav --delete dist/ user@serveur:/var/www/basile/
```

## 🛠️ Motivation

Après une reconversion depuis l'industrie (soudure, maintenance) vers le développement PHP/Symfony, je voulais un blog simple à maintenir : pas de mises à jour de framework, pas de surface d'attaque, pas de base de données — juste du contenu et du HTML statique. Ce générateur fait ~450 lignes de PHP lisibles, et il ne dépendra jamais de rien.

## 📄 Licence

MIT — faites-en ce que vous voulez, le code est là pour ça.

---
*Basile Malin — [GitHub](https://github.com/Bazmati) · [CV en ligne](https://bazmati.github.io/basile-malin-cv-2026/) · [Blog](https://basilemalin.fr)*