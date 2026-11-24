---
title: Géomonkey en production — quand le vrai déploiement commence après le déploiement
date: 2026-11-24
description: La suite promise arrive en retard, et pour de bonnes raisons : la plateforme est passée en production. Rituel de déploiement, conteneur LXC, et les bugs qu'on ne rencontre qu'en conditions réelles.
category: Développement web
tags: symfony, geomonkey, proxmox, lxc, deploiement, prod
image: assets/prod-geomonkey-lxc.jpg
image_alt: Illustration : silhouette de singe devant un terminal de déploiement dans une salle sombre, lumières jaunes et vertes
---

## D'abord, une dette : la série tarde — et voici pourquoi

Le premier article de cette série annonçait sept suites. Elles se sont fait attendre, et il y a une raison simple : entre-temps, **Géomonkey est passée en production**. Fin novembre comme prévu — mais une mise en prod réelle, ça ne se limite pas au déploiement : des emails transactionnels à faire partir pour de vrai, des rôles de bureau à arbitrer avec de vrais utilisateurs, des bugs qui ne se voient qu'en conditions réelles.

Chaque semaine de « silence » sur ce blog a été une semaine de robustesse sur le déploiement. Je ne vais pas m'en excuser longtemps : c'est exactement le sujet de cet article, et j'ai maintenant beaucoup plus de choses concrètes à raconter que si j'avais écrit à froid.

## Le décor : pas de Docker, un LXC sur Proxmox

Rappel pour ceux qui n'ont pas lu le premier billet : le site tourne sur mon serveur **Proxmox**, dans un conteneur **LXC** Debian — pas de Docker. L'isolation est déjà assurée au niveau de l'hyperviseur, et le conteneur garde l'application proche de son système : simple à administrer, léger en ressources, et on peut ouvrir un shell dessus depuis l'hyperviseur en une commande :

```bash
pct exec 202 -- bash
```

C'est devenu mon poste de pilotage. Le code vit sur GitHub, la copie de travail de production est dans `/var/www/geomonkey`, et les secrets (`.env`, `.env.local`) restent hors du dépôt — jamais un `git pull` ne devrait écraser une configuration.

## Le rituel de déploiement

Déployer une application seule, sans équipe, sans revue de code, impose une discipline. La mienne tient en quatre commandes, toujours les mêmes, toujours dans le même ordre :

```bash
# 1. En local : appliquer le correctif, tester
git apply mon-correctif.patch
vendor/bin/phpunit

# 2. Si les tests sont verts : versionner
git add . && git commit -m "..." && git push

# 3. En production : tirer et vider le cache
pct exec 202 -- bash -c "cd /var/www/geomonkey && git pull && php bin/console cache:clear --env=prod"
```

Ce rituel est né d'un principe : **la suite de tests est le seul collègue qui relit chaque commit**. La barre est simple — la suite doit rester au vert, avec uniquement les non-conclusifs déjà connus. Si un correctif casse un test, il ne part pas en production. Pas de compromis là-dessus, pas d'« on rattrapera plus tard ».

Un mot sur l'étape 1, parce qu'elle fait lever les sourcils : pourquoi des `.patch` plutôt que des commits directs ? Parce que les correctifs sont écrits, relus et testés dans une copie de travail propre avant d'effleurer le code réel. Un `git apply` qui échoue n'a cassé rien du tout ; un fichier modifié à la main, si. C'est une sécurité de plus, et elle a déjà évité des accidents.

## Ce que la prod révèle que le dev ne montre pas

Trois exemples vécus ces dernières semaines, tous invisibles en développement.

**Un filtre Twig qui n'existait pas.** Le message d'accueil du backoffice affichait la date du jour avec un motif de format ICU passé à un formateur PHP : le résultat ressemblait à « Eeee 18 novnovnovnov 2026 ». Le correctif paraissait anodin : utiliser le filtre `format_datetime` de Twig. Tests verts, commit, push, déploiement — et retour du président : *erreur 500 sur le dashboard*. La cause : le filtre provient de `twig/intl-extra`, présent dans `composer.lock` comme **suggestion** de twig/extra-bundle, mais jamais installé. En développement, la ligne fautive n'était jamais passée par un rendu réel de la page. Résolution : un petit filtre maison `date_fr`, une trentaine de lignes avec les tables jours/mois en français, **zéro nouvelle dépendance**. C'est la prod qui a tranché le design — pas moi.

**Des emails qui partent — ou pas.** En dev, un mail est un dump dans les logs. En prod, c'est un serveur SMTP externe, une authentification, et des pièges que la doc ne met pas en avant : un mot de passe de messagerie encodé dans une URL de DSN (`%` devient `%25`), et un serveur GMX qui exige l'activation explicite de POP3/IMAP — et qui **la retire tout seul** si le compte reste inactif. Ce dernier point ira dans un article dédié, mais il illustre bien le propos : la documentation d'un prestataire ne remplace pas un test réel d'envoi.

**Des rôles à arbitrer, pas seulement à coder.** Le bureau d'une association a une hiérarchie fine : un vice-président doit tout pouvoir administrer — sauf supprimer des contenus ou changer les paramètres, qui restent l'apanage du président. Ces règles existent dans les Voters Symfony ; mais c'est la première connexion réelle du président qui a validé qu'elles avaient du sens pour quelqu'un d'autre que leur auteur.

## Ce que ça change, concrètement

Le dénominateur commun de ces trois histoires : **aucune suite de tests ne remplace l'exécution réelle**. Les tests vérifient ce qu'on a pensé à vérifier ; la production, elle, vérifie tout le reste :

* ce qui n'est rendu nulle part en dev peut craser en prod (le filtre manquant) ;
* ce qui est mocké en test doit exister pour de vrai (le serveur SMTP) ;
* ce qui est cohérent pour le développeur doit rester compréhensible pour l'utilisateur (la gouvernance).

D'où un ajustement de méthode : après chaque déploiement, un tour humain des pages critiques — se connecter avec un compte bureau, parcourir le parcours d'adhésion, cliquer partout où les tests ne cliquent pas. Cinq minutes qui valent tous les dashboards.

## Et maintenant ?

Le rythme de la série va suivre le fil de ce qui se passe réellement. Prochains billets, dans l'ordre où ils sont prêts :

1. **Envoyer des emails en 2026** : DSN, encodage de mot de passe, GMX et le gabarit commun des mails transactionnels ;
2. **Sécurité et gouvernance** : Voters Symfony, hiérarchie du bureau, ce que le vice-président ne peut pas faire ;
3. **Paiements Stripe en profondeur** : webhooks, idempotence, erreurs ;
4. **Médias et performance** : upload, trois tailles, conversion WebP ;
5. **Tests et qualité** : ce que couvrent — et ne couvrent pas — 433 tests.

Le code reste ouvert : [github.com/Bazmati/geomonkey-master](https://github.com/Bazmati/geomonkey-master), et l'avancement se suit sur [www.geomonkey.fr](https://www.geomonkey.fr).
