<?php

namespace Drupal\ai_conversation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_conversation\Service\PromptManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for AI conversation administration tasks.
 */
class AdminController extends ControllerBase {

  /**
   * The prompt manager service.
   *
   * @var \Drupal\ai_conversation\Service\PromptManager
   */
  protected $promptManager;

  /**
   * Constructs an AdminController object.
   *
   * @param \Drupal\ai_conversation\Service\PromptManager $prompt_manager
   *   The prompt manager service.
   */
  public function __construct(PromptManager $prompt_manager) {
    $this->promptManager = $prompt_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_conversation.prompt_manager')
    );
  }

  /**
   * Force update the system prompt configuration.
   */
  public function updateSystemPrompt() {
    // Get the default Forseti Game Master system prompt from PromptManager
    $system_prompt = $this->promptManager->getBaseSystemPrompt();
    
    // Save using PromptManager
    $success = $this->promptManager->saveSystemPrompt($system_prompt);
    
    return new JsonResponse([
      'success' => $success,
      'message' => $success ? 'Forseti Game Master system prompt updated successfully' : 'Failed to update system prompt',
      'prompt_length' => strlen($system_prompt),
    ]);
  }

}