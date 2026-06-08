# Module Instructions: ai_conversation

## Purpose
`ai_conversation` is the shared AI conversation foundation module used by Dungeoncrawler and Forseti. It owns conversation API endpoints, model invocation, prompt handling, and chat UX integration.

## Source of truth
- Canonical repository: `/home/ubuntu/forseti.life/ai-conversation`
- Dungeoncrawler site context: `org-chart/sites/dungeoncrawler/site.instructions.md`
- Core architecture reference: `ARCHITECTURE.md`

## Subsystem map (quick routing)

| Subsystem | Responsibility | Primary entry points | Key paths |
|---|---|---|---|
| Chat and API surface | Conversation endpoints, message exchange, and utility routes | `ai_conversation.routing.yml`, `src/Controller/ChatController.php`, `src/Controller/ApiController.php`, `src/Controller/UtilityController.php` | `src/Controller/`, `templates/` |
| Model and prompt runtime | LLM invocation and prompt resolution contracts | `src/Service/AIApiService.php`, `src/Service/PromptManager.php` | `src/Service/` |
| Admin and diagnostics | Module settings, usage reporting, and debug/ops surfaces | `src/Form/AIConversationSettingsForm.php`, `src/Form/SettingsForm.php`, `src/Controller/AdminController.php`, `src/Controller/UsageReportController.php`, `src/Controller/GenAiDebugController.php`, `src/Commands/AiDebugCommands.php` | `src/Form/`, `src/Controller/`, `src/Commands/` |
| Frontend chat UX | Browser chat behavior and conversation rendering | `js/chat-interface.js`, module libraries and templates | `js/`, `css/`, `templates/`, `ai_conversation.libraries.yml` |
| Navigation and user entry points | UI block-level entry points for conversation views | `src/Plugin/Block/AiConversationNavBlock.php`, `src/Plugin/Block/UserConversationsBlock.php` | `src/Plugin/Block/` |
| Drupal module contracts | Service wiring, permissions, and install/config hooks | `ai_conversation.services.yml`, `ai_conversation.permissions.yml`, `ai_conversation.install`, `config/` | module root + `config/` |

## Update rule
When controller routes, AI provider contracts, or prompt/runtime interfaces change, update this file in the same change set.
