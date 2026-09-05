# Documentation MSSub

| Document | Pour qui |
| --- | --- |
| [Guide de l'utilisateur](utilisateur.md) | Ceux qui tiennent le plan d'adressage au quotidien : consulter, attribuer, importer, exporter. |
| [Guide de l'administrateur](administration.md) | Comptes et rôles, annuaire LDAP, SSO, référentiel, journal d'audit, sauvegarde. |
| [Installation sur Debian 13](installation-debian-13.md) | Mise en production sur une Debian 13 fraîche, sans conteneur. |
| [Guide du développeur](developpeur.md) | Environnement de développement, décisions structurantes, conventions, pièges connus. |

## En un paragraphe

MSSub tient le plan d'adressage réseau de MSSEC : organisations, sites, blocs et
sous-réseaux, adresses, VLAN et équipements. Il calcule le prochain réseau et la
prochaine adresse libres, refuse les chevauchements, journalise toute écriture,
et sait charger un plan existant depuis un tableur comme alimenter DNS et DHCP.

L'authentification accepte les comptes locaux, un annuaire LDAP ou Active
Directory, et un fournisseur d'identité OIDC. SAML n'est pas implémenté.
