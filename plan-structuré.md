# Plan structuré — Pages WordPress & Shortcodes PédagoLens

URL de base : `http://pedagolens.34.199.149.247.nip.io`

---

## Pages à créer dans WordPress

### 1. Landing Page

| Champ       | Valeur                                                    |
|-------------|-----------------------------------------------------------|
| Titre       | Accueil                                                   |
| Slug        | `/`  (définir comme page d'accueil dans Réglages > Lecture) |
| URL         | `http://pedagolens.34.199.149.247.nip.io/`                |
| Shortcode   | `[pedagolens_landing]`                                    |
| Description | Hero + badges profils + grille des 4 fonctionnalités      |

---

### 2. Dashboard Enseignant

| Champ       | Valeur                                                              |
|-------------|---------------------------------------------------------------------|
| Titre       | Dashboard Enseignant                                                |
| Slug        | `/dashboard-enseignant`                                             |
| URL         | `http://pedagolens.34.199.149.247.nip.io/dashboard-enseignant`      |
| Shortcode   | `[pedagolens_teacher_dashboard]`                                    |
| Description | Liste des cours avec boutons Analyser et Projets. Réservé aux rôles `pedagolens_teacher` et `administrator`. |

---

### 3. Dashboard Étudiant

| Champ       | Valeur                                                              |
|-------------|---------------------------------------------------------------------|
| Titre       | Dashboard Étudiant                                                  |
| Slug        | `/dashboard-etudiant`                                               |
| URL         | `http://pedagolens.34.199.149.247.nip.io/dashboard-etudiant`        |
| Shortcode   | `[pedagolens_student_dashboard]`                                    |
| Description | Interface de conversation avec le jumeau numérique IA (Léa). Utiliser `[pedagolens_student_dashboard course_id="ID"]` pour fixer un cours. |

---

### 4. Cours & Projets

| Champ       | Valeur                                                              |
|-------------|---------------------------------------------------------------------|
| Titre       | Cours & Projets                                                     |
| Slug        | `/cours-projets`                                                    |
| URL         | `http://pedagolens.34.199.149.247.nip.io/cours-projets`             |
| Shortcode   | `[pedagolens_courses]`                                              |
| Description | Liste de tous les cours avec leurs projets. Cliquer sur un cours affiche ses projets avec un lien vers le Workbench. |

---

### 5. Workbench

| Champ       | Valeur                                                              |
|-------------|---------------------------------------------------------------------|
| Titre       | Workbench                                                           |
| Slug        | `/workbench`                                                        |
| URL         | `http://pedagolens.34.199.149.247.nip.io/workbench?project_id=ID`   |
| Shortcode   | `[pedagolens_workbench]`                                            |
| Description | Aperçu du projet sélectionné (scores par profil, sections). Bouton pour ouvrir l'atelier complet dans l'admin WP. L'URL reçoit `?project_id=ID` automatiquement depuis la page Cours & Projets. |

---

### 6. Compte

| Champ       | Valeur                                                              |
|-------------|---------------------------------------------------------------------|
| Titre       | Mon Compte                                                          |
| Slug        | `/compte`                                                           |
| URL         | `http://pedagolens.34.199.149.247.nip.io/compte`                    |
| Shortcode   | `[pedagolens_account]`                                              |
| Description | Affiche le nom, email, rôle et liens rapides selon le rôle (enseignant ou étudiant). Affiche un formulaire de connexion si non connecté. |

---

## Résumé

| # | Page               | Slug                    | Shortcode                            |
|---|--------------------|-------------------------|--------------------------------------|
| 1 | Landing            | `/`                     | `[pedagolens_landing]`               |
| 2 | Dashboard Enseignant | `/dashboard-enseignant` | `[pedagolens_teacher_dashboard]`   |
| 3 | Dashboard Étudiant | `/dashboard-etudiant`   | `[pedagolens_student_dashboard]`     |
| 4 | Cours & Projets    | `/cours-projets`        | `[pedagolens_courses]`               |
| 5 | Workbench          | `/workbench`            | `[pedagolens_workbench]`             |
| 6 | Mon Compte         | `/compte`               | `[pedagolens_account]`               |

---

## Notes

- Un seul shortcode par page
- Le Workbench reçoit `?project_id=ID` via l'URL — le lien est généré automatiquement depuis la page Cours & Projets
- Le Dashboard Enseignant et Cours & Projets redirigent vers l'admin WP pour les actions avancées (analyse IA, édition de sections)
- La page Compte affiche un formulaire de connexion si l'utilisateur n'est pas connecté
- Les rôles WordPress utilisés : `administrator`, `pedagolens_teacher`, `pedagolens_student`
