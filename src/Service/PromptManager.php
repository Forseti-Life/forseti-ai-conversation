<?php

namespace Drupal\ai_conversation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Centralized prompt management service for AI conversations.
 * 
 * This service provides a single source of truth for system prompts,
 * ensuring consistency across the application and simplifying maintenance.
 */
class PromptManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a PromptManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * Get the base system prompt for generic Forseti Assistance.
   *
   * @return string
   *   The system prompt text.
   */
  public function getBaseSystemPrompt() {
    return <<<'EOD'
You are Forseti Assistance for forseti.life.

MISSION:
Provide clear, practical help for users using Forseti services, with current priority on free resume tailoring and Job Hunter workflows.

CORE IDENTITY:
- Helpful, direct, and solution-oriented.
- Honest about uncertainty and system limitations.
- Focused on user outcomes and actionable next steps.

ASSISTANCE RULES:
1. Clarify the user goal before suggesting steps when requests are ambiguous.
2. Prefer concise, structured responses:
   - What this means
   - What to do next
   - Optional alternatives
3. Never claim server-side actions happened unless explicitly confirmed by system context.
4. If data or context is missing, state that clearly and request only the minimum needed details.
5. Avoid roleplay and fictional framing.

CURRENT FORSETI FOCUS:
- Resume tailoring guidance
- Job Hunter workflow support
- Account onboarding/help
- Navigation help across Forseti surfaces
- General platform questions and feedback routing

STYLE:
- Tone: calm, clear, professional, and encouraging
- Keep answers specific and practical
- Avoid hype and unnecessary filler

SUGGESTIONS FLOW:
Step 1 - Discuss:
- Understand the idea, bug, or request.
- Restate intended user value.

Step 2 - Confirm Summary:
- Provide a 1-3 sentence summary and ask for confirmation.

Step 3 - Submit after confirmation:
Append this exact tag block after your normal response:

[CREATE_SUGGESTION]
Summary: [exact confirmed summary]
Category: [one of: safety_feature, partnership, technical_improvement, community_initiative, content_update, general_feedback, other]
Original: [user's original suggestion text]
[/CREATE_SUGGESTION]

Category guidance:
- technical_improvement: bugs, performance, reliability, UX issues
- content_update: copy/content/documentation updates
- safety_feature: security/safety/privacy-oriented improvements
- community_initiative: user programs, engagement features
- partnership: integrations and cross-organization efforts
- general_feedback: broad user feedback
- other: everything else

IMPORTANT:
- Never emit CREATE_SUGGESTION without confirmation.
- Keep summaries implementation-ready.

YOUR GOAL:
Be the most reliable assistant for Forseti users: clear, practical, and outcome-focused.
EOD;
  }

  /**
   * Get the full system prompt with dynamic content integration.
   *
   * @param int $node_id
    *   Optional node ID to load dynamic content from (e.g., world lore or campaign details).
   *
   * @return string
   *   The complete system prompt with dynamic content.
   */
  public function getSystemPrompt($node_id = NULL) {
    $base_prompt = $this->getBaseSystemPrompt();
    
    // If a node ID is provided, append dynamic content
    if ($node_id) {
      $dynamic_content = $this->loadDynamicContent($node_id);
      if (!empty($dynamic_content)) {
        $base_prompt .= "\n\n--- ADDITIONAL WORLD CONTEXT ---\n\n" . $dynamic_content;
      }
    }
    
    return $base_prompt;
  }

  /**
   * Load dynamic content from a node.
   *
   * @param int $node_id
   *   The node ID to load.
   *
   * @return string
   *   The node content or empty string if not found.
   */
  protected function loadDynamicContent($node_id) {
    try {
      $node = $this->entityTypeManager->getStorage('node')->load($node_id);
      
      if ($node && $node->access('view')) {
        $content = '';
        
        // Add title
        $content .= "TITLE: " . $node->getTitle() . "\n\n";
        
        // Add body content if available
        if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
          $body_value = $node->get('body')->value;
          // Strip HTML tags but preserve line breaks
          $clean_content = strip_tags($body_value);
          $content .= $clean_content;
        }
        
        return $content;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading dynamic content from node @nid: @message', [
        '@nid' => $node_id,
        '@message' => $e->getMessage(),
      ]);
    }
    
    return '';
  }

  /**
   * Get a shortened fallback prompt.
   *
   * @return string
   *   A brief description of generic Forseti Assistance.
   */
  public function getFallbackPrompt() {
    return "Forseti Assistance for forseti.life. Provides clear guidance for resume tailoring, Job Hunter workflows, onboarding, and general platform support.";
  }

  /**
   * Save the base system prompt to configuration.
   *
   * @param string $prompt
   *   The prompt text to save.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function saveSystemPrompt($prompt) {
    try {
      $config = $this->configFactory->getEditable('ai_conversation.settings');
      $config->set('system_prompt', $prompt);
      $config->save();
      
      // Clear config cache
      \Drupal::service('cache.config')->deleteAll();
      
      $this->logger->info('System prompt updated successfully. Length: @length', [
        '@length' => strlen($prompt),
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error saving system prompt: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Initialize the system prompt configuration with default Forseti prompt.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function initializeDefaultPrompt() {
    $default_prompt = $this->getBaseSystemPrompt();
    return $this->saveSystemPrompt($default_prompt);
  }

  /**
   * Get configured system prompt from config or use default.
   *
   * @return string
   *   The system prompt.
   */
  public function getConfiguredPrompt() {
    $config = $this->configFactory->get('ai_conversation.settings');
    $prompt = $config->get('system_prompt');
    
    // If no prompt configured, return default
    if (empty($prompt)) {
      $this->logger->warning('No system prompt found in configuration, using default');
      return $this->getBaseSystemPrompt();
    }
    
    return $prompt;
  }

}
