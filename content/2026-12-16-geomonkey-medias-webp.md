---
title: Géomonkey — médias : trois tailles, du WebP, et pourquoi cette brique est codée à la main
date: 2026-11-24
description: Galerie photos d'une asso : upload sécurisé, redimensionnement en trois tailles, conversion WebP à la volée. Et pourquoi le traitement d'images est la brique qu'on ne délègue pas à un bundle.
category: Développement web
tags: symfony, geomonkey, images, webp, intervention-image, performance
image: assets/medias-geomonkey.jpg
image_alt: Illustration : silhouette de singe sur une chaîne de traitement qui décline une photo en trois tailles converties
---

## Une galerie d'asso, c'est des photos de téléphone à 8 Mo

La galerie photos est une des fonctionnalités les plus utilisées de Géomonkey : chaque événement génère des dizaines de photos, prises au téléphone par les membres, rarement en dessous de 8 Mo. Multipliées par les événements et les années, une galerie naïve devient vite la partie la plus lourde du site — et la première cause de lenteur pour un visiteur sur mobile.

Le besoin fonctionnel est simple : le bureau uploade une photo, elle apparaît dans la galerie. Le besoin réel, lui, est double :

* **côté légèreté** : le visiteur d'un événement ne doit pas télécharger des originaux de 8 Mo pour voir des vignettes de 400 pixels ;
* **côté gouvernance** (RGPD, toujours) : une photo publiée doit être le résultat d'un choix explicite, pas d'un glisser-déposer accidentel.

Voici comment la brique médias est construite, et pourquoi elle tient dans du code qu'on écrit soi-même plutôt que dans un bundle.

## Trois tailles, pas trente

À l'upload, chaque image est déclinée en trois tailles — 300, 800 et 1200 pixels de large — chacune avec sa compression propre (80, 85, 90) : une petite pour les vignettes et listes, une moyenne pour les grilles, une grande pour l'affichage plein écran. L'original est conservé pour l'archivage, mais jamais servi au navigateur.

Trois tailles, pas davantage. C'est tentant de vouloir couvrir chaque cas d'affichage au pixel près ; en pratique, chaque taille supplémentaire multiplie le stockage et rend caduque la moitié de la liste dès qu'on retouche le design. La règle de la galerie : les tailles servent le design, pas l'inverse.

Le traitement est fait par **Intervention Image**, la référence PHP en la matière, appelée dans un service maison. La distinction est importante et elle mérite un mot : Intervention est une **bibliothèque** (on l'appelle, elle ne s'impose pas), pas un **bundle** (qui enregistrerait ses routes, ses contrôleurs, ses vues et son cycle de vie dans l'application). Le premier article de cette série posait la frontière : un bundle quand il protège, du code maîtrisé quand c'est le produit. Les médias sont le produit — une bibliothèque étudiée, appelée à la main, dans un seul service.

## WebP : le format qui divise le poids par quatre

Chaque taille est convertie en **WebP** à l'upload. Le gain est massif — à qualité visuelle égale, un WebP pèse typiquement 25 à 40 % du JPEG équivalent — et il se mesure surtout là où il compte : sur mobile, en 4G, dans une grille de douze vignettes.

Pourquoi à l'upload et pas à la volée, à chaque requête ? Parce que convertir une image coûte du CPU à chaque visiteur, alors que l'uploader ne convertit qu'une fois. Une plateforme d'asso a un trafic de lecture largement supérieur à son trafic d'écriture — tout le travail doit donc être déplacé du côté de l'écriture. La première visite d'une galerie paie le prix du calcul ; toutes les suivantes naviguent dans des fichiers déjà prêts.

Côté Twig, une petite extension expose le choix de taille au template :

```twig
<img src="{{ image|image_url('medium') }}" alt="...">
```

Le template exprime une intention (quelle taille d'affichage) et laisse le service gérer la mécanique (où est le fichier, quelle variante existe). Ajouter une taille plus tard, ou changer la compression, ne touche aucun template ni aucun test fonctionnel lié aux vues.

## L'upload, moment de tous les dangers

Le moment le plus délicat n'est pas le redimensionnement, il est avant : l'upload. Une image qui arrive sur le serveur est un fichier arbitraire qui porte une extension honnête en apparence. Trois contrôles, dans cet ordre :

* **La vérification du type réel** — le type MIME annoncé par le navigateur n'est qu'une déclaration ; le vrai format se lit dans les octets du fichier (`finfo`). C'est ce contrôle qui sépare une JPEG au nom malfaiteur d'un script au nom innocent.
* **La limite de taille**, rejeter avant tout traitement ce qui dépasse le raisonnable — 5 Mo par image côté application, bien en dessous des 50 Mo acceptés par le serveur pour l'upload lui-même.
* **Le nommage contrôlé** : le fichier stocké porte un nom généré par l'application (une UUID de 32 caractères hexadécimaux), jamais le nom d'origine. Un nom d'origine peut contenir n'importe quoi — et sert parfois de vecteur d'injection de chemin dans du code peu prudent. (Et pour tout dire, l'UUID m'a aussi simplifié la vie : quand la taille des vignettes a changé, aucun nom de fichier à migrer.)

Côté stockage, une contrainte silencieuse pour la prod : les variantes vivent hors du dépôt git (`uploads/` est gitignoré), servies par nginx en statique et jamais versionnées. La gouvernance du deuxième article (une photo publiée, validée) s'applique en amont, au niveau de l'action d'upload et de publication — c'est la base de données qui porte l'état, pas le système de fichiers.

## Ce qu'on mesure

Une galerie d'asso doit tenir une promesse simple : une grille de douze vignettes visibles sur mobile en moins de deux secondes en terrain moyen. Avec trois tailles bien dimensionnées et du WebP, une vignette pèse quelques dizaines de kilo-octets — la page entière reste très en dessous de ce qu'un original non traité aurait imposé. Et le plus beau, il n'y a presque rien à mesurer : la différence se voit.

## La suite

Le prochain article parlera de tests et qualité : ce que couvrent — et ne couvrent pas — les 433 tests de la plateforme, et pourquoi ils sont devenus le rituel de déploiement.

Le code est ouvert : [github.com/Bazmati/geomonkey-master](https://github.com/Bazmati/geomonkey-master).
