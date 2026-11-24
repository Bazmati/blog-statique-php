---
title: Géomonkey — paiements Stripe en profondeur : webhooks, idempotence, et les 0,1 % qui font tout tomber
date: 2026-11-24
description: Adhésion en ligne dans une asso réelle : pourquoi le webhook Stripe est la seule source de vérité, comment vérifier une signature, rendre le traitement idempotent, et couvrir les erreurs qui n'arrivent presque jamais.
category: Développement web
tags: symfony, geomonkey, stripe, webhook, paiement, idempotence
image: assets/stripe-geomonkey.jpg
image_alt: Illustration : silhouette de singe observant une carte de paiement dont le faisceau traverse un bouclier à coche verte vers un serveur
---

## Le paiement réussi, puis l'utilisateur ferme son onglet

Le premier article de cette série décrivait le besoin : un nouvel adhérent paie sa cotisation, et son adhésion doit être activée **sans intervention manuelle du trésorier**. La partie visible — rediriger vers Stripe Checkout, encaisser, revenir — est la moitié facile du problème.

La moitié difficile commence ici : **et si l'utilisateur ferme son navigateur avant le retour ?** Et si sa connexion tombe pendant la redirection ? Et si le paiement est confirmé avec quelques secondes de retard, alors que la page de retour a déjà fini de charger ? Si l'activation de l'adhésion dépend du retour du navigateur, elle ne se produira pas dans tous ces cas — et l'asso encaisse un paiement pour un adhérent qui n'existe toujours pas dans la base.

La réponse tient en une phrase de design : **la présence ou l'absence du navigateur ne doit jamais être une condition de correction**. Le navigateur est un client qu'on ne contrôle pas ; il n'est jamais la source de vérité. La source de vérité, c'est Stripe — et Stripe parle par webhook.

## Le webhook : Stripe qui vient à nous

Un webhook, c'est Stripe qui appelle une URL de notre application à chaque événement important du paiement. Pas l'utilisateur, pas son navigateur : Stripe, depuis ses serveurs, avec un payload signé. Le squelette du contrôleur :

```php
try {
    $event = $this->stripeService->verifyWebhookSignature($payload, $sigHeader);
} catch (SignatureVerificationException $e) {
    return new Response('Webhook Error: Invalid signature', 400);
}

if ($event->type === 'checkout.session.completed') { /* activer */ }
```

Trois lignes de logique, mais chaque ligne porte une décision :

1. **La vérification de signature est la première chose**, avant toute lecture des données. Le secret partagé entre Stripe et l'application garantit que l'appel vient bien de Stripe — sans lui, n'importe qui devine l'URL de webhook pourrait activer des adhésions gratuitement, ou pire, pour un tiers. Une URL publique qui modifie des données doit toujours prouver qui parle. Vécue en production : un secret dupliqué dans la configuration (préfixe `whsec_` en double) et c'est un 400 systématique, des dons restés « en attente » en base — le genre de bug que seule la prod révèle.
2. **On écoute `checkout.session.completed`, pas « le navigateur est revenu »** : c'est Stripe qui certifie le paiement, au moment où il le certifie — même si le payeur a coupé le web depuis dix minutes.
3. **La signature est déléguée au service** (`StripeService::verifyWebhookSignature()`), la logique d'activation dans des méthodes privées du contrôleur : le webhook n'est qu'un point d'entrée, et la logique d'activation est testée à part — le prochain test qui la manipule n'a pas besoin d'un serveur Stripe derrière.

## Idempotence : parce que Stripe réessaie — et qu'il a raison

Un point que la documentation Stripe mentionne poliment, mais qui mérite d'être écrit en gras dans tout article honnête : **Stripe réessaie les webhooks qui échouent, et il peut aussi livrer deux fois le même événement.** Si le réseau hésite, si le serveur répond lentement, Stripe réémet l'appel. C'est un bon comportement — mais si le traitement n'est pas **idempotent**, la même adhésion peut être activée deux fois, ou pire, générer deux lignes de cotisation.

Le remède dépend du type d'événement, et c'est là qu'un article honnête doit préciser ce que fait le code, pas ce que la doc promet.

**Pour les dons, l'idempotence est explicite** : le don est rapproché de sa ligne en base (via un `donation_id` posé dans les metadata à la création de la session), et un don déjà `paid` n'est pas retraité :

```php
// Idempotence : ne pas retraiter un don déjà enregistré (Stripe renvoie parfois
// le même événement plusieurs fois)
if ($donation->getPaymentStatus() === Donation::STATUS_PAID) {
    return new Response('Donation already processed', 200);
}
```

**Pour les adhésions, c'est le modèle de données qui tranche** : le traitement désactive toutes les adhésions existantes de l'utilisateur puis crée la ligne courante — un événement livré deux fois aboutit au même état final (une seule adhésion active), et l'email de confirmation part dans un `try/catch` qui ne fait jamais échouer le webhook. Le résultat dépend de l'événement, pas du nombre de livraisons.

Ça a une conséquence sur la réponse HTTP : recevoir un événement déjà traité doit répondre **200, pas 409**. Une erreur ferait réessayer Stripe — sur un cas déjà résolu, donc du bruit en plus d'une dose de panique dans les logs.

## Les erreurs qui n'arrivent presque jamais

Les tests couvrent le parcours qui arrive 99,9 % du temps. La prod, elle, vit aussi dans les 0,1 % restants. Trois familles à couvrir explicitement :

* **L'événement inconnu** : Stripe envoie beaucoup de types d'événements (`payment_intent.payment_failed`, `checkout.session.expired`, etc.). Un contrôleur qui traite uniquement `checkout.session.completed` doit répondre 200 aux autres — sinon Stripe réessaie sans fin un événement que l'application ignore volontairement. Ignorer proprement n'est pas échouer.
* **La signature invalide** : répondre 400, ne rien traiter, et le journaliser. C'est potentiellement une attaque — le log est alors la seule trace.
* **La panne partielle** : si la transaction échoue au milieu, l'adhésion ne doit rester ni activée à moitié ni rejetée — la base doit retomber sur ses pieds, et Stripe réessayera dans quelques minutes. Un webhook bien conçu est **retry-safe** par construction.

## Ce que ça change en exploitation

Le résultat, pour l'asso : le trésorier ouvre son backoffice, voit les adhésions qui se sont activées seules pendant la nuit, et n'a jamais ouvert Stripe. Le paiement en ligne n'est pas une fonctionnalité du site — c'est un service qui tourne sans personne, et c'est précisément ce qui fait qu'une petite structure peut en vivre.

Un dernier point d'exploitation, souvent oublié : le secret du webhook vit dans la configuration serveur (`.env`), jamais dans le dépôt. Si le secret fuit, il faut le régénérer côté Stripe — sans quoi toutes les protections de cet article ne sont qu'une ligne de code morte.

## La suite

Le prochain article parlera de médias et performance : upload, redimensionnement en trois tailles, et conversion WebP — pourquoi cette brique a été écrite à la main plutôt que prise dans un bundle.

Le code est ouvert : [github.com/Bazmati/geomonkey-master](https://github.com/Bazmati/geomonkey-master).
