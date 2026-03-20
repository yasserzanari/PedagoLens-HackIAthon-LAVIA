# Requirements Document — PédagoLens Platform

## Introduction

PédagoLens est une plateforme WordPress d'assistance pédagogique par IA, composée de 6 plugins PHP custom. Elle permet aux enseignants d'analyser leurs cours selon 7 profils d'apprenants, d'obtenir des suggestions d'amélioration contextualisées, et aux étudiants d'interagir avec un jumeau numérique guidé. L'IA est fournie exclusivement par AWS Bedrock (Claude). La plateforme est déployée en localhost WordPress pour la démo HackIAthon 48h.

## Glossaire

- **PédagoLens** : nom de la plateforme ; préfixes `pedagolens_` (hooks, rôles) et `pl_` (options, CPT meta, constantes)
- **Core** : plugin `pedagolens-core`, noyau partagé
- **API_Bridge** : plugin `pedagolens-api-bridge`, couche IA Bedrock
- **Teacher_Dashboard** : plugin `pedagolens-teacher-dashboard`
- **Course_Workbench** : plugin `pedagolens-course-workbench`
- **Student_Twin** : plugin `pedagolens-student-twin`
- **Landing** : plugin `pedagolens-landing`
- **Bedrock** : service AWS Bedrock (Claude), seul fournisseur LLM autorisé
- **CPT** : Custom Post Type WordPress
- **pl_course** : CPT représentant un cours enrichi
- **pl_analysis** : CPT représentant un résultat d'analyse de cours
- **pl_interaction** : CPT représentant une session de conversation jumeau
- **pl_project** : CPT représentant un projet d'amélioration pédagogique (magistral ou exercice)
- **Profile_Manager** : classe `PedagoLens_Profile_Manager` dans `pedagolens-core`, gère les profils comme données WP options
- **Profil pédagogique** : entrée administrable dans `pl_profile_{slug}`, listée via `pl_profile_index`
- **Mode mock** : mode de fonctionnement sans appel AWS, retournant des données de démonstration
- **Mode bedrock** : mode de fonctionnement avec appels réels à AWS Bedrock
- **Prompt_key** : identifiant d'un template de prompt parmi les 5 définis
- **Session** : instance de conversation entre un étudiant et le jumeau numérique, identifiée par un UUID
- **Garde-fou** : règle de filtrage appliquée aux messages étudiants avant traitement IA
- **Section** : subdivision d'un cours ou projet, unité d'analyse et de suggestion
- **Delta d'impact** : variation de score /100 estimée pour un profil suite à l'application d'une suggestion

## Profils pédagogiques

Les profils d'apprenants sont désormais des données administrables gérées par `PedagoLens_Profile_Manager` (option WP `pl_profile_{slug}`, index `pl_profile_index`). Les 7 profils suivants sont seedés à l'activation si l'index est vide :

| Identifiant (slug) | Description |
|---|---|
| `concentration_tdah` | Étudiant avec TDAH ou difficultés de concentration |
| `surcharge_cognitive` | Étudiant en surcharge cognitive |
| `langue_seconde` | Étudiant allophone ou en contexte de langue seconde |
| `faible_autonomie` | Étudiant avec besoin de guidage structuré |
| `anxieux_consignes` | Étudiant anxieux face aux consignes floues ou ambiguës |
| `avance_rapide` | Étudiant avancé et autonome (profil de référence fort) |
| `usage_passif_ia` | Étudiant qui utilise l'IA pour éviter de comprendre (profil à risque) |

---

## Section 1 — Core (CPT, rôles, helpers, constantes)

### Requirement 1.1 — Enregistrement des CPT

**User Story :** En tant que développeur, je veux que les CPT soient enregistrés automatiquement, afin que les données pédagogiques soient persistées dans WordPress.

#### Acceptance Criteria

1. THE Core SHALL enregistrer le CPT `pl_analysis` avec les métadonnées : `_pl_course_id`, `_pl_profile_scores`, `_pl_recommendations`, `_pl_raw_response`, `_pl_analyzed_at`, `_pl_summary`, `_pl_impact_estimates`.
2. THE Core SHALL enregistrer le CPT `pl_course` avec les métadonnées : `_pl_sections`, `_pl_versions`, `_pl_last_workbench_at`, `_pl_course_type` (valeurs : `magistral` | `exercice` | `evaluation` | `travail_equipe`).
3. THE Core SHALL enregistrer le CPT `pl_interaction` avec les métadonnées : `_pl_student_id`, `_pl_course_id`, `_pl_session_id`, `_pl_messages`, `_pl_started_at`, `_pl_ended_at`, `_pl_guardrails_applied`.
4. THE Core SHALL enregistrer le CPT `pl_project` avec les métadonnées : `_pl_course_id`, `_pl_project_type` (valeurs : `magistral` | `exercice` | `evaluation` | `travail_equipe`), `_pl_content_sections`, `_pl_profile_scores`, `_pl_recommendations`, `_pl_impact_estimates`, `_pl_versions`, `_pl_created_at`, `_pl_updated_at`.
5. WHEN WordPress déclenche le hook `init`, THE Core SHALL enregistrer tous les CPT définis.

### Requirement 1.2 — Rôle pedagolens_teacher

**User Story :** En tant qu'administrateur, je veux un rôle enseignant dédié, afin de contrôler les accès aux fonctionnalités pédagogiques.

#### Acceptance Criteria

1. THE Core SHALL créer le rôle `pedagolens_teacher` avec les capacités : `edit_pl_courses`, `publish_pl_courses`, `read_pl_analyses`, `manage_pl_workbench`.
2. WHEN un utilisateur possède le rôle `pedagolens_teacher`, THE Core SHALL lui accorder toutes les capacités listées en 1.2.1.
3. THE Core SHALL exposer la méthode `get_user_role(int $user_id): string` retournant `'teacher'`, `'student'` ou `'admin'`.

### Requirement 1.3 — Rôle pedagolens_student

**User Story :** En tant qu'administrateur, je veux un rôle étudiant isolé, afin qu'aucun étudiant ne puisse modifier les cours.

#### Acceptance Criteria

1. THE Core SHALL créer le rôle `pedagolens_student` sans aucune capacité d'édition de cours.
2. WHEN un utilisateur possède le rôle `pedagolens_student`, THE Core SHALL lui refuser toutes les capacités `edit_pl_courses`, `publish_pl_courses`, `manage_pl_workbench`.

### Requirement 1.4 — Valeur par défaut des options

**User Story :** En tant que développeur, je veux que les options absentes retournent une valeur par défaut, afin d'éviter les erreurs sur une installation vierge.

#### Acceptance Criteria

1. WHEN `get_option` est appelé avec une clé absente et un défaut fourni, THE Core SHALL retourner exactement la valeur par défaut.
2. THE Core SHALL définir les constantes globales : `PEDAGOLENS_VERSION`, `PEDAGOLENS_PLUGIN_DIR`, `PEDAGOLENS_PLUGIN_URL`.

### Requirement 1.5 — Persistance des options

**User Story :** En tant que développeur, je veux que les options soient persistées et relisibles, afin de garantir la cohérence de la configuration.

#### Acceptance Criteria

1. WHEN `update_option` est appelé avec une clé et une valeur valide, THE Core SHALL persister la valeur de sorte que `get_option` sur la même clé retourne une valeur égale.
2. THE Core SHALL exposer `log(string $level, string $message, array $context): void` écrivant dans le debug log WordPress.

### Requirement 1.6 — Hooks inter-plugins

**User Story :** En tant que développeur, je veux des hooks WordPress nommés, afin que les plugins communiquent sans couplage direct.

#### Acceptance Criteria

1. THE Core SHALL déclarer les hooks : `pedagolens_before_analysis`, `pedagolens_after_analysis`, `pedagolens_before_ai_invoke`, `pedagolens_after_ai_invoke`, `pedagolens_guardrail_triggered`, `pedagolens_workbench_suggestion_applied`.
2. WHEN un hook `pedagolens_*` est déclenché, THE Core SHALL transmettre les paramètres documentés sans modification.

### Requirement 1.7 — Gestion des profils d'apprenants (Profile_Manager)

**User Story :** En tant qu'administrateur, je veux gérer les profils d'apprenants comme des données administrables, afin de les créer, modifier et supprimer sans modifier le code.

#### Acceptance Criteria

1. THE Profile_Manager SHALL exposer `get_all(bool $active_only = true): array` retournant les profils actifs (ou tous) triés par `sort_order`.
2. THE Profile_Manager SHALL exposer `get(string $slug): array|null` retournant le profil correspondant au slug ou `null` si absent.
3. THE Profile_Manager SHALL exposer `save(array $profile_data): bool` persistant un profil dans l'option WP `pl_profile_{slug}` et mettant à jour `pl_profile_index`.
4. THE Profile_Manager SHALL exposer `delete(string $slug): bool` effectuant un soft delete (`is_active = false`) — jamais une suppression physique de l'option.
5. THE Profile_Manager SHALL exposer `duplicate(string $slug, string $new_slug): bool` créant une copie du profil avec le nouveau slug.
6. THE Profile_Manager SHALL exposer `get_index(): array` retournant le tableau de slugs depuis `pl_profile_index` sans scanner toutes les options.
7. THE Profile_Manager SHALL exposer `reorder(array $slugs): bool` mettant à jour le champ `sort_order` de chaque profil selon l'ordre fourni.
8. WHEN `save` est appelé avec un slug invalide (contenant des majuscules, espaces ou caractères autres que lettres minuscules et tirets), THE Profile_Manager SHALL rejeter la sauvegarde avec une erreur de validation.
9. WHEN `save` est appelé avec un slug déjà présent dans `pl_profile_index` pour un nouveau profil, THE Profile_Manager SHALL rejeter la sauvegarde avec une erreur de conflit.
10. IF `delete` est appelé sur un slug référencé dans des CPT `pl_analysis` existants, THEN THE Profile_Manager SHALL bloquer la suppression et retourner un message d'erreur explicite.
11. THE Profile_Manager SHALL sanitiser `system_prompt` et `resources` via `sanitize_textarea_field`, et `slug` via `sanitize_text_field`.
12. WHEN `pedagolens-core` est activé et que `pl_profile_index` est vide, THE Core SHALL seeder les 7 profils par défaut (`concentration_tdah`, `surcharge_cognitive`, `langue_seconde`, `faible_autonomie`, `anxieux_consignes`, `avance_rapide`, `usage_passif_ia`) avec `system_prompt` et `resources` vides.

---

## Section 2 — API Bridge (Bedrock, prompts, validation JSON, mode mock)

### Requirement 2.1 — Routage mock / bedrock

**User Story :** En tant que démonstrateur, je veux un mode mock complet, afin de présenter la plateforme sans credentials AWS.

#### Acceptance Criteria

1. THE API_Bridge SHALL lire l'option `pl_ai_mode` (valeurs : `mock` | `bedrock`) via `PedagoLens_Core::get_option`.
2. WHEN `pl_ai_mode` vaut `mock`, THE API_Bridge SHALL retourner des données de démonstration sans effectuer aucun appel HTTP externe.
3. WHEN `pl_ai_mode` vaut `bedrock`, THE API_Bridge SHALL effectuer un appel réel à AWS Bedrock.
4. THE API_Bridge SHALL exposer `invoke(string $prompt_key, array $params): array` routant vers le mode configuré.
5. THE API_Bridge SHALL lire les profils actifs via `PedagoLens_Profile_Manager::get_all(active_only: true)` pour construire les payloads et les réponses mock — sans référence statique aux 7 slugs hardcodés.

### Requirement 2.2 — Credentials AWS

**User Story :** En tant qu'administrateur, je veux que les credentials AWS ne soient jamais stockés dans WordPress, afin de garantir la sécurité.

#### Acceptance Criteria

1. THE API_Bridge SHALL lire `AWS_ACCESS_KEY_ID` exclusivement via `defined('AWS_ACCESS_KEY_ID') ? AWS_ACCESS_KEY_ID : getenv('AWS_ACCESS_KEY_ID')`.
2. THE API_Bridge SHALL lire `AWS_SECRET_ACCESS_KEY` exclusivement via `defined('AWS_SECRET_ACCESS_KEY') ? AWS_SECRET_ACCESS_KEY : getenv('AWS_SECRET_ACCESS_KEY')`.
3. THE API_Bridge SHALL lire `AWS_SESSION_TOKEN` exclusivement via `defined('AWS_SESSION_TOKEN') ? AWS_SESSION_TOKEN : getenv('AWS_SESSION_TOKEN')`.
4. THE API_Bridge SHALL stocker dans les options WP uniquement : `pl_bedrock_region` (défaut : `us-east-1`), `pl_bedrock_model_id` (défaut : `anthropic.claude-3-5-sonnet-20241022-v2:0`), `pl_bedrock_max_tokens` (défaut : 1000), `pl_bedrock_temperature` (défaut : 0.3).
5. IF les credentials AWS sont absents en mode bedrock, THEN THE API_Bridge SHALL retourner l'erreur `pl_bedrock_auth_error`.

### Requirement 2.3 — Prompt templates

**User Story :** En tant qu'administrateur, je veux éditer les prompts depuis l'admin WordPress, afin d'ajuster le comportement de l'IA sans modifier le code.

#### Acceptance Criteria

1. THE API_Bridge SHALL gérer exactement 5 prompt_keys : `course_analysis`, `workbench_suggestions`, `student_twin_response`, `student_guardrail_check`, `dashboard_insight_summary`.
2. THE API_Bridge SHALL lire chaque template depuis l'option `pl_prompt_{key}` via `get_prompt_template(string $key): string`.
3. WHEN une clé de prompt est absente des options WP, THE API_Bridge SHALL retourner la valeur par défaut définie en code.
4. IF une clé de prompt est absente et sans défaut, THEN THE API_Bridge SHALL retourner l'erreur `pl_prompt_not_found`.
5. THE API_Bridge SHALL exposer une page de settings admin permettant d'éditer les 5 templates via des textareas.

### Requirement 2.4 — Validation JSON stricte

**User Story :** En tant que développeur, je veux que les réponses IA soient validées structurellement, afin d'éviter les erreurs silencieuses.

#### Acceptance Criteria

1. THE API_Bridge SHALL valider la structure JSON de chaque réponse Bedrock via `validate_response(array $response, string $schema_key): bool`.
2. WHEN une réponse est conforme au schéma, THE API_Bridge SHALL retourner `true`.
3. WHEN une réponse est non conforme (champs manquants, mauvais types, structure incorrecte), THE API_Bridge SHALL retourner `false`.
4. IF la réponse Bedrock est malformée ou non conforme, THEN THE API_Bridge SHALL retourner l'erreur structurée `pl_bedrock_invalid_response` — jamais des données partielles silencieuses.

### Requirement 2.5 — Erreurs structurées

**User Story :** En tant que développeur, je veux une convention d'erreur uniforme, afin de traiter les erreurs de façon cohérente dans tous les plugins.

#### Acceptance Criteria

1. THE API_Bridge SHALL retourner toute erreur sous la forme `['success' => false, 'error_code' => string, 'error_message' => string, 'context' => array]`.
2. THE API_Bridge SHALL gérer les codes d'erreur : `pl_bedrock_auth_error`, `pl_bedrock_timeout`, `pl_bedrock_rate_limit`, `pl_bedrock_invalid_response`, `pl_prompt_not_found`.
3. WHEN un appel Bedrock dépasse le timeout (défaut 30s), THE API_Bridge SHALL retourner `pl_bedrock_timeout`.

### Requirement 2.6 — Données mock crédibles

**User Story :** En tant que démonstrateur, je veux des données mock réalistes avec les profils actifs et des cours québécois, afin de convaincre le jury lors de la démo.

#### Acceptance Criteria

1. THE API_Bridge SHALL retourner pour `course_analysis` en mode mock des scores /100 pour chaque profil actif retourné par `Profile_Manager::get_all(active_only: true)`, avec des valeurs réalistes entre 45 et 92.
2. THE API_Bridge SHALL inclure dans les données mock des références aux cours : Français 101 (analyse littéraire) et Philosophie 101 (introduction à l'argumentation) du collégial québécois.
3. THE API_Bridge SHALL retourner des scores mock indicatifs : `avance_rapide` : 88/100, `concentration_tdah` : 54/100, `langue_seconde` : 61/100, `usage_passif_ia` : 72/100.
4. THE API_Bridge SHALL retourner pour `workbench_suggestions` en mode mock des suggestions concrètes : reformulation de consigne, découpage en étapes, ajout d'exemple, clarification des critères.
5. THE API_Bridge SHALL retourner pour `student_twin_response` en mode mock une réponse avec `reply`, `guardrail_triggered`, `guardrail_reason`, `follow_up_questions`.
6. WHEN le nombre de profils actifs change, THE API_Bridge SHALL générer dynamiquement les scores mock pour tous les profils actifs — sans liste hardcodée de slugs.

---

## Section 3 — Teacher Dashboard (analyse, scores, recommandations, projets)

### Requirement 3.1 — Analyse de contenu par profils

**User Story :** En tant qu'enseignant, je veux analyser mon cours selon les 7 profils pédagogiques, afin d'identifier les points d'amélioration prioritaires.

#### Acceptance Criteria

1. THE Teacher_Dashboard SHALL exposer `analyze_course(int $course_id): array` déclenchant une analyse IA via `API_Bridge::invoke('course_analysis', $params)`.
2. WHEN `analyze_course` est appelé, THE Teacher_Dashboard SHALL déclencher `do_action('pedagolens_before_analysis', $course_id)` avant l'appel IA.
3. WHEN l'analyse est terminée, THE Teacher_Dashboard SHALL déclencher `do_action('pedagolens_after_analysis', $course_id, $result)`.
4. IF le cours n'existe pas, THEN THE Teacher_Dashboard SHALL retourner l'erreur `pl_course_not_found`.
5. IF aucun profil n'est configuré dans `pl_learner_profiles`, THEN THE Teacher_Dashboard SHALL retourner l'erreur `pl_no_profiles_configured`.
6. THE Teacher_Dashboard SHALL retourner un score /100 pour chacun des 7 profils configurés — ni plus, ni moins.

### Requirement 3.2 — Persistance et lecture des analyses

**User Story :** En tant qu'enseignant, je veux consulter l'historique de mes analyses, afin de suivre l'évolution de mes cours.

#### Acceptance Criteria

1. THE Teacher_Dashboard SHALL créer un CPT `pl_analysis` via `save_analysis(int $course_id, array $result): int` avec toutes les métadonnées définies en 1.1.
2. THE Teacher_Dashboard SHALL exposer `get_profile_scores(int $analysis_id): array` retournant les scores par profil.
3. THE Teacher_Dashboard SHALL exposer `get_recommendations(int $analysis_id): array` retournant les recommandations priorisées.
4. THE Teacher_Dashboard SHALL afficher dans l'interface admin les scores par profil sous forme de barres de progression.

### Requirement 3.3 — Impact estimé et recommandations priorisées

**User Story :** En tant qu'enseignant, je veux voir l'impact estimé de chaque recommandation, afin de prioriser mes efforts.

#### Acceptance Criteria

1. THE Teacher_Dashboard SHALL sauvegarder dans `_pl_impact_estimates` l'impact estimé de chaque suggestion par profil.
2. THE Teacher_Dashboard SHALL afficher les recommandations triées par priorité décroissante.
3. THE Teacher_Dashboard SHALL afficher pour chaque recommandation le ou les profils ciblés.

### Requirement 3.4 — Gestion des projets depuis le dashboard

**User Story :** En tant qu'enseignant, je veux créer et gérer des projets depuis le dashboard, afin d'organiser mes améliorations pédagogiques.

#### Acceptance Criteria

1. THE Teacher_Dashboard SHALL permettre de créer un projet `pl_project` de type `magistral` ou `exercice` depuis un cours `pl_course`.
2. THE Teacher_Dashboard SHALL lister les projets existants par cours avec leur type, date de création et statut.
3. THE Teacher_Dashboard SHALL permettre d'ouvrir un projet existant dans le Course_Workbench.
4. WHEN un projet est créé, THE Teacher_Dashboard SHALL initialiser `_pl_created_at` et `_pl_updated_at` avec la date courante.

---

## Section 4 — Course Workbench (projets magistral et exercice, apply/reject, versions, impact)

### Requirement 4.1 — Création et gestion des projets

**User Story :** En tant qu'enseignant, je veux créer des projets magistral et exercice, afin d'améliorer différents types de contenus pédagogiques.

#### Acceptance Criteria

1. THE Course_Workbench SHALL permettre de créer un projet `pl_project` de type `magistral` (diapositives, plan de cours, notes) ou `exercice` (consigne, TP, évaluation).
2. THE Course_Workbench SHALL afficher le contenu du projet découpé en sections via `_pl_content_sections`.
3. WHEN le type de projet est `magistral`, THE Course_Workbench SHALL adapter ses prompts et suggestions pour l'analyse de diapositives et plans de cours.
4. WHEN le type de projet est `exercice`, THE Course_Workbench SHALL adapter ses prompts pour identifier les zones floues, surcharges cognitives et ambiguïtés.
5. THE Course_Workbench SHALL lire `_pl_course_type` du cours parent pour adapter les suggestions selon le contexte.

### Requirement 4.2 — Suggestions IA par section avec delta d'impact

**User Story :** En tant qu'enseignant, je veux voir les suggestions IA avec leur impact estimé par profil, afin de choisir les modifications les plus bénéfiques.

#### Acceptance Criteria

1. THE Course_Workbench SHALL exposer `get_suggestions(int $course_id, string $section): array` via `API_Bridge::invoke('workbench_suggestions', $params)`.
2. THE Course_Workbench SHALL valider que chaque suggestion contient : `id`, `section`, `original`, `proposed`, `rationale`, `profile_target`.
3. THE Course_Workbench SHALL afficher pour chaque suggestion un delta d'impact par profil, par exemple : `+8 pts concentration_tdah`, `+5 pts langue_seconde`, `-2 pts avance_rapide`.
4. THE Course_Workbench SHALL afficher dans le panneau latéral les scores des 7 profils avec barres visuelles /100.
5. IF la section n'existe pas dans `_pl_content_sections`, THEN THE Course_Workbench SHALL retourner l'erreur `pl_section_not_found`.

### Requirement 4.3 — Apply suggestion

**User Story :** En tant qu'enseignant, je veux appliquer une suggestion IA, afin de mettre à jour le contenu de la section.

#### Acceptance Criteria

1. THE Course_Workbench SHALL exposer `apply_suggestion(int $course_id, string $section, int $suggestion_id): bool`.
2. WHEN `apply_suggestion` est appelé, THE Course_Workbench SHALL remplacer le contenu de la section par le champ `proposed` de la suggestion.
3. WHEN une suggestion est appliquée, THE Course_Workbench SHALL déclencher `do_action('pedagolens_workbench_suggestion_applied', $course_id, $section, $suggestion_id)`.
4. WHEN une suggestion est appliquée, THE Course_Workbench SHALL mettre à jour `_pl_updated_at` du projet.

### Requirement 4.4 — Reject suggestion

**User Story :** En tant qu'enseignant, je veux rejeter une suggestion IA, afin de conserver le contenu original.

#### Acceptance Criteria

1. THE Course_Workbench SHALL exposer `reject_suggestion(int $course_id, string $section, int $suggestion_id): bool`.
2. WHEN `reject_suggestion` est appelé, THE Course_Workbench SHALL laisser le contenu de la section identique à avant l'appel.

### Requirement 4.5 — Versionnage et comparaison

**User Story :** En tant qu'enseignant, je veux consulter l'historique des versions d'une section, afin de comparer et revenir en arrière si nécessaire.

#### Acceptance Criteria

1. THE Course_Workbench SHALL exposer `save_version(int $course_id, string $section, string $content): int` sauvegardant dans `_pl_versions` avec timestamp.
2. THE Course_Workbench SHALL exposer `compare_versions(int $course_id, string $section): array` retournant les versions en ordre chronologique.
3. WHEN N sauvegardes sont effectuées sur la même section, THE Course_Workbench SHALL retourner exactement N entrées via `compare_versions`.
4. THE Course_Workbench SHALL proposer dans l'interface les boutons : appliquer / rejeter / modifier / comparer / historique.

---

## Section 5 — Student Twin (sessions, garde-fous, historique, jumeau numérique)

### Requirement 5.1 — Gestion des sessions

**User Story :** En tant qu'étudiant, je veux démarrer une session avec le jumeau numérique, afin d'obtenir de l'aide sur mon cours.

#### Acceptance Criteria

1. THE Student_Twin SHALL exposer `start_session(int $student_id, int $course_id): string` retournant un UUID unique.
2. WHEN une session est démarrée, THE Student_Twin SHALL créer un CPT `pl_interaction` avec `_pl_session_id`, `_pl_student_id`, `_pl_course_id`, `_pl_started_at`.
3. THE Student_Twin SHALL garantir que tous les session_id générés sont distincts deux à deux.
4. THE Student_Twin SHALL exposer `end_session(string $session_id): bool` mettant à jour `_pl_ended_at`.
5. IF le session_id est inexistant, THEN THE Student_Twin SHALL retourner l'erreur `pl_session_not_found`.

### Requirement 5.2 — Envoi de messages

**User Story :** En tant qu'étudiant, je veux envoyer des messages au jumeau numérique, afin d'obtenir des réponses pédagogiques guidées.

#### Acceptance Criteria

1. THE Student_Twin SHALL exposer `send_message(string $session_id, string $message): array`.
2. WHEN un message est envoyé dans une session active, THE Student_Twin SHALL appeler `API_Bridge::invoke('student_twin_response', $params)` après validation des garde-fous.
3. THE Student_Twin SHALL sauvegarder le message et la réponse dans `_pl_messages` avec timestamp.
4. THE Student_Twin SHALL retourner une réponse contenant : `reply` (string non vide), `guardrail_triggered` (bool), `follow_up_questions` (array).

### Requirement 5.3 — Garde-fous

**User Story :** En tant qu'administrateur, je veux configurer des garde-fous, afin d'empêcher le jumeau de faire le travail académique à la place de l'étudiant.

#### Acceptance Criteria

1. THE Student_Twin SHALL exposer `apply_guardrails(string $message, array $config): array` retournant `['guardrail_triggered' => bool, 'reason' => string|null]`.
2. WHEN un message contient un sujet interdit selon `pl_guardrails_config`, THE Student_Twin SHALL retourner `guardrail_triggered = true`.
3. WHEN aucun sujet interdit n'est détecté, THE Student_Twin SHALL retourner `guardrail_triggered = false`.
4. WHEN un garde-fou est déclenché, THE Student_Twin SHALL déclencher `do_action('pedagolens_guardrail_triggered', $session_id, $message, $reason)`.
5. THE Student_Twin SHALL logguer chaque déclenchement dans `_pl_guardrails_applied`.

### Requirement 5.4 — Configuration des garde-fous

**User Story :** En tant qu'administrateur, je veux configurer les règles de garde-fous, afin d'adapter le comportement du jumeau au contexte pédagogique.

#### Acceptance Criteria

1. THE Student_Twin SHALL lire la configuration des garde-fous depuis `pl_guardrails_config` (sujets interdits, longueur max, ton).
2. WHERE la vérification IA est activée, THE Student_Twin SHALL appeler `API_Bridge::invoke('student_guardrail_check', $params)` pour validation complémentaire.
3. THE Student_Twin SHALL exposer une page de settings admin pour configurer les garde-fous.

### Requirement 5.5 — Historique des messages

**User Story :** En tant qu'enseignant, je veux consulter l'historique des sessions étudiantes, afin de suivre l'utilisation du jumeau numérique.

#### Acceptance Criteria

1. THE Student_Twin SHALL exposer `get_history(string $session_id): array` retournant les messages en ordre chronologique.
2. WHEN N messages sont envoyés dans une session, THE Student_Twin SHALL retourner exactement N messages via `get_history`.
3. THE Student_Twin SHALL journaliser les sessions dans `_pl_messages` pour consultation depuis le Teacher_Dashboard.

### Requirement 5.6 — Rejet des messages sur session terminée

**User Story :** En tant que développeur, je veux que les sessions terminées refusent les nouveaux messages, afin de garantir l'intégrité des données.

#### Acceptance Criteria

1. IF une session est terminée via `end_session`, THEN THE Student_Twin SHALL retourner l'erreur `pl_session_ended` pour tout appel ultérieur à `send_message`.
2. THE Student_Twin SHALL ne jamais traiter un message sur une session terminée comme un message valide.

---

## Section 6 — Landing Page (shortcodes, settings, rendu)

### Requirement 6.1 — Shortcodes de la landing page

**User Story :** En tant qu'administrateur, je veux insérer la landing page via des shortcodes WordPress, afin de composer librement la page de présentation.

#### Acceptance Criteria

1. THE Landing SHALL enregistrer les shortcodes : `[pedagolens_hero]`, `[pedagolens_features]`, `[pedagolens_pricing]`, `[pedagolens_testimonials]`.
2. WHEN un shortcode est rendu, THE Landing SHALL lire ses données depuis `pl_landing_settings` (option WP).
3. THE Landing SHALL échapper toutes les sorties HTML via `esc_html`, `esc_url`, `esc_attr`.
4. WHERE une section est désactivée dans `pl_landing_settings`, THE Landing SHALL ne pas rendre le shortcode correspondant.

### Requirement 6.2 — Settings de la landing page

**User Story :** En tant qu'administrateur, je veux éditer le contenu de la landing page depuis l'admin WordPress, afin de personnaliser la présentation sans modifier le code.

#### Acceptance Criteria

1. THE Landing SHALL exposer une page de settings admin permettant d'éditer : titre, sous-titre, texte CTA, URL CTA, couleurs, sections visibles, contenu des blocs.
2. THE Landing SHALL stocker la configuration dans `pl_landing_settings` (JSON object).
3. WHEN le formulaire de settings est soumis, THE Landing SHALL vérifier le nonce et `current_user_can('manage_options')`.
4. THE Landing SHALL sanitiser et échapper toutes les valeurs sauvegardées et affichées.
5. WHEN les settings sont mis à jour, THE Landing SHALL refléter les nouvelles valeurs dans le rendu des shortcodes sans délai.

---

## Section 7 — Gestion des profils d'apprenants (admin UI)

### Requirement 7.1 — Menu admin et navigation

**User Story :** En tant qu'administrateur, je veux un menu WordPress dédié aux profils, afin d'accéder rapidement à leur gestion.

#### Acceptance Criteria

1. THE Core SHALL enregistrer dans le menu WordPress une entrée "Profils d'apprenants" sous "PédagoLens" avec deux sous-entrées : "Tous les profils" et "Ajouter un profil".
2. WHEN un utilisateur sans la capacité `manage_options` tente d'accéder aux pages de gestion des profils, THE Core SHALL refuser l'accès.
3. THE Core SHALL protéger toutes les pages admin de gestion des profils avec `current_user_can('manage_options')`.

### Requirement 7.2 — Page liste des profils

**User Story :** En tant qu'administrateur, je veux voir tous les profils dans un tableau administrable, afin de les gérer efficacement.

#### Acceptance Criteria

1. THE Core SHALL afficher la liste des profils via une `WP_List_Table` avec les colonnes : Nom, Slug, Statut, Ordre, System prompt (extrait 80 chars), Actions.
2. THE Core SHALL proposer pour chaque profil les actions : Modifier, Dupliquer, Activer/Désactiver (AJAX), Supprimer (avec confirmation JavaScript).
3. THE Core SHALL afficher en haut de la liste les boutons : "+ Ajouter un profil", "Importer un profil JSON", "Exporter tous les profils".
4. THE Core SHALL permettre le réordonnancement par drag-and-drop (jQuery UI Sortable) avec sauvegarde AJAX via l'action `pl_reorder_profiles`.
5. WHEN une action AJAX est déclenchée sur la liste, THE Core SHALL vérifier le nonce et `current_user_can('manage_options')`.

### Requirement 7.3 — Page édition d'un profil

**User Story :** En tant qu'administrateur, je veux éditer un profil en 5 sections structurées, afin de configurer complètement son comportement IA.

#### Acceptance Criteria

1. THE Core SHALL afficher la page d'édition avec 5 sections : (A) Identité, (B) Prompt système IA, (C) Ressources scientifiques, (D) Grille de scoring, (E) Prévisualisation.
2. THE Core SHALL afficher en section A : nom, slug (auto-généré depuis le nom), description (max 200 chars), statut actif, ordre.
3. THE Core SHALL afficher en section B : textarea grand format (min-height 400px, police monospace), checkboxes `inject_resources` et `inject_scoring`.
4. THE Core SHALL afficher en section C : textarea grand format (min-height 300px) pour les ressources scientifiques en Markdown.
5. THE Core SHALL afficher en section D : tableau éditable de 5 lignes (min/max/label/couleur) avec validation JavaScript (pas de chevauchement, couverture complète 0-100).
6. THE Core SHALL afficher en section E : bouton "Prévisualiser le prompt complet" ouvrant une modale avec le prompt assemblé et le nombre de tokens estimés.
7. THE Core SHALL proposer les boutons : Enregistrer, Enregistrer et continuer, Annuler.
8. WHEN le formulaire d'édition est soumis, THE Core SHALL vérifier le nonce et `current_user_can('manage_options')`.
9. THE Core SHALL sanitiser `system_prompt` et `resources` via `sanitize_textarea_field`, et `slug` via `sanitize_text_field`.

### Requirement 7.4 — Page settings refactorisée en 4 onglets

**User Story :** En tant qu'administrateur, je veux une page de settings organisée en onglets, afin de configurer chaque aspect de la plateforme séparément.

#### Acceptance Criteria

1. THE Core SHALL organiser la page de settings en 4 onglets : "IA & Bedrock", "Profils d'apprenants", "Comportement", "Avancé".
2. THE Core SHALL afficher dans l'onglet "IA & Bedrock" : mode IA (select mock/bedrock), et si bedrock : région, modèle (défaut `anthropic.claude-sonnet-4-20250514-v2:0`), tokens max (défaut 1500), température (range 0.0-1.0, défaut 0.3), timeout (défaut 30s), bouton "Tester la connexion Bedrock" (AJAX), format de sortie, mode debug.
3. THE Core SHALL afficher dans l'onglet "Profils d'apprenants" : lien vers la page de gestion, aperçu readonly (Nom | Slug | Statut | Prompt renseigné oui/non), boutons Gérer les profils / Exporter JSON / Importer JSON.
4. THE Core SHALL afficher dans l'onglet "Comportement" : profils actifs dans l'analyse (checkboxes depuis `Profile_Manager::get_all()`), ordre d'affichage (drag-and-drop), toggles (afficher score /100, barre de couleur, résumé de simulation), nombre max de propositions (défaut 5).
5. THE Core SHALL afficher dans l'onglet "Avancé" : bouton "Réinitialiser les profils par défaut" (danger, confirmation requise), vider les logs, exporter/importer toute la config (JSON), version du plugin (readonly).
6. WHEN un formulaire de settings est soumis, THE Core SHALL vérifier le nonce et `current_user_can('manage_options')`.

### Requirement 7.5 — Import / Export des profils

**User Story :** En tant qu'administrateur, je veux importer et exporter des profils en JSON, afin de partager des configurations entre installations.

#### Acceptance Criteria

1. THE Core SHALL permettre l'export de tous les profils en un fichier JSON téléchargeable.
2. THE Core SHALL permettre l'import d'un profil depuis un fichier JSON uploadé, avec validation de la structure avant sauvegarde.
3. IF un profil importé a un slug déjà existant, THEN THE Core SHALL proposer à l'administrateur de remplacer ou d'annuler l'import.
4. THE Core SHALL sanitiser toutes les valeurs importées selon les mêmes règles que `Profile_Manager::save`.

### Requirement 7.6 — Réinitialisation des profils par défaut

**User Story :** En tant qu'administrateur, je veux pouvoir réinitialiser les profils aux valeurs par défaut, afin de repartir d'une configuration propre.

#### Acceptance Criteria

1. THE Core SHALL exposer une action "Réinitialiser les profils par défaut" nécessitant une confirmation explicite de l'administrateur.
2. WHEN la réinitialisation est confirmée, THE Core SHALL recréer les 7 profils par défaut avec `system_prompt` et `resources` vides, en écrasant les profils existants portant les mêmes slugs.
3. WHEN la réinitialisation est confirmée, THE Core SHALL vérifier le nonce et `current_user_can('manage_options')`.
