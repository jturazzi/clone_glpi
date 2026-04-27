# Clone Ticket — Plugin GLPI

Clonez un ticket GLPI existant vers une autre entité en un clic.

## Fonctionnalités

- Ajoute un bouton **« Cloner vers une autre entité »** sur chaque formulaire de ticket
- Ouvre une fenêtre modale avec un menu déroulant d'entités (Select2, recherche intégrée)
- Clone le ticket (y compris les éléments liés : ordinateurs, téléphones, etc.) vers l'entité sélectionnée
- Affiche un lien direct vers le nouveau ticket créé après le clonage

## Prérequis

| Prérequis | Version |
|-----------|---------|
| GLPI      | ≥ 11.0, < 12.0 |
| PHP       | Selon les exigences de GLPI 11 |

Aucune extension PHP supplémentaire ni table en base de données n'est nécessaire.

## Installation

1. Téléchargez ou clonez ce dépôt dans `<RACINE_GLPI>/plugins/clone/`.
2. Rendez-vous dans **Configuration > Plugins** dans GLPI.
3. Repérez **Clone Ticket** puis cliquez sur **Installer**, puis **Activer**.

```
cd /var/www/glpi/plugins
git clone https://github.com/jturazzi/clone_glpi clone
```

## Utilisation

1. Ouvrez un ticket existant.
2. Cliquez sur le bouton **« Cloner vers une autre entité »** (visible par les superviseurs et super-administrateurs).
3. Sélectionnez l'entité de destination dans le menu déroulant.
4. Cliquez sur **Cloner** — le plugin crée le nouveau ticket et fournit un lien pour y accéder.

## Permissions

Le bouton de clonage n'est affiché qu'aux utilisateurs disposant d'au moins l'un des droits suivants :

- **Ticket → Attribuer** (superviseurs)
- **Configuration → Mettre à jour** (super-administrateurs)

## Arborescence

```
clone/
├── hook.php                        # Hooks d'installation/désinstallation & POST_ITEM_FORM
├── setup.php                       # Enregistrement du plugin (version, hooks, assets)
├── ajax/
│   ├── clone_ticket.php            # Point d'entrée AJAX – effectue le clonage
│   └── get_entity_dropdown.php     # Point d'entrée AJAX – retourne le <select> d'entités
├── locales/
│   ├── en_GB.po                    # Traductions anglaises
│   └── fr_FR.po                    # Traductions françaises
└── public/
    ├── css/
    │   └── clone.css               # Styles du bouton et de la modale
    └── js/
        └── clone.js                # Logique côté client (modale, fetch, Select2)
```

## Traductions

Le plugin est livré avec les locales anglaise (`en_GB`) et française (`fr_FR`). Pour ajouter une nouvelle langue, créez le fichier `.po` correspondant dans `locales/` et compilez-le en `.mo` avec `msgfmt` :

```bash
msgfmt locales/fr_FR.po -o locales/fr_FR.mo
```

## Licence

Ce plugin est distribué sous la [Licence Publique Générale GNU v3.0](https://www.gnu.org/licenses/gpl-3.0.html).

## Auteur

**Jérémy TURAZZI**
