# Guide de l'utilisateur

MSSub tient le plan d'adressage réseau de MSSEC : quels réseaux existent, à qui
ils servent, quelles adresses y sont prises, et laquelle attribuer ensuite.

## Les notions

| Notion | Ce que c'est |
| --- | --- |
| **Organisation** | Le plus haut niveau. Chacune porte son propre plan : deux organisations peuvent déclarer `10.0.0.0/8` chacune de leur côté sans se gêner. |
| **Site** | Un lieu — siège, agence, datacentre — rattaché à une organisation. |
| **Bloc** | Un réseau marqué « conteneur » : il accueille des sous-réseaux, jamais des adresses. Typiquement `10.0.0.0/8` ou `10.10.0.0/16`. |
| **Réseau** | Un sous-réseau terminal, celui où vivent les machines. |
| **Adresse** | Une adresse documentée dans un réseau, avec son état et éventuellement son nom d'hôte. |
| **VLAN** | Un domaine de diffusion, rattachable à un réseau. |
| **Équipement** | Ce qui porte des interfaces, donc des adresses. |

### Une adresse absente est libre

Les adresses libres ne sont **pas** stockées : un `/16` en compterait 65 536 pour
ne rien dire. Une adresse qui ne figure pas dans la liste d'un réseau est libre
par définition. Documenter une adresse, c'est dire qu'elle est prise — ou
réservée, ou distribuée par DHCP.

### Un réseau hérite du site de son bloc

Si un `/24` ne déclare pas de site, il prend celui du bloc qui le contient.
Découper `10.10.0.0/16` « Paris » en `/24` n'oblige donc pas à répéter « Paris »
partout. L'interface écrit **(hérité)** dans ce cas, pour que vous ne preniez
pas un rattachement déduit pour un oubli de saisie.

## Le périmètre de travail

En tête de chaque page, un sélecteur **Organisation / Site**. Il est retenu d'une
page à l'autre et pour toute la session.

Ce qu'il filtre : les plans d'adressage, le tableau de bord, les exports CSV.

Ce qu'il ne filtre pas : **la recherche d'adresse**. On cherche une adresse
justement parce qu'on ignore d'où elle vient ; la masquer au motif qu'elle relève
d'un autre site irait contre l'usage. La recherche reste toutefois limitée à
l'organisation choisie.

« Tout voir » revient au référentiel entier.

## Plans d'adressage

L'arborescence montre la hiérarchie réelle des réseaux, groupée par site. Un
groupe **Sans site** rassemble ce qui n'est rattaché nulle part — souvent les
grands blocs racines, ce qui est normal, mais aussi ce qu'on a oublié de
rattacher.

- Le chevron plie et déplie un bloc. L'état est mémorisé par votre navigateur.
- La colonne **Occupation** donne les adresses documentées sur les adresses
  attribuables. Un `/30` de liaison affiche `2/2` quand il est plein.

### Lire l'occupation

Les adresses de réseau et de diffusion ne sont pas comptées comme attribuables :
un `/24` offre 254 adresses, pas 256. Deux exceptions, conformes aux usages :

- un `/31` offre ses **deux** adresses (liaisons point à point, RFC 3021) ;
- un `/32` en offre **une** ;
- en IPv6, aucune adresse n'est réservée à la diffusion : tout le préfixe compte.

Attention au piège classique : dans un `/16`, `10.0.0.255` est une adresse
d'hôte ordinaire. Seule `10.0.255.255` est la diffusion.

## Fiche d'un réseau

Elle donne les caractéristiques, l'occupation, les **prochaines adresses libres**,
les sous-réseaux et les adresses documentées.

- **Réserver 10.10.20.1** attribue la prochaine adresse libre en un clic, avec
  l'état « Réservée ». C'est le geste le plus courant.
- **Ajouter une adresse** ouvre un formulaire pré-rempli avec cette même adresse.
  Une adresse hors du réseau, ou déjà documentée, est refusée.

Sur un **bloc conteneur**, il n'y a pas d'adresses : la fiche propose à la place
les prochains emplacements libres pour un `/24`, `/26`, `/28` et `/30`.

## Créer un réseau

**Plans d'adressage → Nouveau réseau**. Vous saisissez une notation CIDR ; le
reste est déduit :

- `10.10.50.37/26` est ramené à `10.10.50.0/26` — inutile de calculer l'adresse
  de réseau vous-même ;
- le **bloc parent** est trouvé automatiquement ;
- un **doublon** ou un **chevauchement partiel** est refusé, avec le réseau
  fautif nommé.

Si le nouveau réseau englobe des réseaux existants, il s'intercale : ils
deviennent ses enfants. Et supprimer un bloc **ne supprime pas** ce qu'il
contient — les sous-réseaux remontent d'un cran.

Le réseau lui-même n'est pas modifiable après création : le déplacer reviendrait
à renuméroter tout ce qu'il contient. C'est une suppression suivie d'une
création.

## Rechercher une adresse

**Rechercher une IP** accepte IPv4 et IPv6. Le résultat donne toute la chaîne de
blocs contenant l'adresse, du plus large au plus fin :

```
10.20.0.0/16     Conteneur   Bloc siège
10.20.30.0/24    Actif       LAN Postes
```

et, si l'adresse est documentée, sa ligne avec son état et son nom d'hôte.

## Fiche d'un site

Accessible depuis le titre d'un groupe dans l'arborescence. Elle donne
l'occupation agrégée du site, ses réseaux, ses VLAN et ses équipements, et
indique pour chaque réseau si le rattachement est **déclaré** ou **hérité**.

Les blocs conteneurs sont exclus du total : additionner un bloc et les
sous-réseaux qu'il contient compterait deux fois les mêmes adresses.

## Import

**Import** charge un plan existant au format CSV ou XLSX, en deux temps.

1. **Simuler** lit le fichier et décrit ce qui se passerait. Rien n'est écrit.
2. **Importer réellement** n'écrit que si aucune erreur ne subsiste.

Une ligne fautive n'interrompt pas la lecture : tout est analysé et chaque
problème est rendu avec son **numéro de ligne dans votre tableur**.

Erreurs (bloquantes) et avertissements (non bloquants) sont séparés. Un CIDR
illisible bloque ; un statut ou un site inconnu se signale sans condamner le
fichier.

### Colonnes

Pour les réseaux : `cidr`, `nom`, `statut`, `site`, `vlan`, `passerelle`, `dns`,
`dhcp`, `dhcp_debut`, `dhcp_fin`, `description`.

Pour les adresses : `adresse`, `nom_hote`, `statut`, `mac`, `description` — le
réseau d'accueil est déduit de l'adresse.

Les intitulés courants sont reconnus : « Réseau », « Hostname », « IP »,
« Commentaire », « Passerelle »… Inutile de renommer vos colonnes.

### Le plus simple

Exportez d'abord, retouchez, réimportez : l'export utilise exactement les
colonnes que l'import sait relire. Un aller-retour sans modification ne change
rien.

## Export

- **Réseaux (CSV)** et **Adresses (CSV)** — point-virgule et UTF-8 avec BOM,
  Excel les ouvre directement.
- **Zone DNS directe** et **Configuration dhcpd** — voir ci-dessous.

Les exports CSV suivent le périmètre ; les fragments DNS et DHCP portent sur
l'organisation entière.

### DNS et DHCP : des fragments, pas des fichiers

Aucun `SOA`, aucun `NS`, aucune option globale de dhcpd n'est produit. Le numéro
de série et les serveurs faisant autorité appartiennent à votre infrastructure :
les inventer donnerait un fichier d'apparence valide qui **écraserait votre
configuration réelle** à la première inclusion.

Seuls les réseaux portant une plage DHCP complète sont déclarés — un `subnet`
sans `range` est accepté par dhcpd mais ne distribue rien, ce qui est pire qu'une
absence. Les adresses documentées portant une MAC deviennent des réservations.

## Les états

**Réseau** : Conteneur, Actif, Réservé, Obsolète.

**Adresse** : Libre (documentée mais disponible — elle ne bloque pas
l'attribution), Utilisée, Réservée, DHCP, Passerelle, Non applicable.

## Ce que vous pouvez faire selon votre rôle

| | Lecture | Opérateur | Administrateur |
| --- | --- | --- | --- |
| Consulter, rechercher, exporter | oui | oui | oui |
| Créer et modifier réseaux et adresses | non | oui | oui |
| Importer | non | oui | oui |
| Consulter le journal | non | oui | oui |
| Supprimer un réseau | non | non | oui |
| Administration | non | non | oui |
