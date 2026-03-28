# Architecture Technique

## 1) Plugin Core (`pedagolens-core`)
Responsabilites:
- enregistrement CPT: `pl_analysis`, `pl_course`, `pl_interaction`, `pl_project`
- creation roles: `pedagolens_teacher`, `pedagolens_student`
- import profils JSON `plugins/pedagolens-core/profiles/*.json`
- admin: gestion profils + settings globaux

Meta importantes:
- Analysis: `_pl_profile_scores`, `_pl_recommendations`, `_pl_summary`, `_pl_impact_estimates`
- Course: `_pl_sections`, `_pl_course_type`, `_pl_course_code`, `_pl_session`
- Project: `_pl_content_sections`, `_pl_versions`, `_pl_files`
- Interaction: `_pl_session_id`, `_pl_messages`, `_pl_guardrails_applied`

## 2) API Bridge (`pedagolens-api-bridge`)
Point d'entree: `PedagoLens_API_Bridge::invoke(prompt_key, params)`
Prompt keys:
- `course_analysis`
- `workbench_suggestions`
- `student_twin_response`
- `student_guardrail_check`
- `dashboard_insight_summary`

Modes:
- `mock`: reponses statiques realistes (demo)
- `bedrock`: appel AWS Bedrock, validation schema, signature SigV4

Credentials AWS (priorite):
1. constantes WP
2. variables env
3. options WP
4. IMDSv2 (IAM role EC2)

## 3) Teacher Dashboard (`pedagolens-teacher-dashboard`)
- lance analyse cours via API Bridge
- sauvegarde resultats dans CPT `pl_analysis`
- cree projets `pl_project`
- rendu front dashboard + detail cours + detail analyse

## 4) Course Workbench (`pedagolens-course-workbench`)
- suggestions par section de projet
- apply/reject suggestion
- versionnage section (`_pl_versions`)
- upload/extraction: PPTX, DOCX, PDF
- rendu front + admin

## 5) Student Twin (`pedagolens-student-twin`)
- sessions via `pl_interaction`
- envoi messages + guardrails
- guardrails: longueur, mots interdits, verification IA optionnelle
- rendu shortcode chat + page admin demo

## 6) Landing/App Front (`pedagolens-landing`)
- hub front par shortcodes
- login/register AJAX
- pages app (dashboard, cours/projets, workbench, compte, historique, settings, institutionnel)
- relie certains endpoints AJAX vers classes d'autres plugins si absents
