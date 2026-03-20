```markdown
# Système de Design : L'Intelligence Éditoriale

## 1. Vision et Étoile Polaire : "Le Curateur Digital"
Ce système de design ne se contente pas d'afficher des données ; il les met en scène. La "North Star" de ce projet est **Le Curateur Digital**. L'objectif est de transformer une plateforme SaaS éducative en une expérience éditoriale de haut niveau, rappelant les publications académiques de luxe ou les rapports financiers premium.

Pour briser l'aspect "template" des LMS classiques, nous utilisons :
*   **L'Asymétrie Intentionnelle :** Des mises en page qui jouent sur des espaces blancs généreux et des alignements décalés pour guider l'œil vers les insights clés.
*   **La Hiérarchie de Contraste :** Une typographie monumentale pour les titres (Display) contrastant avec des interfaces de données ultra-fines.
*   **La Superposition Organique :** Des éléments qui se chevauchent légèrement pour créer une sensation de profondeur naturelle et non rigide.

---

## 2. Palette Chromatique et Sémantique
L'identité visuelle repose sur une profondeur tonale plutôt que sur des lignes de démarcation.

### Les Couleurs Fondamentales
*   **Fonds (Surface) :** `#F7F9FB` (Surface) et `#FFFFFF` (Surface Container Lowest). L'utilisation du blanc pur est réservée aux éléments les plus "élevés" pour attirer l'attention.
*   **Accent Primaire (Deep Blue) :** `#00236F` (Primary). Incarne l'autorité institutionnelle et la stabilité.
*   **Accent Secondaire (Violet) :** `#712AE2` (Secondary). Utilisé pour les insights générés par l'IA et l'innovation pédagogique.
*   **Accent Tertiaire (Turquoise) :** `#002F38` (Tertiary). Dédié aux indicateurs de croissance et de fluidité.

### Les Règles d'Or du Senior Director
1.  **La Règle du "No-Line" :** Il est strictement interdit d'utiliser des bordures de 1px pour séparer les sections. La séparation doit se faire par le changement de ton (ex: un bloc `surface-container-low` sur un fond `surface`) ou par l'espace blanc (échelle de spacing 8 ou 12).
2.  **Texture Signature :** Utilisez des dégradés subtils entre `primary` (#00236F) et `primary_container` (#1E3A8A) pour les boutons principaux ou les headers de widgets afin d'apporter une "âme" visuelle que le plat ne peut offrir.
3.  **Glassmorphism :** Pour les éléments flottants (menus contextuels, modales), utilisez la couleur `surface` avec une opacité de 80% et un `backdrop-blur` de 12px.

---

## 3. Typographie Éditoriale
Le choix de **Manrope** pour les titres et **Inter** pour le corps de texte crée un équilibre entre modernité technique et lisibilité académique.

| Rôle | Police | Taille (rem) | Usage |
| :--- | :--- | :--- | :--- |
| **Display-LG** | Manrope | 3.5rem | Chiffres clés de l'IA, Hero headers. |
| **Headline-MD** | Manrope | 1.75rem | Titres de sections majeures. |
| **Title-LG** | Inter | 1.375rem | Titres de cartes et widgets. |
| **Body-MD** | Inter | 0.875rem | Texte courant, analyses pédagogiques. |
| **Label-SM** | Inter | 0.6875rem | Métadonnées, micro-copy, légendes. |

*Note : Toujours utiliser un espacement de lettre (letter-spacing) de -0.02em sur les titres Display pour un look plus "pressé" et premium.*

---

## 4. Élévation, Profondeur et Calques
Nous abandonnons la grille plate pour une approche de **Stratification Tonale**.

*   **Le Principe d'Empilement :**
    *   Niveau 0 (Fond de page) : `surface` (#F7F9FB).
    *   Niveau 1 (Sections de contenu) : `surface-container-low` (#F2F4F6).
    *   Niveau 2 (Cartes d'insights) : `surface-container-lowest` (#FFFFFF).
*   **Ombres Ambiantes :** Pour les éléments nécessitant un soulèvement (ex: modales), utilisez des ombres ultra-diffuses.
    *   *Propriété :* `box-shadow: 0 10px 40px rgba(25, 28, 30, 0.06);` (Teinte basée sur `on-surface`).
*   **Le "Ghost Border" :** Si une séparation est vitale pour l'accessibilité, utilisez le token `outline_variant` à 15% d'opacité maximum. Jamais d'aplats opaques.

---

## 5. Composants Primitifs & Spécifiques

### Boutons & Actions
*   **Primaire :** Fond dégradé (`primary` vers `primary_container`), coins arrondis `lg` (1rem). Pas de bordure. Texte en `on_primary`.
*   **Tertiaire (Ghost) :** Texte uniquement en `primary`, avec un état de survol (hover) utilisant `primary_fixed_dim` à 10% d'opacité.

### Cartes d'Insights (Insight Widgets)
*   **Style :** Fond `surface-container-lowest`, rayon `xl` (1.5rem).
*   **Structure :** Pas de séparateurs horizontaux. Utilisez le spacing `5` (1.7rem) pour créer des zones distinctes.
*   **Indicateur IA :** Une subtile lueur (glow) violette (`secondary`) dans le coin supérieur droit pour indiquer une analyse générée par l'algorithme.

### Graphiques & Données
*   **Traitement :** Utilisation de courbes splines (arrondies) plutôt que des lignes brisées.
*   **Couleurs :** Alternance entre `primary`, `secondary` (violet) et `tertiary_fixed_dim` (turquoise) pour les comparatifs.

### Chips de Statut
*   **Style :** Forme `full` (pillule), fond très clair (ex: `tertiary_fixed` à 20%), texte en `on_tertiary_fixed_variant`.

---

## 6. Les "Do's & Don'ts" (À faire et à éviter)

### ✅ À faire
*   **Aérer :** Si vous hésitez sur l'espace entre deux widgets, utilisez la valeur supérieure de l'échelle (ex: passez de `8` à `10`).
*   **Nuancer :** Utilisez les variantes de gris bleutés (`on_surface_variant`) pour le texte secondaire afin de réduire la fatigue visuelle.
*   **Arrondir :** Respectez scrupuleusement le rayon `lg` (16px) pour les cartes afin de maintenir l'aspect "moderne doux".

### ❌ À ne pas faire
*   **Lignes noires :** Ne jamais utiliser de noir pur (#000000) ou de bordures visibles pour structurer la page.
*   **Surcharge :** Éviter de placer plus de 3 widgets d'analyse sur une même ligne. L'expérience doit rester respirante, pas encombrée.
*   **Couleurs vives :** Ne pas utiliser de couleurs saturées en dehors des tokens définis. Le système doit rester "institutionnel" et sérieux.

---

## 7. Échelle de Spacing (Référence Rapide)
*   **Section Padding :** `12` (4rem) ou `16` (5.5rem).
*   **Widget Gap :** `6` (2rem).
*   **Internal Card Padding :** `4` (1.4rem).
*   **Micro-spacing :** `1.5` (0.5rem) pour les labels et icônes.

---
*Ce document est la fondation de l'excellence visuelle de la plateforme. Chaque pixel doit servir la clarté pédagogique et l'autorité technologique.*```