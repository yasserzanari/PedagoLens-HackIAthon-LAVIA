# Checklist Intervention

## Avant modification
- lire le plugin cible + dependances transverses (`core`, `api-bridge`, `landing`)
- verifier impact roles/capabilities
- verifier impact metas CPT
- verifier nonces et permissions sur endpoints AJAX

## Pendant modification
- garder compat mode `mock` + `bedrock`
- garder schema JSON attendu par `PedagoLens_API_Bridge::validate_response`
- maintenir compatibilite shortcodes existants
- ne pas casser alias FR (`historique`, `parametres`, `institutionnel`)

## Apres modification
- valider parcours enseignant: dashboard -> analyse -> workbench
- valider parcours etudiant: dashboard -> twin chat
- valider login/register front
- verifier logs d'erreurs

## Risques identifies
- gros fichiers monolithiques (`class-landing.php`, `class-workbench-admin.php`) -> risque regressions UI/AJAX
- duplication potentielle de logique settings (core vs api-bridge)
- ecarts possibles entre `cdk/lib/pedagolens-stack.ts` et `cdk/userdata.sh`
- plusieurs secrets/password en clair dans docs/scripts de demo (a securiser hors hackathon)
