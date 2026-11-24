---
title: Géomonkey — envoyer des emails en production, le parcours complet (DSN, GMX, gabarit commun)
date: 2026-11-24
description: Passer d'un dump dans les logs de dev à de vrais emails transactionnels envoyés par un serveur SMTP externe : encodage de mot de passe dans la DSN, pièges GMX, gabarit responsive et sombre partagé par 9 mails.
category: Développement web
tags: symfony, geomonkey, email, smtp, gmx, twig
image: assets/emails-geomonkey.jpg
image_alt: Illustration : silhouette de singe à côté d'une enveloppe lumineuse dont les particules voyagent vers un serveur distant
---

## En dev, un email est un mensonge confortable

En développement, Symfony propose par défaut un « mailer » qui n'envoie rien : il écrit les emails dans un fichier, il ne les envoie pas. Ce qu'on voit dans le dump n'est pas ce que lira le destinataire. Ni le reverse DNS, ni l'authentification SMTP, ni le filtre antispam, ni le rendu du webmail — rien de tout ça n'existe dans un fichier de log.

Passer Géomonkey en production a déplacé ce problème du côté des vrais. Voici le parcours complet : configuration du transport SMTP avec GMX, les pièges d'encodage qui m'ont coûté une soirée, et la mise en place d'un gabarit commun pour que 9 emails transactionnels ne soient pas stylés 9 fois.

## La DSN : quand votre mot de passe devient une URL

Avec Symfony Mailer, tout part d'une chaîne de configuration dans l'environnement, le `MAILER_DSN` :

```
MAILER_DSN=smtp://utilisateur:motdepasse@serveur:port
```

Jusque-là, lisible. Sauf que cette chaîne **est une URL** — et que son contenu doit donc respecter les règles d'encodage des URLs avant d'être interprété comme un couple identifiant/mot de passe. Mon mot de passe GMX contenait un `%`. Dans une URL, `%` introduit une séquence d'échappement (`%41` = « A », par exemple) — et un `%` suivi de caractères aléatoires produit des erreurs de décodage. Le remède, contre-intuitif mais logique une fois qu'on l'a compris : **encoder le caractère qui encode**, `%` devient `%25` (qui est, lui-même, le code URL de `%`). Même logique pour `#`, qui dans une URL marque le début d'un fragment et doit donc devenir `%23`.

Mon identifiant, de son côté, est une adresse email complète — le `@` doit lui aussi être échappé en `%40` :

```
MAILER_DSN=smtp://geomonkey%40gmx.fr:mot%25depasse@mail.gmx.net:587
```

Aucune documentation ne m'avait signalement ce point — l'erreur qui en résulte est un « authentication failed » qui ressemble à un mauvais mot de passe. Alors qu'elle signifie surtout : votre mot de passe a été déformé en route.

Autre point souvent oublié : l'adresse expéditeur. Elle est portée par le service d'envoi (`fromAddress`, par défaut `noreply@geomonkey.fr`), pas laissée à la valeur générique de Symfony. Sans elle, certains serveurs destinataires classent le message en spam avant même de l'avoir lu.

## GMX : un serveur gratuit, mais qui exige d'être réveillé

Le compte GMX est gratuit, ce qui correspond au budget d'une association loi 1901. Mais gratuit, chez GMX, veut dire certaines exigences.

L'envoi SMTP exige que les accès **POP3/IMAP** soient activés dans le webmail — désactivés par défaut. Et surtout : GMX **désactive tout seul ces accès** si le compte reste inactif pendant quelques semaines, sans prévenir. Le premier signe est toujours le même : les emails cessent de partir, avec une erreur d'authentification.

Pour une asso, c'est une bombe à retardement — le compte tombe en panne pendant les vacances de celui qui l'a configuré, et personne ne sait pourquoi. J'ai noté ce point dans la documentation d'exploitation du site, avec la marche à suivre : se connecter au webmail GMX, réactiver POP3/IMAP dans les paramètres, et vérifier l'envoi d'un email test. C'est un point à faire figurer dans toute procédure de supervision d'emails transactionnels.

## Neuf mails, un seul gabarit

La plateforme envoie 9 emails transactionnels : confirmation d'inscription, validation d'adhésion, réinitialisation de mot de passe, notification de contact, etc. Au départ, chacun était stylé séparément, dans sa propre logique Twig — 9 variations à maintenir. La refonte visuelle du site a fourni l'occasion d'unifier : un gabarit commun, `wrapEmailContent()`, qui impose l'identité visuelle (fond anthracite, logo, wordmark, bouton vert) autour d'un contenu propre à chaque message.

Un point d'architecture intéressant ici, hérité d'un échec : **le gabarit ne fait aucun appel au routeur**. La première version générait les URLs du site via `path()` — propre en apparence, mais les tests automatisés de la plateforme mockent strictement le `UrlGeneratorInterface`, et deux tests échouaient immédiatement. Plutôt que de relâcher l'assertion des tests, le gabarit utilise une constante de configuration :

```php
private const SITE_URL = 'https://www.geomonkey.fr';
```

Moins élégant ? Pas si sûr : une URL dans un email doit de toute façon être absolue, pas relative à un contexte de requête HTTP qui n'existe pas dans un envoi asynchrone. Ce qui ressemblait à une concession imposée par les tests s'est révélé être la bonne décision d'architecture : un email ne dépend pas d'une requête en cours.

## Le test qui valide tout ça

La suite de tests, elle, ne se moque pas de tout : elle vérifie que chaque email est bien envoyé par les contrôleurs (en environnement de test, le mailer intercepte les envois et expose les messages via le profiler de test). Ce niveau de test a attrapé un vrai bug : un commit déplacé une constante de gabarit, deux tests sur les emails échouaient — et l'erreur est partie avant production, pas après.

Résultat, au moment d'écrire ces lignes : les 9 emails partent, avec le bon gabarit et la bonne adresse expéditeur, et une erreur repérée en production est corrigée par un test ajouté avant le correctif. C'est exactement le fonctionnement attendu d'une petite plateforme qui doit tourner sans surveillance permanente.

## La suite

Le prochain article parlera de sécurité et gouvernance : les Voters Symfony, la hiérarchie fine du bureau (membre actif, bureau, vice-président, président, super-administrateur), et ce qu'un vice-président a le droit de faire — et surtout pas.

Le code est ouvert : [github.com/Bazmati/geomonkey-master](https://github.com/Bazmati/geomonkey-master).
