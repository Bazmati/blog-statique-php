---
title: Géomonkey — 433 tests, la suite qui relit chaque commit toute seule
date: 2026-11-24
description: Ce que couvrent, et surtout ce que ne couvrent pas, 433 tests d'une plateforme Symfony en production. Et comment une suite de tests devient le rituel de déploiement d'un développeur solo assisté par l'IA.
category: Développement web
tags: symfony, geomonkey, phpunit, tests, qualite, ia
image: assets/tests-geomonkey.jpg
image_alt: Illustration : silhouette de singe devant un tableau de bord de barres et cartes à cocher vertes
---

## Le vrai rôle d'une suite de tests quand on est seul

Le premier article de cette série disait une chose qui a surpris certains lecteurs : le projet est développé seul, avec l'assistance de l'IA, mais chaque ligne est lue et validée avant d'être commitée. Ce sixième article raconte l'autre moitié de cette phrase, celle qui tient la première en équilibre : **la suite de tests**.

Quand on développe en équipe, la revue de code par un pair rattrape les erreurs d'inattention. Quand on est seul, il n'y a pas de pair — ou plutôt, il y en a un, qui ne s'endort jamais, ne se lasse jamais de rejouer les mêmes scénarios, et répond en quelques dizaines de secondes :

```bash
vendor/bin/phpunit
```

433 tests, quelques milliers d'assertions, un statut qui doit rester vert avec uniquement les non-conclusifs déjà connus. C'est l'invariant central du projet.

Et depuis peu, il ne tient plus seulement sur ma machine : une **CI GitHub Actions** rejoue la suite à chaque push, adossée à un PostgreSQL de service — ce qui force à maintenir la suite lançable partout, pas juste sur le poste où elle est née. Le badge du dépôt affiche ce vert à chaque visiteur.

## Ce que les 433 tests couvrent

Le socle est classique mais complet :

* **Les entités** : le code des associations et le comportement des invariants ;
* **Les formulaires** : chaque champ — labels compris, une assertion sur le libellé d'une fonction d'élu tient en une ligne ce qu'un cahier des charges tient en une page ;
* **Les contrôleurs fonctionnels** : parcours complets en HTTP réel, dans une base nettoyée par transaction entre chaque test ;
* **Les services critiques** : les mailers (qui vérifient réellement les envois interceptés), le traitement des images, et surtout la logique d'activation des adhésions du quatrième article, testée sans jamais parler à Stripe ;
* **Les voters et la gouvernance** : pour chaque rôle, pour chaque action, l'assertion attend le verdict exact — le vice-président peut éditer le contenu du bureau, il ne peut pas le supprimer, et c'est vérifié à part.

Ce dernier point est le plus important à mes yeux : **ce sont les tests qui rendent la gouvernance vérifiable**. Un règlement intérieur est une intention ; un test qui échoue quand il n'est pas respecté est une garantie.

## Ce qu'ils ne couvrent pas — et pourquoi je l'assume

Dire ce que la suite ne teste pas est plus important que d'afficher un total. Trois absences, en particulier, sont assumées :

**Les emails envoyés au vrai serveur SMTP.** Les tests vérifient que l'email part, pas qu'il arrive. Le deuxième article racontait les pièges du prestataire réel — aucun test automatisé ne peut couvrir ça, et c'est le tour humain de la prod qui le couvre.

**Le rendu visuel.** Un test peut vérifier qu'un bouton est présent dans la page ; il ne vérifie pas que deux textes ne se chevauchent pas. Un vrai chevauchement de libellé sur le formulaire d'adhésion a été repéré par mes yeux, en prod, pas par la suite — et corrigé au template, pas au test. Les tests protègent la logique ; l'humain protège l'expérience.

**Ce qui n'existe pas encore.** La 500 du filtre Twig manquant (premier article) est passée au travers d'une suite pourtant verte : le code défaillant résidait dans un chemin de template jamais rendu par un test. La leçon y était d'ailleurs : après chaque déploiement, un tour humain des pages critiques. Les tests vérifient ce qu'on a pensé à vérifier ; la production vérifie tout le reste.

## Le rituel : là où l'IA rejoint la suite

Le pont entre ces deux moitiés — l'IA qui écrit, la suite qui relit — c'est le rituel de déploiement décrit dans le premier article : correctif écrit contre une copie propre, appliqué en patch, **et les tests tournent avant chaque commit**. Si un test casse, le patch ne part pas en production. Pas de compromis.

Ce que ce dispositif apporte à un développeur solo assisté par l'IA est difficile à exagérer. Quand un correctif arrive sous forme de patch, la tentation de « l'appliquer parce que ça a l'air juste » est grande ; le rituel impose la discipline inverse : chaque patch se heurte à la suite, et c'est elle qui décide. L'IA propose, la suite dispose — l'humain tranche seulement quand les deux sont d'accord. C'est une exigence de plus que de lire le code : c'est le prouver, à chaque déploiement.

Et le statut attendu est documenté avec la précision d'un reçu : 433 tests, un compte précis d'assertions, une liste fermée de non-conclusifs. Toute dérive de ces nombres est un signal en soi — un test qui passe de « skip » à « fail », ou un total qui change sans raison, mérite la même enquête qu'une erreur visible.

## Ce que ça donne, six articles plus tard

Cette série est née avec la production de Géomonkey, et elle se termine au moment où l'application vit sa première période réelle : de vrais adhérents, de vrais événements, un bureau qui utilise le backoffice. Le bilan technique tient en une phrase par article — un déploiement qui n'est jamais une aventure, des emails qui partent, une gouvernance vérifiable, des paiements qui s'activent seuls, des images légères, et une suite de tests qui relit chaque commit toute seule.

La plateforme continue : la V2 (boutique, billetterie, comptabilité asso) est sur la feuille de route, et le code reste ouvert pour qui veut y mettre la main : [github.com/Bazmati/geomonkey-master](https://github.com/Bazmati/geomonkey-master). L'avancement se suit sur [www.geomonkey.fr](https://www.geomonkey.fr).

Merci d'avoir suivi cette série — la suite s'écrira avec la V2.
