---
title: Géomonkey : une plateforme web Symfony 8 pour les associations — du besoin réel à la production
date: 2026-11-10
description: Pourquoi j'ai développé une plateforme web complète pour une association réelle : gestion des adhérents, événements, galerie photos, paiement Stripe et RGPD. Symfony 8, PHP 8.4, PostgreSQL, hébergée sur Proxmox.
category: Développement web
tags: php, symfony, geomonkey, projet, open-source, stripe
---

Une association qui organise des événements culturels et artisanaux, ça semble simple à gérer. En pratique : des adhérents à suivre, des cotisations à encaisser, des événements à publier, des photos à partager, des pages légales à tenir à jour — et souvent, rien d'autre qu'un tableur partagé et une messagerie pour tenir tout ça ensemble.

Les solutions existantes ne collaient pas au besoin : les SaaS associatifs sont payants et enferment les données, les outils génériques demandent des contorsions, et rien de libre ne couvrait l'ensemble « site vitrine + gestion des adhérents + événements + paiements » dans un seul outil auto-hébergeable.

C'est de ce constat qu'est née **Géomonkey** : une plateforme web complète pour associations, développée seule, de bout en bout — de l'analyse du besoin au déploiement.

## Ce qu'est Géomonkey

Géomonkey, c'est deux choses dans une même application :

*   **un site vitrine public** : présentation de l'association, événements, blog, galerie photos ;
*   **un espace de gestion privé** : adhérents, rôles et permissions, événements et participants, contenus.

Côté pile technique, des choix assumés et récents :

*   **Backend** : PHP 8.4, Symfony 8, Doctrine ORM 3 avec migrations versionnées
*   **Frontend** : Twig, Bootstrap 5.3, Stimulus et Turbo (Symfony UX)
*   **Base de données** : PostgreSQL
*   **Images** : Intervention Image — redimensionnement en trois tailles et conversion WebP
*   **Paiements** : Stripe pour les adhésions en ligne, avec webhook dédié

La V1 est déjà bien avancée : gestion des utilisateurs et des rôles, CRUD des événements et des participants, galerie photos avec traitement d'images, blog, pages de présentation, pages légales (conditions d'adhésion, politique de confidentialité) et consentement RGPD. Une page statique sur **www.geomonkey.fr** présente l'avancement du projet : ce qui est fait, ce qui reste à faire.

Le code est public sur GitHub, et ce n'est pas un hasard : toute personne qui souhaite rejoindre l'association et donner sa patte à la réalisation du projet est la bienvenue. Un projet libre, c'est aussi un projet qui peut vivre sans son auteur.

## Le projet est réel — et c'est ce qui change tout

Géomonkey n'est pas un projet de démonstration. Elle a été imaginée pour une association réelle, avec de vraies contraintes : des utilisateurs non techniques, des données personnelles à maîtriser, de l'argent qui circule, et un site public qui doit rester accessible.

Un projet réel impose ce qu'un projet d'école ne peut pas simuler : on ne peut pas esquiver le RGPD, on ne peut pas faire « ça marchera en prod », on ne peut pas ignorer ce qui se passe quand un paiement échoue. Chaque décision technique part d'un besoin concret — et c'est cette discipline que ce projet démontre, commit après commit, depuis fin septembre 2026.

Un mot sur la méthode, parce qu'un rythme pareil appelle une question : ce projet a été développé seul, mais **avec l'assistance de l'IA**. L'IA accélère l'écriture, propose des pistes, débloque les points techniques — mais elle ne décide rien : chaque choix d'architecture est tranché après compréhension, chaque ligne est lue et validée avant d'être commitée. Savoir piloter l'IA sans lui déléguer la responsabilité du code, c'est devenu une compétence de développement à part entière. C'est celle-ci qui permet à une personne seule de tenir le rythme d'un projet complet — sans jamais sacrifier la compréhension de ce qu'on livre.

## La fonctionnalité clé : l'adhésion en ligne avec Stripe

Parmi tout ce que couvre la plateforme, une fonctionnalité résume bien la démarche : **le paiement de l'adhésion en ligne**.

Le besoin : un nouvel adhérent paie sa cotisation sur le site public, et son adhésion doit être activée automatiquement, sans intervention manuelle du trésorier.

La solution : un paiement **Stripe** côté utilisateur, puis un **webhook** — Stripe notifie le serveur de l'application quand le paiement est confirmé, et c'est cette notification, pas le retour du navigateur, qui active l'adhésion. Pourquoi un webhook ? Parce qu'un utilisateur peut fermer son navigateur avant le retour de paiement, parce qu'un paiement peut être confirmé avec un léger délai, et parce que faire confiance au seul navigateur, c'est faire confiance à un client qu'on ne contrôle pas.

Un détail du code suffit à montrer l'esprit de la chose. Toute notification de webhook est vérifiée avant d'être traitée :

```
$event = \Stripe\Webhook::constructEvent(
    $payload,
    $request->headers->get('stripe-signature'),
    $this->webhookSecret
);

if ($event->type === 'checkout.session.completed') {
    $session = $event->data->object;
    // Active l'adhésion correspondant à la session de paiement
    $this->membershipService->activateFromCheckout($session);
}
```

Quelques lignes, mais elles portent une décision : **aucune donnée venant de l'extérieur n'est traitée sans vérification**. La signature du webhook est contrôlée avant tout accès aux données, ce qui empêche quiconque d'appeler l'endpoint en imitant Stripe. L'idempotence des paiements, la gestion des cas d'erreur et les tests associés méritent chacun leur article — ce sera l'objet d'un billet à part dans cette série.

### Pourquoi Stripe — et pourquoi pas un bundle pour tout

Le choix de Stripe n'est pas un choix de confort, c'est un choix d'architecture. Trois raisons :

*   **Le bundle officiel Symfony** s'intègre proprement dans l'écosystème (configuration, services, tests), plutôt qu'un SDK collé à côté de l'application ;
*   **La sécurité** : la gestion du paiement, la lutte contre la fraude et la conformité PCI DSS sont le métier de Stripe, pas le mien ;
*   **Le RGPD** : l'application ne stocke aucun numéro de carte. Les données de paiement sont traitées côté Stripe, ce qui décharge l'association d'un dossier RGPD lourd — un point déterminant pour une petite structure.

Mais la règle n'est pas « un bundle pour tout ». Le traitement des images, par exemple, est fait sans bundle tout intégré : redimensionnement, trois tailles, conversion WebP. Pourquoi l'inverse de Stripe ? Parce que la gestion d'images est **cœur du métier de l'application** et que déléguer cette partie, c'est perdre la main sur un composant critique — et s'exposer à un système qui évolue selon le calendrier d'un tiers, avec ses mises à jour, ses correctifs de sécurité, voire ses failles. Un bundle quand il protège (paiements), du code maîtrisé quand c'est le produit (médias) : c'est cette frontière, redessinée cas par cas, qui fait la qualité d'une architecture.

## Où en est le projet, et quand en production

L'application a été développée seule, sur un rythme de commits réguliers depuis fin septembre 2026. La **mise en production est prévue pour fin novembre 2026**, hébergée sur un serveur **Proxmox** dans un conteneur **LXC** — pas de Docker ici : l'isolation est déjà assurée au niveau du serveur, et le conteneur LXC garde l'application proche de son système, simple à administrer et légère en ressources.

La feuille de route est déjà tracée :

*   **V1, à finir** : les **dons en ligne** — le paiement Stripe étant opérationnel pour les adhésions, c'est une extension naturelle ;
*   **V2** : une **boutique de goodies**, une **billeterie** pour les événements, et un **gestionnaire de comptabilité** pensé pour les associations loi 1901 ;
*   **V3** : les outils **Flyers Generator** et **Ticket Generator** — génération de supports pour les événements.

## Une série d'articles pour aller plus loin

Cet article est le premier d'une série consacrée à Géomonkey. Les sujets à venir, dans l'ordre :

1\. Architecture et setup : Symfony 8, Docker, Doctrine Migrations — pourquoi ces choix

2\. Sécurité : formulaires, validation, contrôle d'accès, Cloudflare Turnstile, RGPD en pratique

3\. Paiements Stripe en profondeur : webhooks, idempotence, gestion des erreurs

4\. Médias et performance : upload, redimensionnement, conversion WebP

5\. Recherche globale : modal Twig avec Stimulus et Turbo

6\. Tests et qualité : PHPUnit, ce qui est testé et pourquoi

7\. Mise en production : déploiement sur Proxmox en conteneur LXC, sans Docker

Le code est ouvert et consultable sur GitHub : github.com/Bazmati/geomonkey-master. L'avancement du projet est présenté sur www.geomonkey.fr — et si l'envie de participer vous prend, le projet est public pour ça.
