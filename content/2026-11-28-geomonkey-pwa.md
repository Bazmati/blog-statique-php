---
title: Géomonkey — PWA : pourquoi personne n'arrivait à installer l'app alors que tout était en place
date: 2026-11-28
description: Manifest, service worker, thème : tout était là depuis la v1.2, et pourtant Chrome refusait de proposer l'installation. Récit d'un débogage mobile où le coupable est un PNG de 150 octets et une ligne de mime.types.
category: Développement web
tags: symfony, geomonkey, pwa, manifest, service-worker, webp, nginx, mobile
image: assets/pwa-geomonkey.jpg
image_alt: Illustration : silhouette de singe tenant un smartphone d'où émerge une icône d'application, avec un engrenage et une coche verte en arrière-plan
---

## La fonctionnalité invisible qui ne marchait pas

La série Géomonkey s'est terminée sur les tests et la qualité, et j'avais annoncé la suite pour la V2. Mais un sujet est resté sur le carnet, et il mérite mieux qu'une ligne de backlog : la **PWA**. Une application web installable, avec son icône sur l'écran d'accueil du téléphone, qui s'ouvre en plein écran sans barre d'adresse. Pour une association qui organise des événements, c'est l'écran d'accueil du membre, à un clic du programme. Gratuit, sans App Store, sans compte développeur à 99 dollars par an.

La partie « visible » de la PWA était en place depuis la v1.2 : le **manifest** (nom, couleurs, icônes), le **service worker**, la **theme-color**. En théorie, Chrome Android devait proposer « Installer l'application ». En pratique : rien. Aucun visiteur n'a jamais vu cette proposition, moi compris, et comme personne ne l'attendait, personne ne l'a signalée.

C'est le genre de bug que la prod débusque parce qu'il n'y a personne d'autre pour le voir : une fonctionnalité silencieuse qui échoue silencieusement, sans erreur, sans log, sans test rouge.

## Le manifest passe à l'inspection

Le débogage commence par faire parler le navigateur. Sur Chrome, l'onglet **Application** des devtools (ou le petit menu de la barre d'adresse côté mobile) affiche le manifest tel que le navigateur l'interprète : les trois grandes causes d'un refus d'installation y apparaissent en majuscules.

Chez moi, tout semblait vert. Puis j'ai regardé de plus près les **icônes** : le manifest désignait ses PNG, les fichiers existaient sur le serveur, les URLs répondaient. Sauf qu'un `curl -I` de plus a révélé la première arnaque : l'un des fichiers pesait **150 octets**. Un PNG de 150 octets, ce n'est pas une icône, c'est un accident de génération, ou un fichier vide passé par un convertisseur en ligne qui a échoué sans prévenir. Et l'icône `512x512`, celle que Chrome exigeait, n'existait même pas.

Leçon notoire des PWA : **un manifest n'est valide que par son fichier le plus faible**. Le navigateur ne propose l'installation que si chaque taille déclarée pointe vers une image réelle, valide, du bon format. Une seule icône cassée, et c'est toute la fonctionnalité qui disparaît, sans messages d'erreur visible côté visiteur.

## Le coupable suivant : nginx et ses types MIME

Deuxième piège, plus fin : les fichiers répondaient bien, mais avec un `Content-Type` incorrect. NGINX, n'ayant pas le type `webmanifest` dans son fichier `mime.types` par défaut de la Debian du CT 202, servait le manifest en `application/octet-stream` : un fichier binaire générique, pas un manifest. Un navigateur méfiant refusera d'interpréter ce qui n'est pas déclaré comme manifeste.

La correction tient en trois lignes, mais se vérifie toujours avant rechargement :

```bash
# /etc/nginx/mime.types : la ligne application/json existe déjà,
# on ajoute webmanifest à la liste
application/json  json webmanifest map topojson;

nginx -t && systemctl reload nginx
```

Le `nginx -t` n'est pas décoratif : un mime.types cassé peut refuser de démarrer le serveur entier, sur un conteneur qui porte un site en production. Toujours le réflexe `nginx -t`, toujours le reload, jamais l'inverse.

## Icônes refaites, proprement

Plutôt que de réparer les fichiers accidentels, j'ai régénéré tout le pack d'icônes **depuis le logo source** : 192, 512, maskable (la variante à zone de sécurité pour Android, dont le fond doit déborder l'icône), apple-touch, et favicon. Un seul fichier source, un script de génération, un dossier.

Cela a soulevé un détail de gouvernance du dépôt : le `public/images/` de l'application était gitignoré, donc le branding de l'application vivait hors du dépôt. Inversement au principe du premier article (ce qui est le produit se versionne), le branding est le produit : il est désormais versionné, et seul `public/uploads/` reste ignoré, pour la bonne raison que c'est le dossier des contenus utilisateurs, qui n'ont rien à faire dans l'historique git (et qui contiennent des photos de personnes, RGPD oblige).

## Le résultat, sur un vrai téléphone

Sur Chrome Android, sur le site en production : menu, « Installer l'application », et l'icône du singe apparaît sur l'écran d'accueil. L'application s'ouvre en **standalone**, plein écran, dans le noir #1C1C1A de l'identité du site, sans barre d'adresse. Une PWA, ce n'est pas une application native : c'est le site, mais il se comporte comme une appli quand quelqu'un lui demande. Une association n'a pas besoin de plus, et surtout, elle n'a pas besoin de payer pour ça.

Reste au backlog un détail cosmétique : le favicon racine `public/favicon.ico` référencé par l'admin n'existe pas, et plante un 404 discret dans les logs à chaque visibilité de la console d'administration. Le genre d'imperfection qu'on note dans la doc d'exploitation, et qu'on corrige un mardi.

## Ce que cette histoire raconte, au fond

Trois leçons, toutes dans l'esprit de la série :

1. **Une fonctionnalité silencieuse doit être testée à voix haute** : personne ne signalera qu'une installation « ne se propose pas ». Il faut la déclencher soi-même, sur un vrai appareil, en production, et le noter dans le tour humain post-déploiement.
2. **Le navigateur est le seul juge de paix d'une PWA** : le manifest peut être « correct » à la lecture de la doc et refusé dans les faits, à cause d'une icône de 150 octets ou d'un type MIME générique.
3. **Un serveur web a un fichier de configuration que personne ne lit** : `mime.types` est ce genre de fichier, utile pendant quinze ans jusqu'au jour où un format nouveau (webmanifest, puis avif, puis le prochain) en dépend.

## La suite

La série Géomonkey reprendra son cours avec la V2 (boutique, billetterie, comptabilité de l'association). En attendant, l'application s'installe depuis un navigateur : ouvrez [www.geomonkey.fr](https://www.geomonkey.fr) sur Android, menu, « Installer l'application ».

Le code est ouvert : [github.com/Bazmati/geomonkey-master](https://github.com/Bazmati/geomonkey-master).
