<?php

namespace Drupal\ai_conversation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\ai_conversation\Service\AIApiService;
use Drupal\Core\Access\AccessResult;

/**
 * Controller for AI conversation chat interface with rolling summary support.
 */
class ChatController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiService
   */
  protected $aiApiService;

  /**
   * Constructs a new ChatController object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, AIApiService $ai_api_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->aiApiService = $ai_api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('ai_conversation.ai_api_service')
    );
  }

  /**
   * Access callback for chat interface.
   */
  public function chatAccess(NodeInterface $node, AccountInterface $account) {
    // Check if the node is a conversation and the user owns it or is admin.
    if ($node->bundle() !== 'ai_conversation') {
      return AccessResult::forbidden();
    }

    if ($node->getOwnerId() === $account->id() || $account->hasPermission('administer content')) {
      return AccessResult::allowed();
    }
    
    return AccessResult::forbidden();
  }

  /**
   * Chat interface page.
   */
  public function chatInterface(NodeInterface $node) {
    // Verify access.
    $access = $this->chatAccess($node, $this->currentUser);
    if (!$access->isAllowed()) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }

    // Get conversation messages (only recent ones for display).
    $messages = $this->getRecentMessagesForDisplay($node);

    // Get conversation statistics.
    $stats = $this->aiApiService->getConversationStats($node);

    $build = [
      '#theme' => 'ai_conversation_chat',
      '#conversation' => $node,
      '#messages' => $messages,
      '#stats' => $stats,
      '#attached' => [
        'library' => [
          'ai_conversation/chat-interface',
        ],
        'drupalSettings' => [
          'aiConversation' => [
            'nodeId' => $node->id(),
            'sendMessageUrl' => '/ai-conversation/send-message',
            'statsUrl' => '/ai-conversation/stats',
            'csrfToken' => \Drupal::csrfToken()->get('ai_conversation_send_message'),
            'stats' => $stats,
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Get recent messages for display purposes.
   */
  private function getRecentMessagesForDisplay(NodeInterface $node) {
    $messages = [];
    
    if ($node->hasField('field_messages') && !$node->get('field_messages')->isEmpty()) {
      $all_messages = [];
      foreach ($node->get('field_messages') as $message_item) {
        $message_data = json_decode($message_item->value, TRUE);
        if ($message_data && isset($message_data['role']) && isset($message_data['content'])) {
          $all_messages[] = $message_data;
        }
      }

      // Sort by timestamp and return all (since we're now only storing recent ones).
      usort($all_messages, function($a, $b) {
        $a_time = $a['timestamp'] ?? 0;
        $b_time = $b['timestamp'] ?? 0;
        return $a_time - $b_time;
      });

      $messages = $all_messages;
    }

    return $messages;
  }

  /**
    * Start Game Master chat - handles smart user redirect logic.
   *
   * For anonymous users: redirects to registration with destination parameter
   * For authenticated users: creates new conversation and redirects to chat
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function startChat() {
    // Check if user is logged in
    if ($this->currentUser->isAnonymous()) {
      // Redirect anonymous users to registration with destination
      $url = \Drupal\Core\Url::fromRoute('user.register', [], [
        'query' => ['destination' => '/ai-chat']
      ]);
      return new \Symfony\Component\HttpFoundation\RedirectResponse($url->toString());
    }

    try {
      // Create new AI conversation node for authenticated user
      $conversation = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'ai_conversation',
        'title' => 'Forseti GM Session - ' . date('Y-m-d H:i:s'),
        'uid' => $this->currentUser->id(),
        'status' => 1,
        'field_context' => [
          'value' => 'You are Forseti, the Game Master of the Dungeoncrawler universe. Guide players with immersive narration, tactical clarity, fair rulings, and consistent world logic.',
          'format' => 'basic_html'
        ],
        'field_message_count' => ['value' => 0],
        'field_total_tokens' => ['value' => 0],
      ]);
      
      $conversation->save();

      // Redirect to the chat interface
      $chat_url = \Drupal\Core\Url::fromRoute('ai_conversation.chat_interface', [
        'node' => $conversation->id()
      ]);
      
      $this->messenger()->addStatus($this->t('New Forseti Game Master session started successfully!'));
      
      return new \Symfony\Component\HttpFoundation\RedirectResponse($chat_url->toString());
      
    } catch (\Exception $e) {
      // Log error and show user-friendly message
      \Drupal::logger('ai_conversation')->error('Error creating new conversation: @error', [
        '@error' => $e->getMessage()
      ]);
      
      $this->messenger()->addError($this->t('Unable to start new conversation. Please try again.'));
      
      // Fallback to home page
      $home_url = \Drupal\Core\Url::fromRoute('<front>');
      return new \Symfony\Component\HttpFoundation\RedirectResponse($home_url->toString());
    }
  }

  /**
   * Send message endpoint.
   */
  public function sendMessage(Request $request) {
    // Verify CSRF token.
    $token = $request->request->get('csrf_token');
    if (!\Drupal::csrfToken()->validate($token, 'ai_conversation_send_message')) {
      return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
    }

    $node_id = $request->request->get('node_id');
    $message = $request->request->get('message');

    if (!$node_id || !$message) {
      return new JsonResponse(['error' => 'Missing required parameters'], 400);
    }

    // Load the conversation node.
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$node || $node->bundle() !== 'ai_conversation') {
      return new JsonResponse(['error' => 'Invalid conversation'], 400);
    }

    // Check access.
    $access = $this->chatAccess($node, $this->currentUser);
    if (!$access->isAllowed()) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    try {
      // Add user message to conversation.
      $user_message = [
        'role' => 'user',
        'content' => $message,
        'timestamp' => time(),
      ];
      
      $this->addMessageToNode($node, $user_message);
      
      // IMPORTANT: Save the node after adding user message
      // This ensures the message count is in the database before summary check
      $node->save();
      
      // Get AI response (this will handle summary generation if needed).
      $ai_response = $this->aiApiService->sendMessage($node, $message);

      // Parse for suggestion creation tags.
      $suggestion_created = FALSE;
      if (preg_match('/\[CREATE_SUGGESTION\](.*?)\[\/CREATE_SUGGESTION\]/s', $ai_response, $matches)) {
        // Extract the suggestion data.
        $suggestion_text = $matches[1];
        
        // Parse Summary, Category, and Original fields.
        $summary = '';
        $category = 'general_feedback';
        $original = $message;
        
        if (preg_match('/Summary:\s*(.+?)(?=\nCategory:|$)/s', $suggestion_text, $summary_match)) {
          $summary = trim($summary_match[1]);
        }
        
        if (preg_match('/Category:\s*(\w+)/i', $suggestion_text, $category_match)) {
          $category = strtolower(trim($category_match[1]));
        }
        
        if (preg_match('/Original:\s*(.+?)$/s', $suggestion_text, $original_match)) {
          $original = trim($original_match[1]);
        }
        
        // Create the suggestion node.
        if (!empty($summary)) {
          $suggestion = $this->aiApiService->createSuggestion($node, $summary, $original, $category);
          if ($suggestion) {
            $suggestion_created = TRUE;
            \Drupal::logger('ai_conversation')->info('Created suggestion nid @nid from conversation nid @conv_nid', [
              '@nid' => $suggestion->id(),
              '@conv_nid' => $node->id(),
            ]);
          }
        }
        
        // Remove the tag from the AI response to clean it up for display.
        $ai_response = preg_replace('/\[CREATE_SUGGESTION\].*?\[\/CREATE_SUGGESTION\]/s', '', $ai_response);
        $ai_response = trim($ai_response);
      }

      // Add AI response to conversation.
      $ai_message = [
        'role' => 'assistant',
        'content' => $ai_response,
        'timestamp' => time(),
      ];
      
      $this->addMessageToNode($node, $ai_message);

      // Save the node.
      $node->save();

      // Get updated stats.
      $stats = $this->aiApiService->getConversationStats($node);

      return new JsonResponse([
        'success' => TRUE,
        'response' => $ai_response,
        'user_message' => $user_message,
        'ai_message' => $ai_message,
        'stats' => $stats,
        'suggestion_created' => $suggestion_created,
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('ai_conversation')->error('Error sending message: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Failed to send message: ' . $e->getMessage()], 500);
    }
  }

  /**
   * Get conversation statistics endpoint.
   */
  public function getStats(Request $request) {
    $node_id = $request->query->get('node_id');
    
    if (!$node_id) {
      return new JsonResponse(['error' => 'Missing node ID'], 400);
    }

    // Load the conversation node.
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$node || $node->bundle() !== 'ai_conversation') {
      return new JsonResponse(['error' => 'Invalid conversation'], 400);
    }

    // Check access.
    $access = $this->chatAccess($node, $this->currentUser);
    if (!$access->isAllowed()) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    $stats = $this->aiApiService->getConversationStats($node);
    
    return new JsonResponse(['stats' => $stats]);
  }

  /**
   * Add a message to the conversation node and update message count.
   */
  private function addMessageToNode(NodeInterface $node, array $message) {
    // Add the message to the field.
    $messages = $node->get('field_messages')->getValue();
    $messages[] = ['value' => json_encode($message)];
    $node->set('field_messages', $messages);

    // Update message count.
    $current_count = $node->get('field_message_count')->value ?: 0;
    $node->set('field_message_count', $current_count + 1);

    // Log the message addition.
    \Drupal::logger('ai_conversation')->info('Added message to conversation @nid. Total messages: @count', [
      '@nid' => $node->id(),
      '@count' => $current_count + 1,
    ]);
  }

  /**
   * Manually trigger summary update (for testing/admin purposes).
   */
  public function triggerSummaryUpdate(NodeInterface $node) {
    // Verify access.
    $access = $this->chatAccess($node, $this->currentUser);
    if (!$access->isAllowed()) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    try {
      // Force summary update by calling the private method via reflection.
      $reflection = new \ReflectionClass($this->aiApiService);
      $method = $reflection->getMethod('updateConversationSummary');
      $method->setAccessible(true);
      $method->invoke($this->aiApiService, $node);

      $node->save();

      $stats = $this->aiApiService->getConversationStats($node);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Summary updated successfully',
        'stats' => $stats,
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('ai_conversation')->error('Error updating summary: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Failed to update summary: ' . $e->getMessage()], 500);
    }
  }

  /**
  * Create a new GM conversation node and redirect to chat interface.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect to the chat interface for the newly created node.
   */
  public function claudeDemo() {
    try {
      // Create a new AI conversation node for the current user
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'ai_conversation',
        'title' => 'Forseti GM Session - ' . date('Y-m-d H:i:s'),
        'uid' => $this->currentUser->id(),
        'status' => 1,
        'field_conversation_data' => [
          'value' => json_encode([
            'messages' => [],
            'summary' => '',
            'created' => time(),
          ]),
          'format' => 'plain_text',
        ],
      ]);
      
      $node->save();

      // Redirect to the chat interface for this node
      return $this->redirect('ai_conversation.chat_interface', ['node' => $node->id()]);
      
    } catch (\Exception $e) {
      // Log error and show user-friendly message
      \Drupal::logger('ai_conversation')->error('Error creating Claude demo chat: @error', ['@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Unable to create chat session. Please try again.'));
      
      // Redirect to home page on error
      return $this->redirect('<front>');
    }
  }

}