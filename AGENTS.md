<!-- BEGIN faaaster-doc (généré par ~/Dev/faaaster-doc/scripts/sync.sh — NE PAS ÉDITER ce bloc) -->

# Règles faaaster (bloc généré dans chaque AGENTS.md)

**Invariants — s'appliquent AVANT toute sélection de skill :**

- Aucune opération destructive ni mutation de prod (Firestore, cluster, DB clients, config d'un site) sans accord explicite dans la conversation.
- Jamais d'opération Kubernetes/MySQL large (multi-sites, wildcard, scan complet) hors d'un flux dédié, revu et validé.
- Pas de repli silencieux : une contradiction (doc vs code vs état live) se nomme et se tranche par le code ou l'état observé. Hiérarchie de vérité : `code > tests > état live > skills & docs > mémoire de session`.
- Les artefacts marqués `faaaster-doc:generated` (dont ce bloc) ne s'éditent jamais à la main.

**Avant toute tâche faaaster** : lire l'index `~/Dev/faaaster-doc/skills/INDEX.md` et charger la skill du domaine concerné.

**Fin de cycle — une seule proposition groupée, sur confirmation** : ne PAS mettre à jour la doc ni committer à chaque modification. À la fin d'un cycle de travail cohérent (feature, fix, investigation aboutie), proposer ENSEMBLE : (1) le **commit du travail** dans le(s) repo(s) concerné(s) (message + périmètre du diff), **lint et tests du repo VERTS au moment de la proposition** — pas seulement tsc (k8s-consumer → `npm run lint` + `npm test` ; next → `npx eslint <fichiers modifiés>` + `npx tsc --noEmit` ; leçon du 11 juin 2026 : commit repassé en erreur ESLint faute de lint) ; (2) la **synchronisation documentaire** (`/sync-knowledge` ou liste des faits appris) — et, si le diff change ce que voit un client (écran, texte, règle annoncée), la **doc client** (skill `customer-docs-sync` : articles FR + EN sur une branche jumelle de faaaster-doc, publiés sur Crisp au merge). **Attendre la confirmation de l'utilisateur** avant d'exécuter l'un ou l'autre. Les mises à jour doc d'un même cycle se regroupent en **UN commit** dans `~/Dev/faaaster-doc` — diff chirurgical, faits datés, toujours à la SOURCE (jamais un stub). Repo absent ? `git clone git@github.com:laurentvergnaud/faaaster-doc.git ~/Dev/faaaster-doc`.

<!-- END faaaster-doc -->
