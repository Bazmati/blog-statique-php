---
title: Quatre Debian à maintenir à jour sans y passer ses soirées — ma méthode sur Proxmox
date: 2026-11-10
description: Mon serveur héberge quatre conteneurs LXC Debian : reverse proxy, blog statique, application Symfony et PostgreSQL. Voici la méthode en trois étages que j'utilise pour les garder à jour et sauvegardés sans surveillance quotidienne.
category: Administration système
tags: proxmox, lxc, debian, sysadmin, securite, sauvegarde
---

Mon serveur Proxmox fait tourner quatre conteneurs Debian : un reverse proxy nginx, le conteneur de ce blog, une application Symfony en cours de mise en production, et sa base PostgreSQL. Quatre systèmes d'exploitation à tenir à jour, ça peut vite devenir une corvée — ou pire, un truc qu'on oublie jusqu'au jour où ça explose.

Voici la méthode en trois étages que j'ai mise en place. Elle tient en une heure de configuration initiale, puis quelques minutes par mois.

## Étage 1 : les correctifs de sécurité s'appliquent tout seuls

La règle est simple : **ce qui est critique doit être automatique**, parce que tu ne peux pas compter sur ta future motivation pour y penser chaque semaine.

Dans chaque conteneur, et sur l'hôte :

```
apt install -y unattended-upgrades
dpkg-reconfigure -plow unattended-upgrades
```

C'est tout. Debian applique désormais seul, chaque nuit, les correctifs de sécurité. C'est la machinerie standard de la distribution — pas un script maison, pas de dépendance externe, et ça marche aussi bien dans un LXC que sur du bare metal.

La nuance importante : `unattended-upgrades` ne fait **pas** les montées de version majeures. Il ne passera jamais PHP 8.4 en 8.5, ni PostgreSQL 15 en 16. Et c'est voulu : les upgrades majeurs peuvent casser une application, on ne les fait pas à l'aveugle à 4 h du matin. Elles ont leur place à l'étage 3.

## Étage 2 : un rapport mensuel, pas des connexions quotidiennes

L'avantage méconnu de LXC, c'est `pct exec` : exécuter une commande dans un conteneur **sans y entrer**, sans SSH, sans mot de passe. J'en profite pour un script posé sur l'hôte, `/root/check-updates.sh` :

```bash
#!/bin/bash
# Rapport de mises à jour disponibles sur tous les conteneurs + l'hôte
for ct in 200 201 202 203; do
  name=$(pct list | awk -v id=$ct '$1==id {print $3}')
  echo "=== CT $ct ($name) ==="
  pct exec $ct -- bash -c "apt update -qq 2>/dev/null && apt list --upgradable 2>/dev/null | grep -v Listing | head -20"
done
echo "=== Hôte pve ==="
apt update -qq 2>/dev/null && apt list --upgradable 2>/dev/null | grep -v Listing
```

Lancé à la main, il donne en dix secondes l'état des quatre Debian. Mais son vrai rôle est d'être planifié — une ligne dans le cron de l'hôte :

```
0 8 1 * * /root/check-updates.sh > /var/log/check-updates-$(date +\%Y\%m).log 2>&1
```

Chaque 1er du mois, un fichier `check-updates-202612.log` se pose sur l'hôte. Je l'ouvre quand j'y pense, je vois ce qui attend.

Notez le choix qui se cache là-dedans : le cron fait le **rapport**, pas l'upgrade. Automatiser `apt full-upgrade` complet, c'est risquer de casser une application un dimanche matin sans personne pour le voir. Un rapport, lui, ne casse rien.

## Étage 3 : les upgrades, à la main, au bon moment

Une fois par mois (ou au fil de l'eau), je lis le rapport et je monte les versions quand c'est opportun :

```
apt update && apt full-upgrade -y && apt autoremove -y
```

« Opportun » veut dire : pas la veille d'un événement, pas pendant que je développe une fonctionnalité sur l'app, et avec un œil sur les services après coup :

```
systemctl restart nginx php8.4-fpm postgresql
curl -I http://127.0.0.1
```

C'est le seul étage qui demande du jugement — c'est exactement pour ça qu'il n'est pas automatisé.

## L'étage qui ne pardonne pas : les sauvegardes

Les mises à jour protègent des failles. Les sauvegardes protègent de tout le reste — moi y compris. Proxmox a ça natif : **Datacenter → Backup → Add**, mode `snapshot` (les conteneurs continuent de tourner), hebdomadaire, `keep-last = 4`.

Le détail qui distingue une sauvegarde d'un fichier `vzdump` qui traîne : **l'avoir déjà restaurée au moins une fois**. Une sauvegarde jamais testée est un espoir, pas une sauvegarde.

```
vzdump --list
pct restore 900 /var/lib/vz/dump/vzdump-lxc-202-....tar.zst --storage local-lvm
pct start 900 && pct enter 900   # vérifier, puis détruire le CT 900
```

## Les pièges que j'ai rencontrés en route

Trois prises de tête qui méritent d'être écrites quelque part :

**fail2ban refuse de démarrer dans un conteneur sans SSH.** Debian active la jail `sshd` par défaut ; sans serveur SSH, pas de log à surveiller, et le service plante avec un `Have not found any log file for sshd jail` peu bavard. Dans mes conteneurs applicatifs, la première section de `/etc/fail2ban/jail.local` est toujours :

```
[sshd]
enabled = false
```

**Les dépôts Proxmox enterprise renvoient 401.** Sans abonnement, `apt update` affiche une erreur 401 dès l'installation. Les fichiers `/etc/apt/sources.list.d/*.sources` se désactivent proprement avec une ligne `Enabled: no` — inutile de les supprimer.

**Un bloc `server` dans nginx ne va pas n'importe où.** J'ai un jour collé un `server { ... }` après la fermeture du bloc `http` : `nginx -t` refuse, et c'est très bien ainsi.

## Ce que ça donne au total

| Quoi                | Comment                            | Fréquence                |
| ------------------- | ---------------------------------- | ------------------------ |
| Correctifs sécurité | `unattended-upgrades` partout    | chaque nuit, automatique |
| Contrôle global     | script `pct exec` + cron         | mensuel, rapport posé    |
| Upgrades majeures   | `apt full-upgrade` manuel        | quand je décide          |
| Sauvegardes         | vzdump programmé                   | hebdomadaire             |
| Restauration        | `pct restore` vers un CT jetable | testée une fois          |

La maintenance de mon serveur, c'est maintenant un fichier de rapport par mois à ouvrir, et cinq minutes d'upgrade quand il y en a besoin. Le reste tourne sans moi.

Après douze ans à faire de la maintenance industrielle, je retrouve la même règle que pour les machines : ce qui doit se faire régulièrement doit être rendu automatique, et ce qui demande du jugement doit rester aux mains de quelqu'un qui comprend le système. Un serveur n'est pas différent d'une ligne de production — c'est juste plus rapide à redémarrer.

*La procédure complète est dans mon dépôt admin-procedures. Si vous hébergez sur LXC, dites-moi comment vous gérez vos mises à jour — le flux RSS est là pour ça.*
