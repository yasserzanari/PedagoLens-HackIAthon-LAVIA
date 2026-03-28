# AGENT CONTEXT - PedagoLens

Ce dossier contient un contexte de travail pour analyser, modifier et tester rapidement le projet.

## Objectif produit
PedagoLens est une plateforme WordPress orientee pedagogie:
- analyse de contenus de cours par profils etudiants
- suggestions d'amelioration (workbench)
- chat etudiant (jumeau numerique)
- dashboard enseignant
- pages front shortcode (landing + app)

## Modules principaux
- `plugins/pedagolens-core`: roles, CPT, profils, settings noyau
- `plugins/pedagolens-api-bridge`: mode `mock` ou `bedrock`, prompts, validation JSON
- `plugins/pedagolens-landing`: shortcodes front (landing/login/dashboards/pages app)
- `plugins/pedagolens-teacher-dashboard`: analyse de cours + rendu dashboard
- `plugins/pedagolens-course-workbench`: suggestions section, apply/reject, versions, upload docs
- `plugins/pedagolens-student-twin`: sessions chat, guardrails, admin/demo
- `cdk/`: infra EC2 + bootstrap WordPress

## Notes de session
- Worktree non propre detecte pendant l'analyse: `cdk/tmp-deploy-fix.json` (deja modifie avant cette session).

## Fichiers de reference
- `README.md` (vision produit)
- `plan-structure.md` n'existe pas, utiliser `plan-structuré.md` (mapping pages/shortcodes)
- `mock.md` (scenario demo mock)
- `cdk/DEPLOY-INFO.md` et `cdk/userdata.sh` (deploiement EC2)

## Demarrage rapide
1. Activer plugins PedagoLens dans WordPress.
2. Creer pages WordPress et associer les shortcodes (voir `AGENT_CONTEXT/02-shortcodes-et-pages.md`).
3. Choisir mode IA dans options: `mock` (demo) ou `bedrock` (reel).
4. Verifier roles `pedagolens_teacher` et `pedagolens_student`.
