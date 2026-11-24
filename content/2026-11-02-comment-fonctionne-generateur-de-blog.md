---
title: Comment fonctionne mon générateur de blog — et comment l'héberger n'importe où
date: 2026-11-02
description: Le guide complet de mon générateur de blog statique en PHP pur : architecture du code, frontmatter, conversion WebP, et déploiement sur VPS, hébergement mutualisé, serveur maison ou GitHub Pages.
category: Développement web
tags: php, blog-statique, tutoriel, hebergement, open-source
---

Mon générateur de blog est open source sur GitHub. Ce guide en explique le fonctionnement de bout en bout — avec des extraits de code — puis montre comment déployer le site obtenu sur quatre plateformes différentes. Si vous savez taper `php build.php`, vous savez héberger ce blog.

## Le principe : compiler, pas exécuter

La plupart des blogs (WordPress, Ghost) exécutent du code **à chaque visite** : une base de données, des requêtes, du rendu à la volée. Mon générateur fait l'inverse : tout le travail est fait **une fois, au moment du build**. Le serveur de production ne fait qu'envoyer des fichiers — HTML, CSS, WebP. C'est toute la différence entre une cuisine et un plat préparé.

Le flux complet tient en trois commandes :

```
php build.php                      # 1. compile content/ -> dist/
rsync -rav --delete dist/ user@serveur:/var/www/   # 2. envoie
# 3. nginx (ou Apache, ou rien du tout) sert les fichiers
```

## L'architecture du code

### Le frontmatter : un article, c'est un fichier Markdown

Chaque article est un simple `.md` dans `content/`, avec ses métadonnées en tête :

```
---
title: De l'atelier au code
date: 2026-10-27
description: Pourquoi j'ai quitté la maintenance industrielle pour le développement.
category: Reconversion
tags: industrie, php, carriere
image: dist/assets/article_guide.webp
---

Le corps de l'article en Markdown, images incluses.
```

Le parseur sépare ce bloc du corps avec une expression rationnelle minimale :

```php
$parts = preg_split('/^\s*---\s*$/m', $raw, 3);
// $parts[0] : avant le frontmatter (vide, normalement)
// $parts[1] : les métadonnées
// $parts[2] : le corps Markdown

foreach (explode("\n", trim($parts[1])) as $line) {
    [$key, $value] = explode(':', $line, 2);
    $meta[trim(strtolower($key))] = trim($value);
}
```

Trois lignes utiles, zéro dépendance. Le même principe s'applique au slug : le nom de fichier `2026-10-27-mon-article.md` devient l'URL `/article/mon-article/` — la date du préfixe migre dans les métadonnées :

```php
$slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', basename($file, '.md'));
```

### Un mini convertisseur Markdown, volontairement limité

Plutôt que d'importer une usine à gaz, le convertisseur embarqué couvre ce qu'un article de blog utilise vraiment : titres, gras, italique, liens, listes, citations, blocs de code, tableaux. La fonction `markdown_to_html()` fait environ cent lignes et échappe tout avec `htmlspecialchars()` **avant** d'interpréter le balisage — une balise HTML malveillante dans un `.md` ne peut donc pas se retrouver injectée dans la page.

### Le pipeline images : du WebP avant même d'arriver sur le serveur

C'est la partie que je préfère, parce que c'est là que le SEO technique se gagne. À chaque build, les images sources (`assets/*.jpg`) sont redimensionnées à 1500 px de large et converties en WebP avec l'extension GD :

```php
$img = imagecreatefromjpeg($source);
imagewebp($img, $destination, 82);   // qualité 82 : le bon compromis
```

Les articles référencent toujours le nom d'origine (`atelier.jpg`) ; le HTML généré pointe automatiquement vers `atelier.webp`. Résultat : des pages à ~30 % plus légères, sans y penser.

### Ce que le build produit

Une seule exécution génère tout le site : `index.html` (accueil paginé), une page par article dans `article/<slug>/index.html`, les pages de catégories et de tags, une `404.html`, et trois fichiers que Google adore :

*   **`sitemap.xml`** — prêt pour la Search Console
*   **`feed.xml`** — flux RSS
*   **`robots.txt`** — règles d'indexation

Chaque page embarque aussi ses données structurées JSON-LD (`BlogPosting` de schema.org), et des **chemins relatifs** — ce qui nous amène au point suivant.

## Pourquoi ce site s'héberge partout

Le générateur produit des pages avec des liens **relatifs** (`article/mon-article/`, `../assets/`), pas absolus (`/article/...`). C'est un choix de conception avec une conséquence directe : le dossier `dist/` fonctionne tel quel dans n'importe quel environnement — à la racine d'un domaine, dans un sous-dossier, ou en preview locale avec `php -S localhost:8000 -t dist`. Aucune configuration à adapter.

## Déployer selon votre plateforme

### 1\. Serveur maison ou VPS (nginx)

C'est le mode que j'utilise en production. Sur le serveur :

```nginx
server {
    listen 80;
    server_name basile-malin.fr www.basile-malin.fr;
    root /var/www/blog;
    index index.html;
    location / { try_files $uri $uri/ =404; }
    location ~ /\. { deny all; }   # .git, .env... : jamais servis
    server_tokens off;             # masque la version de nginx
}
```

Puis depuis votre machine :

```
rsync -rav --delete dist/ root@serveur:/var/www/blog/
```

Ajoutez Certbot (`certbot --nginx -d votre-domaine.fr`) et vous avez du HTTPS avec renouvellement automatique. Coût : une machine, un domaine, un peu de sysadmin — et un contrôle total.

### 2\. Hébergement mutualisé (OVH, Infomaniak, o2switch…)

Le plus simple de tous : ces offres ont déjà le serveur web configuré. Vous uploadez le contenu de `dist/` dans le dossier web (`www/`, `public_html/`) par FTP, SFTP ou — plus propre — rsync :

```
rsync -rav --delete dist/ utilisateur@ftp.hebergeur.fr:/home/utilisateur/www/
```

Le PHP n'est nécessaire **que sur votre machine**, pour le build : le serveur, lui, ne voit jamais une ligne de PHP. C'est l'avantage cachée du statique : n'importe quel hébergement à quelques euros par mois suffit.

### 3\. GitHub Pages

Le dépôt est déjà sur GitHub ; le site peut l'être aussi. Publiez le contenu de `dist/` sur une branche `gh-pages` (à chaque push si vous voulez, via une action GitHub), activez Pages dans les réglages du dépôt, et le site est servi en HTTPS sur `https://votre-compte.github.io/blog-statique-php/`. Comme les chemins sont relatifs, le sous-dossier ne casse rien. Limite : pas de vrai domaine sans configuration supplémentaire.

### 4\. Conteneurs (LXC / Docker)

Mon installation tourne dans un conteneur LXC sur Proxmox : un conteneur pour le blog (nginx + fichiers statiques), un second pour le reverse proxy qui centralise le HTTPS de plusieurs domaines. Ce découpage — un service = un conteneur — se transpose directement à Docker :

```
FROM nginx:alpine
COPY dist/ /usr/share/nginx/html/
```

Deux lignes de Dockerfile, un `docker build`, et l'image est prête pour n'importe quel orchestrateur.

## Évolution : d'un blog à une flotte

Un générateur qui construit un site peut en construire cinquante. La suite logique de ce projet est une **console d'administration multi-sites** : un tableau de bord qui déploie, met à jour et surveille un parc complet de blogs statiques — conteneurs, certificats, builds, sitemaps — depuis une seule interface.

Le cas d'usage est celui des agences web et SEO : elles administrent souvent des dizaines de sites clients, et chacune des tâches répétitives de mon flux (build, rsync, certificat, redémarrage) devient une opération centralisée. Le statique rend ce scénario réaliste : un blog hébergé coûte quelques dizaines de mégaoctets et zéro maintenance de sécurité, donc l'exploitation d'un parc entier reste légère.

Une précision toutefois, parce que le terrain s'y prête : l'objectif n'est pas de fabriquer des fermes de liens vers des sites clients — Google classe ces réseaux dans ses pratiques de spam, avec des pénalités qui touchent parfois les clients eux-mêmes. La valeur du produit est ailleurs : **remplacer vingt interventions manuelles par un clic** — déploiement, supervision, publication et reporting sur un parc de sites légitimes. C'est du SaaS B2B honnête, et c'est la direction que je donne à ce projet.

## Ce que j'y gagne, honnêtement

Ce générateur ne rivalisera pas avec Hugo sur les fonctionnalités — Hugo fait bien plus. Mais chaque ligne de mon site, je sais pourquoi elle existe. Quand une page rame, j'ouvre un fichier. Quand la Search Console me signale un souci, je corrige à la source. Après douze ans à réparer des machines industrielles, c'est ma définition d'un outil fiable : assez simple pour être compris par une seule personne.

Le code est sur GitHub. Si vous le testez, dites-moi ce que vous en pensez — le flux RSS est là pour ça.
