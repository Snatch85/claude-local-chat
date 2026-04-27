<?php
/**
 * Directive d'amélioration nocturne — ClaudeLocal Chat
 * Utilisé par GitHub Actions pour améliorer index.php chaque nuit
 */

$GOAL = <<<'GOAL'
Tu es un expert PHP/JS/UX senior. Tu dois améliorer le fichier index.php de ClaudeLocal,
un clone de Claude.ai propulsé par Mistral AI.

CONTEXTE DU FICHIER :
- Application PHP mono-fichier (HTML + CSS + JS + PHP)
- Interface de chat IA avec sidebar, conversations multiples, SQLite, Markdown
- Thème sombre pour la sidebar, fond clair pour le chat
- Propulsé par Mistral AI (API mistral.ai/v1/chat/completions)
- Modèles disponibles : mistral-large-latest, mistral-small-latest, codestral-latest
- Personnalités : assistant, dev PHP, expert marées, chercheur scientifique

RÈGLES IMPÉRATIVES :
1. Retourne UNIQUEMENT le fichier PHP complet, sans commentaire avant ni après
2. Ne tronque pas le fichier — il doit être complet du <?php jusqu'au </html>
3. Conserve TOUTES les fonctionnalités existantes (sidebar, SQLite, Markdown, personas, modèles)
4. Le PHP doit être syntaxiquement valide (pas d'erreur php -l)
5. Conserve le numéro de version et incrémente le patch (ex: 1.0.2 → 1.0.3)
6. Ne change pas la structure de base de données SQLite existante

AMÉLIORATIONS À APPORTER (choisir 1 à 2 par nuit) :
- UX : animations plus fluides sur les messages, transitions CSS
- Raccourci clavier : Ctrl+N pour nouvelle conversation, Ctrl+/ pour focus input
- Bouton "Effacer" la conversation actuelle (sans supprimer)
- Indicateur de tokens restants estimé (basé sur le nombre de mots)
- Mode "Focus" : masquer la sidebar pour plus d'espace
- Scroll automatique amélioré (ne descend pas si l'utilisateur scrollait vers le haut)
- Timestamp affiché sous chaque message (heure locale)
- Possibilité de régénérer la dernière réponse de l'IA
- Export conversation en .txt ou .md
- Amélioration mobile : swipe pour ouvrir/fermer la sidebar
- Compteur de messages par conversation dans la sidebar
- Recherche dans les conversations (filtre par titre)
- Thème clair/sombre toggle
- Suggestions contextuelles selon la personnalité choisie

STYLE À RESPECTER :
- Variables CSS : --accent: #7c3aed (violet), --sidebar: #1c1917 (dark)
- Police : -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif
- Border-radius : 8px standard, 12px pour les grandes boîtes
- Animations : 0.15s à 0.25s ease
- Mobile-first pour les nouvelles fonctionnalités

EXEMPLE DE CE QUI MARCHE BIEN (ne pas casser) :
- La fonction appendMessage() avec rendu Markdown côté JS
- Le système de conversations SQLite (tables conversations + messages)
- L'auto-resize du textarea
- La gestion des erreurs API avec message clair
GOAL;

echo $GOAL;
