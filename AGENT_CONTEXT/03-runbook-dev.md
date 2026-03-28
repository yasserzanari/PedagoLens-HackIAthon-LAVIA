# Runbook Dev

## Prerequis
- PHP 8.1+
- WordPress 6.x
- Composer (tests)
- Optionnel: AWS creds + acces Bedrock

## Tests
- config: `phpunit.xml`
- suites attendues sous `plugins/*/tests`
- commande type:
  - `composer install`
  - `vendor/bin/phpunit`

Note: dossiers `tests` ne sont pas presents dans ce snapshot, donc phpunit peut echouer tant que ces tests ne sont pas ajoutes.

## Debug utile
- activer `WP_DEBUG_LOG`
- logs core via `PedagoLens_Core::log(...)`
- verifier options:
  - `pl_ai_mode`
  - `pl_bedrock_*`
  - `pl_guardrails_config`

## Flux metier typiques
1. Creer un cours (`pl_course`)
2. Lancer analyse (`course_analysis`) -> `pl_analysis`
3. Creer projet (`pl_project`) depuis cours
4. Ouvrir workbench -> suggestions -> apply/reject -> versions
5. Cote etudiant: session twin -> messages -> historique

## Infra (cdk)
- stack EC2 WordPress simple (VPC default)
- SG ouvre 80/443/22
- bootstrap installe WordPress + clone repo + symlinks plugins
- alias deploy: `pl-deploy`
