---
title: Géomonkey — gouvernance d'une asso : Voters Symfony, vice-président, et les droits qu'on ne donne pas
date: 2026-11-24
description: Qui a le droit de supprimer une photo ? Qui peut changer les paramètres du site ? Comment coder une hiérarchie de bureau fine avec les Voters Symfony, et pourquoi la vraie difficulté n'est pas technique mais humaine.
category: Développement web
tags: symfony, geomonkey, securite, voter, rbac, gouvernance
image: assets/gouvernance-geomonkey.jpg
image_alt: Illustration : silhouette de singe gardien devant une porte de coffre à niveaux de serrures multiples
---

## La question qui ne se pose jamais trop tôt

Dans un projet solo, la gestion des droits est un problème abstrait : un seul développeur, un seul super-administrateur, et tout va bien. Puis l'application part en production dans une vraie association — et le président se connecte pour la première fois. La question arrive immédiatement : *et le vice-président, il peut faire quoi exactement ?*

C'est le sujet de cet article : comment coder une hiérarchie de bureau fine avec Symfony, et surtout comment trancher des permissions qui sont d'abord une question... d'association, pas de code.

## La hiérarchie du bureau

Géomonkey distingue plusieurs niveaux d'implication, chacun correspondant à un rôle applicatif :

* **Membre actif** : accès complet au site public et aux contenus réservés aux membres — c'est le statut payant de l'association ;
* **Bureau** : membres élus qui administrent — chacun dans son périmètre ;
* **Vice-président** : full office, avec une frontière explicite ;
* **Président** : le seul à détenir les permissions destructrices ou irréversibles ;
* **Super-administrateur** : l'échelon technique, pour l'exploitation.

La question qui déclenche tout le design : **que fait-on quand une nouvelle fonction apparaît dans le bureau de l'association réelle, et pas dans le code ?** La réponse du monde réel, ici : un vice-président a été élu. Il fallait lui donner un accès cohérent avec son mandat.

## Les Voters : coder le droit, pas le rôle

Symfony fournit plusieurs mécanismes de contrôle d'accès (`access_control` dans la configuration, `is_granted()` dans les templates, attributs PHP dans les contrôleurs). Tous reposent sur la même brique : les **Voters** — des classes qui votent `true` / `false` / `abstention` quand on leur demande « est-ce que *cet utilisateur* peut faire *cette action* sur *cet objet* ? ».

Le principe à retenir, c'est le découplage : un template ne demande pas « suis-je président ? », il demande « ai-je le droit de publier ceci ? ». Le nom de la fonction devient un détail d'implémentation :

```twig
{% if is_granted('ENTITY_DELETE', image) %}
    <button>Supprimer</button>
{% endif %}
```

Changer la règle (demander l'avis du bureau, couvrir un nouveau cas, restreindre en période électorale) ne modifie qu'un seul fichier : le Voter. Tous les templates, les contrôleurs et les tests restent inchangés. C'est ce découplage qui rend la gouvernance pilotable par l'association, et pas par le calendrier des releases.

## Ce que le vice-président ne peut PAS faire

La vraie discussion, ce n'est pas « quelles pages il ouvre », c'est « quelles actions il ne peut pas déclencher ». Le mandat réel d'un vice-président dans une asso se résume bien : **tout le travail du bureau, sauf ce qui engage durablement la structure**. Traduit en permissions :

* ✅ Créer, modifier, organiser — événements, photos, contenus : oui, sans restriction ;
* ❌ Supprimer un contenu ;
* ❌ Publier ou dépublier ;
* ❌ Modifier les paramètres du site.

Le pattern qui rend ça simple dans un Voter : une méthode `canDelete()` ne regarde que la fonction de bureau, à laquelle seul le président passe pour les actions destructrices. Dans le code, ça se lit presque comme un règlement intérieur :

```php
// RÈGLE DE SÉCURITÉ : un membre du bureau sans adhésion active
// est rétrogradé automatiquement (défense en profondeur)
if ($function !== OfficeFunction::None && !$user->hasActiveMembership()) {
    $user->applyMembershipStatus();
    return false;
}

private function canDelete(OfficeFunction $function): bool
{
    // Suppression : président uniquement — l'acte irréversible reste au sommet
    return $function === OfficeFunction::President;
}
```

C'est une décision d'architecture autant que de gouvernance : **les permissions destructrices ne se délèguent pas**. Si le vice-président veut quelque chose de supprimé, il demande au président — en apparence une contrainte, en réalité une protection : un compte compromise ne peut pas détruire le contenu du site.

## Super-administrateur : l'échelon technique

Règle classique : un compte technique qui sert au dépannage doit passer **à travers** toutes les couches d'autorisation — sinon il sert rarement quand on en a besoin. Dans Géomonkey, le `ROLE_SUPER_ADMIN` est un passe-partout de secours (« break-glass »), vérifié une seule fois à l'entrée du vote :

```php
if (\in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
    return true;
}
```

C'est aujourd'hui mon compte de développeur-exploitant ; demain, si l'association grandit, ce sera un rôle de « maintenance », et la gouvernance pourra le restreindre sans toucher aux Voters.

## Une 403 utile, pas un mur

Dernier point, souvent négligé : que voit l'utilisateur qui se heurte au refus du Voter ? Un mur 403 générique n'apprend rien. Géomonkey affiche une page contextuelle : l'utilisateur déconnecté trouve un bouton « Se connecter », le visiteur non-membre comprend qu'il peut rejoindre l'association, et le membre du bureau est renvoyé directement au backoffice. Chaque population a une action suivante claire — un refus d'accès devient une redirection utile.

Techniquement, un listener capte l'`AccessDeniedException` et bascule vers une route dédiée, où le template détermine le contexte : c'est quelques lignes de code pour un gain d'ergonomie important, parce que le refus d'accès le plus courant est « je m'appelle utilisateur non connecté ou non membre » — pas « je n'ai pas le droit ».

## La suite

Le prochain article parlera des paiements Stripe en profondeur : webhooks, idempotence, et comment gérer les erreurs qui n'arrivent que 0,1 % du temps.

Le code est ouvert : [github.com/Bazmati/geomonkey-master](https://github.com/Bazmati/geomonkey-master).
