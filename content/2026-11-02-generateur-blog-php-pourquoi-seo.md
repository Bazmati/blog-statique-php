---
title: Pourquoi j'ai écrit mon propre générateur de blog en PHP pur
date: 2026-11-02
description: Un fichier PHP, zéro dépendance, un blog complet avec sitemap, flux RSS et JSON-LD. Retour sur un choix de reconversion qui fait du bien au SEO.
category: Développement web
tags: php, seo, blog-statique, auto-hebergement
---

Quand on se reconvertit du monde industriel vers le développement web, on apprend une chose très vite : les usines n'aiment pas les pièces uniques. Une machine avec des composants propriétaires introuvables, c'est une machine qu'on ne répare pas, qu'on remplace. En informatique, c'est pareil — sauf que le monde du web a tendance à oublier cette leçon.

Alors quand est venu le moment de mettre mon blog en ligne, j'ai regardé les solutions qui s'offraient à moi : WordPress, Hugo, Ghost, Jekyll… Toutes compétentes. Toutes avec leur lot de dépendances, de mises à jour de sécurité, d'extensions à maintenir, de thèmes qui cassent à chaque montée de version.

J'ai fait ce que douze ans de maintenance industrielle m'ont appris : **si la machine peut être simple, fabrique-la simple.**

## Un fichier, aucune dépendance

Mon générateur tient dans un seul `build.php`. Pas de `composer install`, pas de `node_modules`, pas de pipeline de build. Un fichier Markdown par article, une commande :

```
php build.php
```

Et le dossier `dist/` contient un site complet : page d'accueil, pages d'articles, pagination, pages de catégories et de tags, plan du site, flux RSS, robots.txt.

## Pourquoi c'est intéressant pour le SEO

On entend souvent que le SEO, c'est du contenu, des mots-clés, du netlinking. C'est vrai. Mais la base technique, celle qui décide si Google peut même *lire* votre site correctement, elle se joue plus bas — et c'est là qu'un site statique généré sur mesure devient redoutable.

### 1\. La vitesse, le nerf de la guerre

Les Core Web Vitals de Google (LCP, INP, CLS) sont des critères de classement. Un site statique, c'est des fichiers HTML servis directement par Nginx : aucune base de données à interroger, aucun PHP à exécuter à chaque visite, aucun plugin qui charge trois librairies JavaScript. Le poids d'une page se compte en kilo-octets, la réponse en millisecondes sur un serveur maison. Un WordPress moyen, c'est souvent 2 à 4 Mo et plusieurs centaines de millisecondes de plus. Sur un hébergement auto-hébergé derrière une box, cette marge n'est pas un luxe : elle est indispensable.

### 2\. Le HTML sémantique, écrit à la main

Parce que je génère moi-même chaque balise, mon HTML ne contient rien d'inutile. Une hiérarchie de titres propre (`h1` unique, `h2`/`h3` structurés), des attributs `alt` sur toutes les images, des `<time datetime="...">` pour les dates, des liens internes en chemins relatifs. Google lit ça comme un grand livre ouvert. Les constructeurs de thèmes, eux, enveloppent chaque site dans des dizaines de `div` et de classes CSS que les moteurs de recherche doivent traverser avant de trouver le contenu.

### 3\. Sitemap, flux RSS et robots.txt générés, pas installés

Le générateur produit `sitemap.xml` automatiquement à chaque build — avec les bonnes URLs, les dates de publication, la configuration prête pour Google Search Console. Le flux `feed.xml` permet aux agrégateurs et lecteurs de suivre le blog, ce qui crée des signaux d'activité réguliers. Le `robots.txt` interdit aux robots ce qui n'a pas à être indexé. Zéro extension à installer, zéro configuration à oublier de cocher dans un back-office : le SEO technique est dans le code, pas dans une case à cocher.

### 4\. Le JSON-LD : des données structurées gratuites

C'est mon coin préféré du générateur. Chaque article embarque un bloc `<script type="application/ld+json">` au format `BlogPosting` de schema.org : titre, description, date de publication, auteur, image de couverture, URL canonique. C'est ce qui permet à Google d'afficher les *rich snippets* — carte enrichie avec image et date dans les résultats de recherche. Sur WordPress, ça demande un plugin (Yoast ou équivalent). Ici, c'est une douzaine de lignes de PHP qui s'adaptent à chaque article.

### 5\. La simplicité des URLs

Mes articles vivent à des adresses comme `/article/mon-sujet/` — courtes, lisibles, stables, sans identifiant numérique ni paramètre. Le générateur crée la page dans un dossier du même nom, Nginx sert le `index.html`, et l'URL ne changera jamais tant que le slug ne change pas. Google adore les URLs stables ; les lecteurs aussi.

### 6\. Les images, converties avant même d'arriver sur le serveur

Le poids d'une page web, c'est en général 60 à 80 % d'images. Mon build redimensionne automatiquement les images source à 1500 px de large et les convertit en WebP — un format 25 à 35 % plus léger que le JPEG à qualité équivalente. Le LCP s'en trouve transformé, surtout sur mobile, où se trouvent la majorité des visites d'un blog.

## La leçon industrielle

Un générateur de blog statique, ça existe partout — Hugo fait ça très bien, mieux même sur certains points. Mais le vrai gain n'est pas technique : il est dans la compréhension. Chaque balise de mes pages, je sais pourquoi elle est là. Chaque requête HTTP, je sais ce qu'elle coûte. Le jour où mon site rame, ou qu'une page n'apparaît pas dans les résultats de recherche, je peux ouvrir un seul fichier et tout comprendre — comme un technicien qui connaît son schéma électrique par cœur.

C'est la compétence que je veux apporter en tant que développeur : pas seulement savoir *utiliser* des outils, mais savoir les construire quand la situation le demande. Le SEO n'est pas une case à cocher dans un back-office — c'est une conséquence d'une architecture propre. Et une architecture propre, ça s'apprend.

Le code est disponible sur GitHub : `https://github.com/Bazmati/blog-statique-php`
