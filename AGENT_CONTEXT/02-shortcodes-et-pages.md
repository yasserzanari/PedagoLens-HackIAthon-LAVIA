# Shortcodes et Pages

Source de verite: `plan-structuré.md`

## Shortcodes principaux
- `[pedagolens_landing]`
- `[pedagolens_login]`
- `[pedagolens_teacher_dashboard]`
- `[pedagolens_student_dashboard]`
- `[pedagolens_courses]`
- `[pedagolens_workbench]`
- `[pedagolens_account]`
- `[pedagolens_history]` (alias `[pedagolens_historique]`)
- `[pedagolens_settings]` (alias `[pedagolens_parametres]`)
- `[pedagolens_institutional]` (alias `[pedagolens_institutionnel]`)
- `[pedagolens_jumeau_ia]`

## Rappels importants
- un seul shortcode par page
- page workbench attend `?project_id=ID`
- routes et slugs attendus: voir `plan-structuré.md`
- login redirige par role: enseignant/admin -> dashboard enseignant, etudiant -> dashboard etudiant
