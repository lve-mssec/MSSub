# **Identité**

# Les couleurs de la marque.

| Jeton | Hexa | Employé pour | Jamais pour |
| --- | --- | --- | --- |
| --orange-bright | #F69235 | le dégradé, l'élément actif de la navigation sombre | un aplat sur fond clair — contraste insuffisant sur blanc |
| --orange | #EA7E30 | titres de section, identifiants de règle, accents sur surface claire | voir le point ouvert en fin de document |
| --red | #EE3628 | le dégradé, le liseré de l'élément actif | un aplat de texte — se confond avec la gravité critique |
| --wash | #FBEEE4 | fond des encadrés d'information, fond de la pastille d'identifiant de règle | un fond de verdict — les verdicts ont leurs propres fonds |
| --grad | linear-gradient
(135deg,#F69235,#EE3628) | le logo, l'état actif, un liseré d'accent | une grande surface — il devient sale au-delà de 200 px |

## Surface sombre

**La barre latérale et elle seule**. Aucun de ces tons ne descend dans le contenu.

| Jeton | Hexa | Employé pour | Jamais pour |
| --- | --- | --- | --- |
| --ink-900 | #0D0C0C | le fond de la barre latérale | le noir pur
#000000 est interdit : l'orange y vibre, et un PNG à fond non transparent y laisse voir son carré |
| --ink-800 | #1A1818 | le survol d'un élément de navigation | - |
| --ink-700 | #262323 | l'élément de navigation actif | un fond de contenu |
| --ink-600 | #373433 | les séparateurs à l'intérieur de la barre | la jonction barre/contenu — elle se fait par le contraste, **sans filet** |
| --ink-text | #E8E4E2 | le texte sur fond sombre | du texte sur fond clair |
| --ink-muted | #8A8482 | le texte atténué sur fond sombre | — seul jeton partagé avec la surface claire, où il vaut **--text-3** |

## Surface claire

Toute la zone de contenu. **C'est elle, et elle seule, qui s'imprime**.

| Jeton | Hexa | Employé pour | Jamais pour |
| --- | --- | --- | --- |
| --paper | #F7F5F4	 | le fond de la zone de contenu, et les lignes paires des tableaux | le fond d'une carte — une carte doit se détacher du papier |
| --card | #FFFFFF | cartes, sections, blocs de contrôle | le fond général — le blanc pur en pleine page fatigue |
| --line | #EDE8E5 | filets de structure : bordures de section, trait sous un titre | une bordure de pastille — celles-ci portent la bordure de leur famille |
| --hairline | #F0ECEA | filets internes aux tableaux, entre deux lignes | une bordure de structure — trop faible, elle disparaît |
| --surface2 | #F3EEEC | en-têtes de tableau, code en ligne, fond de l'état « non applicable » | un fond de carte |
| --text | #302E2E | le texte principal, les titres de contrôle | du texte sur fond sombre |
| --text-2 | #5B5959 | raisons, explications, texte secondaire | un libellé de colonne — trop appuyé |
| --text-3 | #8A8482 | libellés, légendes, valeurs atténuées | le corps de texte |

