# Plan d'implémentation : PédagoLens Platform

## Vue d'ensemble

Implémentation des 6 plugins PHP WordPress constituant la plateforme PédagoLens. L'ordre d'exécution suit les dépendances strictes :

1. `pedagolens-core` — noyau partagé
2. `pedagolens-api-bridge` — couche IA avec mode mock obligatoire
3. `pedagolens-landing` — landing page marketing
4. `pedagolens-teacher-dashboard` — tableau de bord enseignant
5. `pedagolens-course-workbench` — atelier de cours
6. `pedagolens-student-twin` — jumeau numérique étudiant

**Profils pédagogiques** — gérés dynamiquement par `PedagoLens_Profile_Manager` (option WP `pl_profile_{slug}`, index `pl_profile_index`). Les 7 profils par défaut sont seedés à l'activation si l'index est vide :
- `concentration_tdah`, `surcharge_cognitive`, `langue_seconde`, `faible_autonomie`, `anxieux_consignes`, `avance_rapide`, `usage_passif_ia`

**Convention sécurité AWS** : les credentials AWS ne sont JAMAIS stockés dans les options WP. Lecture via `defined('AWS_ACCESS_KEY_ID') ? AWS_ACCESS_KEY_ID : getenv('AWS_ACCESS_KEY_ID')`.

**Mode mock** : `pl_ai_mode = mock` retourne des réponses de démonstration crédibles sans appel AWS.

## Tâches

- [ ] 1. Mettre en place la structure de base et les outils de test
  - Créer les répertoires `plugins/pedagolens-{core,api-bridge,landing,teacher-dashboard,course-workbench,student-twin}/` avec la structure `includes/`, `admin/`, `assets/`, `tests/`
  - Créer un `composer.json` racine avec PHPUnit et Eris (PBT) comme dépendances de dev
  - Créer un `phpunit.xml` racine pointant vers les dossiers `tests/` de chaque plugin
  - _Requirements: 1.1_


- [ ] 2. Implémenter `pedagolens-core`
  - [ ] 2.1 Créer le fichier principal du plugin et la classe `PedagoLens_Core`
    - Header WordPress (`Plugin Name`, `Version`, `Requires at least`, etc.)
    - Constantes globales : `PEDAGOLENS_VERSION`, `PEDAGOLENS_PLUGIN_DIR`, `PEDAGOLENS_PLUGIN_URL`
    - Méthodes statiques : `get_option`, `update_option`, `log`
    - Hook `init` pour l'enregistrement des CPT et des rôles
    - _Requirements: 1.1, 1.4, 1.5_

  - [ ] 2.2 Enregistrer les CPT avec toutes leurs métadonnées
    - CPT `pl_analysis` avec meta : `_pl_course_id`, `_pl_profile_scores`, `_pl_recommendations`, `_pl_raw_response`, `_pl_analyzed_at`, `_pl_summary`, `_pl_impact_estimates`
    - CPT `pl_course` avec meta : `_pl_sections`, `_pl_versions`, `_pl_last_workbench_at`, `_pl_course_type` (valeurs : `magistral` | `exercice` | `evaluation` | `travail_equipe`) (post_title et post_content natifs WP)
    - CPT `pl_interaction` avec meta : `_pl_student_id`, `_pl_course_id`, `_pl_session_id`, `_pl_messages`, `_pl_started_at`, `_pl_ended_at`, `_pl_guardrails_applied`
    - CPT `pl_project` avec meta : `_pl_course_id`, `_pl_project_type` (valeurs : `magistral` | `exercice` | `evaluation` | `travail_equipe`), `_pl_content_sections`, `_pl_profile_scores`, `_pl_recommendations`, `_pl_impact_estimates`, `_pl_versions`, `_pl_created_at`, `_pl_updated_at`
    - _Requirements: 1.1_

  - [ ] 2.3 Enregistrer les rôles et déclarer les hooks inter-plugins
    - Rôle `pedagolens_teacher` avec capacités : `edit_pl_courses`, `publish_pl_courses`, `read_pl_analyses`, `manage_pl_workbench`
    - Rôle `pedagolens_student` sans aucune capacité d'édition de cours
    - Méthode `get_user_role(int $user_id): string`
    - Déclarer les 6 hooks : `pedagolens_before_analysis`, `pedagolens_after_analysis`, `pedagolens_before_ai_invoke`, `pedagolens_after_ai_invoke`, `pedagolens_guardrail_triggered`, `pedagolens_workbench_suggestion_applied`
    - _Requirements: 1.2, 1.3_

  - [ ]* 2.4 Écrire les tests unitaires pour `pedagolens-core`
    - Tester l'activation : CPT enregistrés, rôles créés, hooks déclarés
    - Tester `get_option` sur clé absente retourne le défaut
    - Tester que les 6 hooks `pedagolens_*` sont déclenchables
    - _Requirements: 1.2, 1.3, 1.4, 1.5_

  - [ ]* 2.5 Écrire le test de propriété P1 — Options round-trip (core)
    - **Property 1 : Options round-trip**
    - Pour toute clé et valeur valide, `update_option` puis `get_option` retourne la valeur écrite
    - `// Feature: pedagolens-platform, Property 1: Options round-trip (core)`
    - **Validates: Requirements 1.5**

  - [ ]* 2.6 Écrire le test de propriété P2 — Valeur par défaut sur clé absente (core)
    - **Property 2 : Valeur par défaut sur clé absente**
    - Pour toute clé inexistante et défaut arbitraire, `get_option` retourne exactement le défaut
    - `// Feature: pedagolens-platform, Property 2: Valeur par défaut sur clé absente (core)`
    - **Validates: Requirements 1.4**

  - [ ]* 2.7 Écrire le test de propriété P3 — Capacités du rôle teacher (core)
    - **Property 3 : Capacités du rôle teacher**
    - Pour tout utilisateur assigné au rôle `pedagolens_teacher`, il possède toutes les capacités de gestion de cours
    - `// Feature: pedagolens-platform, Property 3: Capacités du rôle teacher (core)`
    - **Validates: Requirements 1.2**

  - [ ]* 2.8 Écrire le test de propriété P4 — Isolation du rôle student (core)
    - **Property 4 : Isolation du rôle student**
    - Pour tout utilisateur assigné au rôle `pedagolens_student`, il ne possède aucune capacité d'édition de cours
    - `// Feature: pedagolens-platform, Property 4: Isolation du rôle student (core)`
    - **Validates: Requirements 1.3**

  - [ ] 2.9 Implémenter `PedagoLens_Profile_Manager` dans `pedagolens-core`
    - Créer `includes/class-profile-manager.php`
    - Méthodes statiques : `get_all(bool $active_only = true): array`, `get(string $slug): array|null`, `save(array $profile_data): bool`, `delete(string $slug): bool` (soft delete), `duplicate(string $slug, string $new_slug): bool`, `get_index(): array`, `reorder(array $slugs): bool`
    - Validation des slugs : lowercase, tirets uniquement, unique dans l'index
    - Sanitize : `sanitize_textarea_field` sur `system_prompt` et `resources`, `sanitize_text_field` sur `slug`
    - Bloquer `delete` si le slug est référencé dans des CPT `pl_analysis` existants
    - _Requirements: 1.7_

  - [ ] 2.10 Seeder les 7 profils par défaut à l'activation
    - Dans le hook d'activation de `pedagolens-core`, si `pl_profile_index` est vide, créer les 7 profils par défaut avec `system_prompt` et `resources` vides
    - Slugs : `concentration_tdah`, `surcharge_cognitive`, `langue_seconde`, `faible_autonomie`, `anxieux_consignes`, `avance_rapide`, `usage_passif_ia`
    - _Requirements: 1.7_

  - [ ] 2.11 Enregistrer les menus admin pour les profils
    - Ajouter "Profils d'apprenants" sous "PédagoLens" avec sous-entrées "Tous les profils" et "Ajouter un profil"
    - Protéger toutes les pages avec `current_user_can('manage_options')`
    - _Requirements: 7.1_

  - [ ] 2.12 Créer la page liste des profils (`admin/profiles-list.php`)
    - `WP_List_Table` avec colonnes : Nom, Slug, Statut, Ordre, System prompt (extrait 80 chars), Actions
    - Actions par ligne : Modifier, Dupliquer, Activer/Désactiver (AJAX), Supprimer (confirmation JS)
    - Boutons en haut : "+ Ajouter un profil", "Importer un profil JSON", "Exporter tous les profils"
    - Drag-and-drop jQuery UI Sortable + AJAX `pl_reorder_profiles`
    - Nonce sur toutes les actions AJAX, `current_user_can('manage_options')`
    - _Requirements: 7.2_

  - [ ] 2.13 Créer la page édition d'un profil (`admin/profile-edit.php`)
    - Section A — Identité : nom, slug (auto-généré), description (max 200 chars), actif, ordre
    - Section B — Prompt système IA : textarea (min-height 400px, monospace), checkboxes `inject_resources` et `inject_scoring`
    - Section C — Ressources scientifiques : textarea (min-height 300px, Markdown)
    - Section D — Grille de scoring : tableau 5 lignes (min/max/label/couleur), validation JS (pas de chevauchement, couverture 0-100)
    - Section E — Prévisualisation : bouton "Prévisualiser le prompt complet" → modale avec tokens estimés
    - Boutons : Enregistrer, Enregistrer et continuer, Annuler
    - Nonce + `current_user_can('manage_options')` sur la sauvegarde
    - _Requirements: 7.3_

  - [ ] 2.14 Refactoriser `admin/settings-page.php` en 4 onglets
    - Onglet 1 "IA & Bedrock" : mode IA, config Bedrock (région, modèle défaut `anthropic.claude-sonnet-4-20250514-v2:0`, tokens max défaut 1500, température, timeout), bouton "Tester la connexion" (AJAX), format de sortie, mode debug
    - Onglet 2 "Profils d'apprenants" : lien vers gestion, aperçu readonly, boutons Gérer/Exporter/Importer
    - Onglet 3 "Comportement" : profils actifs (checkboxes depuis `Profile_Manager::get_all()`), ordre drag-and-drop, toggles UI, nombre max de propositions (défaut 5)
    - Onglet 4 "Avancé" : réinitialiser profils (danger + confirmation), vider logs, export/import config JSON, version plugin (readonly)
    - Nonce + `current_user_can('manage_options')` sur chaque onglet
    - _Requirements: 7.4, 7.5, 7.6_

  - [ ]* 2.15 Écrire les tests unitaires pour `PedagoLens_Profile_Manager`
    - Tester `save` puis `get` retourne le profil sauvegardé
    - Tester `delete` effectue un soft delete (`is_active = false`)
    - Tester `delete` bloqué si profil utilisé dans `pl_analysis`
    - Tester `get_index` cohérent après save/delete
    - Tester validation slug (rejet si invalide ou doublon)
    - _Requirements: 1.7_

  - [ ]* 2.16 Écrire le test de propriété P21 — Profile round-trip (core)
    - **Property 21 : Profile round-trip**
    - Pour tout profil valide sauvegardé via `save`, `get` retourne les mêmes valeurs pour `slug`, `name`, `is_active`, `system_prompt`, `scoring_grid`
    - `// Feature: pedagolens-platform, Property 21: Profile round-trip (core)`
    - **Validates: Requirements 1.7, 7.3**

  - [ ]* 2.17 Écrire le test de propriété P22 — Index cohérent après save/delete (core)
    - **Property 22 : Index cohérent après save/delete**
    - Pour toute séquence de `save` et `delete`, `get_index()` contient exactement les slugs des profils actifs
    - `// Feature: pedagolens-platform, Property 22: Index cohérent après save/delete (core)`
    - **Validates: Requirements 1.7**

  - [ ]* 2.18 Écrire le test de propriété P24 — Suppression bloquée si profil utilisé (core)
    - **Property 24 : Suppression bloquée si profil utilisé**
    - Pour tout slug référencé dans un `pl_analysis`, `delete` retourne `false` et ne modifie pas `is_active`
    - `// Feature: pedagolens-platform, Property 24: Suppression bloquée si profil utilisé (core)`
    - **Validates: Requirements 1.7**

- [ ] 3. Checkpoint — Vérifier que tous les tests du core passent
  - S'assurer que tous les tests passent, poser des questions à l'utilisateur si nécessaire.


- [ ] 4. Implémenter `pedagolens-api-bridge`
  - [ ] 4.1 Créer la classe `PedagoLens_API_Bridge` avec le mode mock complet
    - Header WordPress du plugin, bootstrap, constantes
    - Option `pl_ai_mode` (valeurs : `mock` | `bedrock`) lue via `PedagoLens_Core::get_option`
    - Méthode `invoke(string $prompt_key, array $params): array` qui route vers `invoke_mock()` ou `invoke_bedrock()` selon `pl_ai_mode`
    - Méthode `get_available_models(): array`
    - _Requirements: 2.1_

  - [ ] 4.2 Implémenter le mode mock avec réponses crédibles par prompt_key
    - `invoke_mock('course_analysis', $params)` → lire les profils actifs via `PedagoLens_Profile_Manager::get_all(active_only: true)` et générer dynamiquement les scores pour chaque profil actif (ex. `avance_rapide` : 88, `concentration_tdah` : 54, `langue_seconde` : 61, `usage_passif_ia` : 72), recommandations et summary cohérents
    - Les données mock référencent les cours québécois : Français 101 (analyse littéraire) et Philosophie 101 (introduction à l'argumentation)
    - `invoke_mock('workbench_suggestions', $params)` → suggestions concrètes avec `id`, `section`, `original`, `proposed`, `rationale`, `profile_target` : reformulation de consigne, découpage en étapes, ajout d'exemple, clarification des critères
    - `invoke_mock('student_twin_response', $params)` → réponse avec `reply`, `guardrail_triggered`, `guardrail_reason`, `follow_up_questions`
    - `invoke_mock('dashboard_insight_summary', $params)` → résumé pédagogique narratif cohérent
    - Aucune liste hardcodée de slugs — tous les profils actifs sont lus dynamiquement via `Profile_Manager::get_all()`
    - _Requirements: 2.1, 2.6_

  - [ ] 4.3 Implémenter la lecture sécurisée des credentials AWS
    - Lire `AWS_ACCESS_KEY_ID` via `defined('AWS_ACCESS_KEY_ID') ? AWS_ACCESS_KEY_ID : getenv('AWS_ACCESS_KEY_ID')`
    - Lire `AWS_SECRET_ACCESS_KEY` via `defined('AWS_SECRET_ACCESS_KEY') ? AWS_SECRET_ACCESS_KEY : getenv('AWS_SECRET_ACCESS_KEY')`
    - Lire `AWS_SESSION_TOKEN` via `defined('AWS_SESSION_TOKEN') ? AWS_SESSION_TOKEN : getenv('AWS_SESSION_TOKEN')`
    - Ne JAMAIS stocker ces credentials dans les options WP
    - Les options WP stockent uniquement : `pl_bedrock_region` (défaut : `us-east-1`), `pl_bedrock_model_id` (défaut : `anthropic.claude-sonnet-4-20250514-v2:0`), `pl_bedrock_max_tokens` (défaut : 1500), `pl_bedrock_temperature` (défaut : 0.3)
    - Lever `pl_bedrock_auth_error` si credentials absents en mode bedrock
    - _Requirements: 2.2_

  - [ ] 4.4 Implémenter l'appel AWS Bedrock (mode bedrock)
    - Méthode `invoke_bedrock(string $prompt_key, array $params): array`
    - Déclencher `do_action('pedagolens_before_ai_invoke', $prompt_key, $params)` avant l'appel
    - Appel HTTP au SDK Bedrock avec timeout configurable (défaut 30s)
    - Déclencher `do_action('pedagolens_after_ai_invoke', $prompt_key, $result)` après l'appel
    - Gérer les erreurs : `pl_bedrock_timeout`, `pl_bedrock_rate_limit`, `pl_bedrock_invalid_response`
    - _Requirements: 2.1, 2.2_

  - [ ] 4.5 Implémenter la gestion des prompt templates
    - Méthode `get_prompt_template(string $key): string` lisant `pl_prompt_{key}` depuis les options WP
    - Valeurs par défaut intelligentes pour chaque clé : `course_analysis`, `workbench_suggestions`, `student_twin_response`, `student_guardrail_check`, `dashboard_insight_summary`
    - Méthode `validate_response(array $response, string $schema_key): bool` validant la structure JSON par schéma
    - Retourner `pl_prompt_not_found` si la clé est absente et sans défaut
    - _Requirements: 2.3, 2.4_

  - [ ] 4.6 Créer la page de settings admin du bridge
    - Section "Mode IA" : radio `mock` / `bedrock`
    - Section "Configuration Bedrock" : région, model ID, max tokens, température (affichée seulement si mode bedrock)
    - Section "Prompts" : textarea éditable pour chacun des 5 prompt_keys avec valeur par défaut affichée
    - Notice d'information : "Les credentials AWS doivent être définis dans wp-config.php ou en variables d'environnement"
    - Nonce sur le formulaire, `current_user_can('manage_options')` sur la sauvegarde
    - Sanitize/escape sur toutes les valeurs sauvegardées et affichées
    - _Requirements: 2.3_

  - [ ]* 2.9 Écrire les tests unitaires pour `pedagolens-api-bridge`
    - Tester que le mode mock retourne la structure attendue pour chaque prompt_key
    - Tester que les credentials ne sont jamais lus depuis les options WP
    - Tester `validate_response` avec réponses conformes et non conformes
    - Tester que `invoke` en mode mock ne fait aucun appel HTTP
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [ ]* 4.7 Écrire le test de propriété P5 — Prompt template round-trip (api-bridge)
    - **Property 5 : Prompt template round-trip**
    - Pour toute clé et contenu valide, mettre à jour puis relire via `get_prompt_template` retourne le contenu exact
    - `// Feature: pedagolens-platform, Property 5: Prompt template round-trip (api-bridge)`
    - **Validates: Requirements 2.3**

  - [ ]* 4.8 Écrire le test de propriété P6 — Validation JSON bidirectionnelle (api-bridge)
    - **Property 6 : Validation JSON bidirectionnelle**
    - `validate_response` retourne `true` ssi la réponse est conforme au schéma, `false` sinon
    - `// Feature: pedagolens-platform, Property 6: Validation JSON bidirectionnelle (api-bridge)`
    - **Validates: Requirements 2.4, 2.5**

  - [ ]* 4.9 Écrire le test de propriété P7 — Réponse bridge structurée (api-bridge)
    - **Property 7 : Réponse bridge structurée**
    - Pour tout appel `invoke` avec prompt_key valide et réponse mock conforme, le résultat contient les clés du schéma JSON déclaré
    - `// Feature: pedagolens-platform, Property 7: Réponse bridge structurée (api-bridge)`
    - **Validates: Requirements 2.1**

  - [ ]* 4.10 Écrire le test de propriété P8 — Rejet des réponses invalides (api-bridge)
    - **Property 8 : Rejet des réponses invalides**
    - Pour toute réponse JSON malformée ou non conforme, `invoke` retourne un tableau d'erreur structuré — jamais des données partielles silencieuses
    - `// Feature: pedagolens-platform, Property 8: Rejet des réponses invalides (api-bridge)`
    - **Validates: Requirements 2.2**

  - [ ]* 4.11 Écrire le test de propriété P23 — Profils actifs couverts par mock (api-bridge)
    - **Property 23 : Profils actifs couverts par mock**
    - Pour tout ensemble de profils actifs retournés par `Profile_Manager::get_all(active_only: true)`, la réponse mock de `invoke('course_analysis')` contient un score pour chaque profil actif — ni plus, ni moins
    - `// Feature: pedagolens-platform, Property 23: Profils actifs couverts par mock (api-bridge)`
    - **Validates: Requirements 2.1, 2.6**

- [ ] 5. Checkpoint — Vérifier que tous les tests du bridge passent (mode mock inclus)
  - S'assurer que tous les tests passent, poser des questions à l'utilisateur si nécessaire.


- [ ] 6. Implémenter `pedagolens-landing`
  - [ ] 6.1 Créer la classe principale et les shortcodes
    - Header WordPress du plugin, bootstrap
    - Enregistrer les shortcodes : `[pedagolens_hero]`, `[pedagolens_features]`, `[pedagolens_pricing]`, `[pedagolens_testimonials]`
    - Chaque shortcode lit ses données depuis `pl_landing_settings` (option WP)
    - Escape de toutes les sorties HTML via `esc_html`, `esc_url`, `esc_attr`
    - _Requirements: 6.1_

  - [ ] 6.2 Créer la page de settings admin de la landing
    - Formulaire éditable : titre, sous-titre, texte CTA, URL CTA, couleurs, sections visibles, contenu des blocs
    - Stocker dans `pl_landing_settings` (JSON object)
    - Nonce sur le formulaire, `current_user_can('manage_options')` sur la sauvegarde
    - Sanitize/escape sur toutes les valeurs
    - _Requirements: 6.2_

  - [ ]* 6.3 Écrire les tests unitaires pour `pedagolens-landing`
    - Tester le rendu des shortcodes avec settings variés
    - Tester que les valeurs settings sont bien reflétées dans le HTML rendu
    - _Requirements: 6.1, 6.2_

  - [ ]* 6.4 Écrire le test de propriété P20 — Settings landing reflétés dans le rendu
    - **Property 20 : Settings landing reflétés dans le rendu**
    - Pour toute configuration de settings landing, le HTML rendu par `pedagolens_hero` contient le titre, sous-titre et texte CTA configurés
    - `// Feature: pedagolens-platform, Property 20: Settings landing dans rendu (landing)`
    - **Validates: Requirements 6.1, 6.2**

- [ ] 7. Implémenter `pedagolens-teacher-dashboard`
  - [ ] 7.1 Créer la classe principale et l'interface d'analyse
    - Header WordPress du plugin, bootstrap
    - Méthode `analyze_course(int $course_id): array`
    - Déclencher `do_action('pedagolens_before_analysis', $course_id)` avant l'analyse
    - Appel `PedagoLens_API_Bridge::invoke('course_analysis', $params)`
    - Déclencher `do_action('pedagolens_after_analysis', $course_id, $result)` après l'analyse
    - Retourner `pl_course_not_found` si le cours n'existe pas, `pl_no_profiles_configured` si aucun profil configuré
    - _Requirements: 3.1_

  - [ ] 7.2 Implémenter la persistance de l'analyse et les scores par profil
    - Méthode `save_analysis(int $course_id, array $result): int` créant un CPT `pl_analysis`
    - Sauvegarder : `_pl_course_id`, `_pl_profile_scores`, `_pl_recommendations`, `_pl_raw_response`, `_pl_analyzed_at`, `_pl_summary`, `_pl_impact_estimates`
    - Méthode `get_profile_scores(int $analysis_id): array`
    - Méthode `get_recommendations(int $analysis_id): array`
    - Les scores doivent couvrir exactement les profils actifs retournés par `PedagoLens_Profile_Manager::get_all(active_only: true)`
    - _Requirements: 3.1, 3.2_

  - [ ] 7.3 Créer l'interface admin du dashboard enseignant
    - Page admin listant les cours (`pl_course`) avec bouton "Analyser" et bouton "Nouveau projet"
    - Affichage des scores par profil (barres de progression pour tous les profils actifs via `Profile_Manager::get_all()`) et des recommandations avec impact estimé
    - Liste des projets `pl_project` par cours avec type, date et lien vers le workbench
    - Appel AJAX pour déclencher l'analyse avec nonce et `current_user_can('read_pl_analyses')`
    - Escape de toutes les sorties, sanitize des inputs AJAX
    - Stocker les préférences UI dans `pl_teacher_dashboard_settings`
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

  - [ ]* 7.4 Écrire les tests unitaires pour `pedagolens-teacher-dashboard`
    - Tester `analyze_course` avec mock bridge en mode mock
    - Tester `save_analysis` crée bien le CPT avec toutes les métadonnées
    - Tester les erreurs `pl_course_not_found` et `pl_no_profiles_configured`
    - _Requirements: 3.1, 3.2_

  - [ ]* 7.5 Écrire le test de propriété P9 — Scores couvrent tous les profils configurés
    - **Property 9 : Scores couvrent tous les profils configurés**
    - Pour tout cours et ensemble de profils actifs retournés par `Profile_Manager::get_all()`, `analyze_course` retourne un score pour chaque profil — ni plus, ni moins
    - `// Feature: pedagolens-platform, Property 9: Scores couvrent tous les profils configurés (teacher-dashboard)`
    - **Validates: Requirements 3.1**

  - [ ]* 7.6 Écrire le test de propriété P10 — Persistance de l'analyse
    - **Property 10 : Persistance de l'analyse**
    - Pour tout cours analysé, un CPT `pl_analysis` existe avec `_pl_course_id`, `_pl_profile_scores`, `_pl_recommendations`, `_pl_summary` et `_pl_impact_estimates` correspondant au résultat
    - `// Feature: pedagolens-platform, Property 10: Persistance de l'analyse (teacher-dashboard)`
    - **Validates: Requirements 3.2**

- [ ] 8. Checkpoint — Vérifier que tous les tests du dashboard passent
  - S'assurer que tous les tests passent, poser des questions à l'utilisateur si nécessaire.

- [ ] 9. Implémenter `pedagolens-course-workbench`
  - [ ] 9.1 Créer la classe principale et la récupération des suggestions
    - Header WordPress du plugin, bootstrap
    - Méthode `get_suggestions(int $course_id, string $section): array`
    - Appel `PedagoLens_API_Bridge::invoke('workbench_suggestions', $params)`
    - Valider que chaque suggestion contient `id`, `section`, `original`, `proposed`, `rationale`, `profile_target`
    - Retourner `pl_section_not_found` si la section n'existe pas dans `_pl_content_sections`
    - Lire `_pl_course_type` du cours parent pour adapter les prompts (magistral vs exercice)
    - _Requirements: 4.1, 4.2_

  - [ ] 9.2 Implémenter apply, reject et save_version
    - Méthode `apply_suggestion(int $course_id, string $section, int $suggestion_id): bool`
    - Après apply : contenu de la section = champ `proposed` de la suggestion ; déclencher `do_action('pedagolens_workbench_suggestion_applied', $course_id, $section, $suggestion_id)` ; mettre à jour `_pl_updated_at`
    - Méthode `reject_suggestion(int $course_id, string $section, int $suggestion_id): bool`
    - Après reject : contenu de la section inchangé
    - Méthode `save_version(int $course_id, string $section, string $content): int`
    - Sauvegarder dans `_pl_versions` avec timestamp ; mettre à jour `_pl_last_workbench_at`
    - Nonce et `current_user_can('manage_pl_workbench')` sur chaque action AJAX
    - _Requirements: 4.3, 4.4, 4.5_

  - [ ] 9.3 Implémenter compare_versions et l'interface admin
    - Méthode `compare_versions(int $course_id, string $section): array` retournant les N versions en ordre chronologique
    - Interface admin : panneau latéral avec scores des profils actifs (barres visuelles /100 via `Profile_Manager::get_all()`) ; delta d'impact par profil par suggestion (ex. `+8 pts concentration_tdah`, `+5 pts langue_seconde`, `-2 pts avance_rapide`)
    - Boutons par suggestion : appliquer / rejeter / modifier / comparer / historique
    - Stocker les préférences UI dans `pl_workbench_settings`
    - Sanitize/escape sur toutes les sorties et inputs
    - _Requirements: 4.2, 4.5_

  - [ ]* 9.4 Écrire les tests unitaires pour `pedagolens-course-workbench`
    - Tester `apply_suggestion` met à jour le contenu avec `proposed` et met à jour `_pl_updated_at`
    - Tester `reject_suggestion` préserve le contenu original
    - Tester `save_version` incrémente l'historique
    - Tester les erreurs `pl_suggestion_not_found` et `pl_section_not_found`
    - Tester que les prompts sont adaptés selon `_pl_course_type` (magistral vs exercice)
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

  - [ ]* 9.5 Écrire le test de propriété P11 — Structure des suggestions workbench
    - **Property 11 : Structure des suggestions workbench**
    - Pour tout cours et section valides, chaque suggestion retournée contient `id`, `section`, `original`, `proposed`, `rationale` avec valeurs non vides
    - `// Feature: pedagolens-platform, Property 11: Structure des suggestions workbench (course-workbench)`
    - **Validates: Requirements 4.1**

  - [ ]* 9.6 Écrire le test de propriété P12 — Apply suggestion met à jour le contenu
    - **Property 12 : Apply suggestion met à jour le contenu**
    - Pour toute suggestion valide, après `apply_suggestion`, le contenu de la section est égal au champ `proposed`
    - `// Feature: pedagolens-platform, Property 12: Apply suggestion met à jour le contenu (course-workbench)`
    - **Validates: Requirements 4.2**

  - [ ]* 9.7 Écrire le test de propriété P13 — Reject suggestion préserve le contenu
    - **Property 13 : Reject suggestion préserve le contenu**
    - Pour toute suggestion valide, après `reject_suggestion`, le contenu de la section est identique à avant l'appel
    - `// Feature: pedagolens-platform, Property 13: Reject suggestion préserve le contenu (course-workbench)`
    - **Validates: Requirements 4.3**

  - [ ]* 9.8 Écrire le test de propriété P14 — Historique des versions complet
    - **Property 14 : Historique des versions complet**
    - Pour toute séquence de N sauvegardes via `save_version` sur la même section, `compare_versions` retourne exactement N entrées en ordre chronologique
    - `// Feature: pedagolens-platform, Property 14: Historique des versions complet (course-workbench)`
    - **Validates: Requirements 4.4**

- [ ] 10. Checkpoint — Vérifier que tous les tests du workbench passent
  - S'assurer que tous les tests passent, poser des questions à l'utilisateur si nécessaire.

- [ ] 11. Implémenter `pedagolens-student-twin`
  - [ ] 11.1 Créer la classe principale et la gestion des sessions
    - Header WordPress du plugin, bootstrap
    - Méthode `start_session(int $student_id, int $course_id): string` retournant un UUID unique
    - Créer un CPT `pl_interaction` avec `_pl_session_id`, `_pl_student_id`, `_pl_course_id`, `_pl_started_at`
    - Méthode `end_session(string $session_id): bool` mettant à jour `_pl_ended_at`
    - Méthode `get_history(string $session_id): array` retournant les messages en ordre chronologique
    - Retourner `pl_session_not_found` si session_id inexistant
    - _Requirements: 5.1, 5.5, 5.6_

  - [ ] 11.2 Implémenter send_message avec garde-fous
    - Méthode `send_message(string $session_id, string $message): array`
    - Rejeter avec `pl_session_ended` si la session est terminée — jamais traiter comme message valide
    - Appeler `apply_guardrails($message, $config)` avant l'appel IA
    - Si garde-fou déclenché : déclencher `do_action('pedagolens_guardrail_triggered', $session_id, $message, $reason)` et retourner la réponse avec `guardrail_triggered = true`
    - Appel `PedagoLens_API_Bridge::invoke('student_twin_response', $params)` si pas de garde-fou
    - Sauvegarder le message et la réponse dans `_pl_messages` avec timestamp
    - Nonce et `current_user_can` sur l'endpoint AJAX
    - _Requirements: 5.2, 5.3, 5.6_

  - [ ] 11.3 Implémenter apply_guardrails et la configuration
    - Méthode `apply_guardrails(string $message, array $config): array` retournant `['guardrail_triggered' => bool, 'reason' => string|null]`
    - Vérifier sujets interdits, longueur max, ton selon `pl_guardrails_config`
    - Appel optionnel à `PedagoLens_API_Bridge::invoke('student_guardrail_check', $params)` pour vérification IA
    - Logguer dans `_pl_guardrails_applied` chaque déclenchement
    - _Requirements: 5.3, 5.4_

  - [ ] 11.4 Créer l'interface admin et les settings du jumeau
    - Page admin de configuration : garde-fous (sujets interdits, longueur max, ton), comportement du jumeau
    - Stocker dans `pl_student_twin_settings` et `pl_guardrails_config`
    - Interface étudiant : fenêtre de conversation, affichage de l'historique, questions de suivi
    - Nonce sur tous les formulaires/AJAX, sanitize/escape partout
    - _Requirements: 5.3, 5.4_

  - [ ]* 11.5 Écrire les tests unitaires pour `pedagolens-student-twin`
    - Tester `start_session` crée un CPT `pl_interaction` avec session_id unique
    - Tester `send_message` sur session terminée retourne `pl_session_ended`
    - Tester `apply_guardrails` avec message contenant un sujet interdit
    - Tester `get_history` retourne les messages en ordre chronologique
    - _Requirements: 5.1, 5.2, 5.3, 5.5, 5.6_

  - [ ]* 11.6 Écrire le test de propriété P15 — Unicité des session_id
    - **Property 15 : Unicité des session_id**
    - Pour tout ensemble de N sessions démarrées, tous les session_id sont distincts deux à deux
    - `// Feature: pedagolens-platform, Property 15: Unicité des session_id (student-twin)`
    - **Validates: Requirements 5.1**

  - [ ]* 11.7 Écrire le test de propriété P16 — Structure de réponse message
    - **Property 16 : Structure de réponse message**
    - Pour tout message envoyé dans une session active, la réponse contient `reply` (string non vide), `guardrail_triggered` (bool) et `follow_up_questions` (array)
    - `// Feature: pedagolens-platform, Property 16: Structure de réponse message (student-twin)`
    - **Validates: Requirements 5.2**

  - [ ]* 11.8 Écrire le test de propriété P17 — Garde-fous bidirectionnels
    - **Property 17 : Garde-fous bidirectionnels**
    - Pour toute configuration de garde-fous et message, `apply_guardrails` retourne `guardrail_triggered = true` ssi le message contient un sujet interdit — et `false` sinon
    - `// Feature: pedagolens-platform, Property 17: Garde-fous bidirectionnels (student-twin)`
    - **Validates: Requirements 5.3, 5.4**

  - [ ]* 11.9 Écrire le test de propriété P18 — Historique ordonné
    - **Property 18 : Historique ordonné**
    - Pour toute session avec N messages envoyés, `get_history` retourne exactement N messages en ordre chronologique d'envoi
    - `// Feature: pedagolens-platform, Property 18: Historique ordonné (student-twin)`
    - **Validates: Requirements 5.5**

  - [ ]* 11.10 Écrire le test de propriété P19 — Session terminée refuse les messages
    - **Property 19 : Session terminée refuse les messages**
    - Pour toute session terminée via `end_session`, tout appel ultérieur à `send_message` retourne une erreur — jamais traité comme message valide
    - `// Feature: pedagolens-platform, Property 19: Session terminée refuse les messages (student-twin)`
    - **Validates: Requirements 5.6**

- [ ] 12. Checkpoint final — Vérifier que tous les tests de tous les plugins passent
  - S'assurer que tous les tests passent, poser des questions à l'utilisateur si nécessaire.

## Notes

- Les tâches marquées `*` sont optionnelles et peuvent être sautées pour un MVP rapide
- Chaque tâche référence les requirements pour la traçabilité
- Les checkpoints garantissent une validation incrémentale
- Les tests de propriétés valident les invariants universels (minimum 100 itérations avec Eris)
- Les tests unitaires valident les exemples spécifiques et cas limites
- Le mode mock (`pl_ai_mode = mock`) permet une démo complète sans credentials AWS
- Les credentials AWS ne transitent jamais par les options WordPress
