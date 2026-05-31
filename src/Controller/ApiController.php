<?php

namespace Drupal\ai_conversation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_conversation\Service\AIApiService;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * API Controller for AI Conversation REST endpoints.
 */
class ApiController extends ControllerBase {

  /**
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiService
   */
  protected $aiApiService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs an ApiController object.
   */
  public function __construct(AIApiService $ai_api_service, AccountProxyInterface $current_user) {
    $this->aiApiService = $ai_api_service;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_conversation.ai_api'),
      $container->get('current_user')
    );
  }

  /**
   * Create a new AI conversation.
   *
   * POST /api/ai-conversation/create
   */
  public function createConversation(Request $request) {
    // Check authentication.
    if ($this->currentUser->isAnonymous()) {
      return new JsonResponse([
        'error' => 'Authentication required',
        'message' => 'You must be logged in to create a conversation'
      ], 401);
    }

    try {
      $data = json_decode($request->getContent(), TRUE);
      
      $title = $data['title'] ?? 'Conversation with Forseti - ' . date('Y-m-d H:i:s');
      $ai_model = $data['ai_model'] ?? 'anthropic.claude-3-5-sonnet-20241022-v2:0';

      // Create conversation node.
      $conversation = Node::create([
        'type' => 'ai_conversation',
        'title' => $title,
        'uid' => $this->currentUser->id(),
        'field_ai_model' => $ai_model,
        'field_messages' => [],
        'field_conversation_summary' => '',
        'field_summary_message_count' => 0,
        'field_token_count' => 0,
      ]);
      $conversation->save();

      return new JsonResponse([
        'conversation_id' => (int) $conversation->id(),
        'title' => $conversation->getTitle(),
        'created' => $conversation->getCreatedTime(),
        'ai_model' => $ai_model
      ], 201);

    } catch (\Exception $e) {
      \Drupal::logger('ai_conversation_api')->error('Error creating conversation: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to create conversation',
        'message' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * Send a message to a conversation.
   *
   * POST /api/ai-conversation/{conversation_id}/message
   */
  public function sendMessage(Request $request, $conversation_id) {
    // Check authentication.
    if ($this->currentUser->isAnonymous()) {
      return new JsonResponse([
        'error' => 'Authentication required',
        'message' => 'You must be logged in to send messages'
      ], 401);
    }

    try {
      $conversation = Node::load($conversation_id);
      
      if (!$conversation || $conversation->bundle() !== 'ai_conversation') {
        return new JsonResponse([
          'error' => 'Conversation not found',
          'message' => 'The specified conversation does not exist'
        ], 404);
      }

      // Check access.
      if (!$conversation->access('view', $this->currentUser)) {
        return new JsonResponse([
          'error' => 'Access denied',
          'message' => 'You do not have permission to access this conversation'
        ], 403);
      }

      $data = json_decode($request->getContent(), TRUE);
      $user_message = $data['message'] ?? '';

      if (empty(trim($user_message))) {
        return new JsonResponse([
          'error' => 'Invalid input',
          'message' => 'Message cannot be empty'
        ], 400);
      }

      // Get AI response.
      $ai_response = $this->aiApiService->sendMessage($conversation, $user_message);

      // Check for suggestion creation tag.
      $suggestion_created = FALSE;
      if (preg_match('/\[CREATE_SUGGESTION\](.*?)\[\/CREATE_SUGGESTION\]/s', $ai_response, $matches)) {
        $suggestion_text = $matches[1];

        $summary = '';
        $category = 'general_feedback';
        $original = $user_message;

        if (preg_match('/Summary:\s*(.+?)(?=\nCategory:|$)/s', $suggestion_text, $summary_match)) {
          $summary = trim($summary_match[1]);
        }

        if (preg_match('/Category:\s*(\w+)/i', $suggestion_text, $category_match)) {
          $category = strtolower(trim($category_match[1]));
        }

        if (preg_match('/Original:\s*(.+?)$/s', $suggestion_text, $original_match)) {
          $original = trim($original_match[1]);
        }

        if (!empty($summary)) {
          $suggestion = $this->aiApiService->createSuggestion($conversation, $summary, $original, $category);
          $suggestion_created = (bool) $suggestion;
        }

        $ai_response = preg_replace('/\[CREATE_SUGGESTION\].*?\[\/CREATE_SUGGESTION\]/s', '', $ai_response);
        $ai_response = trim($ai_response);
      }

      return new JsonResponse([
        'response' => $ai_response,
        'conversation_id' => (int) $conversation_id,
        'suggestion_created' => $suggestion_created,
        'timestamp' => time()
      ], 200);

    } catch (\Exception $e) {
      \Drupal::logger('ai_conversation_api')->error('Error sending message: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to send message',
        'message' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * Get conversation history (messages).
   *
   * GET /api/ai-conversation/{conversation_id}/history
   */
  public function getHistory(Request $request, $conversation_id) {
    // Check authentication.
    if ($this->currentUser->isAnonymous()) {
      return new JsonResponse([
        'error' => 'Authentication required',
        'message' => 'You must be logged in to view conversation history'
      ], 401);
    }

    try {
      $conversation = Node::load($conversation_id);
      
      if (!$conversation || $conversation->bundle() !== 'ai_conversation') {
        return new JsonResponse([
          'error' => 'Conversation not found',
          'message' => 'The specified conversation does not exist'
        ], 404);
      }

      // Check access.
      if (!$conversation->access('view', $this->currentUser)) {
        return new JsonResponse([
          'error' => 'Access denied',
          'message' => 'You do not have permission to access this conversation'
        ], 403);
      }

      $messages = [];
      if ($conversation->hasField('field_messages') && !$conversation->get('field_messages')->isEmpty()) {
        foreach ($conversation->get('field_messages') as $message_item) {
          $message_data = json_decode($message_item->value, TRUE);
          if ($message_data) {
            $messages[] = $message_data;
          }
        }
      }

      return new JsonResponse([
        'conversation_id' => (int) $conversation_id,
        'title' => $conversation->getTitle(),
        'messages' => $messages,
        'message_count' => count($messages)
      ], 200);

    } catch (\Exception $e) {
      \Drupal::logger('ai_conversation_api')->error('Error getting history: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to get conversation history',
        'message' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * Get list of user's conversations.
   *
   * GET /api/ai-conversation/conversations
   */
  public function getUserConversations(Request $request) {
    // Check authentication.
    if ($this->currentUser->isAnonymous()) {
      return new JsonResponse([
        'error' => 'Authentication required',
        'message' => 'You must be logged in to view conversations'
      ], 401);
    }

    try {
      $query = \Drupal::entityQuery('node')
        ->condition('type', 'ai_conversation')
        ->condition('uid', $this->currentUser->id())
        ->sort('created', 'DESC')
        ->accessCheck(TRUE);

      $nids = $query->execute();
      $conversations = [];

      if (!empty($nids)) {
        $nodes = Node::loadMultiple($nids);
        foreach ($nodes as $node) {
          $messages = [];
          if ($node->hasField('field_messages') && !$node->get('field_messages')->isEmpty()) {
            foreach ($node->get('field_messages') as $message_item) {
              $message_data = json_decode($message_item->value, TRUE);
              if ($message_data) {
                $messages[] = $message_data;
              }
            }
          }

          $last_message = '';
          if (!empty($messages)) {
            $last_msg = end($messages);
            $last_message = $last_msg['content'] ?? '';
          }

          $conversations[] = [
            'conversation_id' => (int) $node->id(),
            'title' => $node->getTitle(),
            'created_at' => date('c', $node->getCreatedTime()),
            'message_count' => count($messages),
            'last_message' => $last_message
          ];
        }
      }

      return new JsonResponse([
        'conversations' => $conversations,
        'total' => count($conversations)
      ], 200);

    } catch (\Exception $e) {
      \Drupal::logger('ai_conversation_api')->error('Error getting conversations: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to get conversations',
        'message' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * Delete a conversation.
   *
   * DELETE /api/ai-conversation/{conversation_id}
   */
  public function deleteConversation(Request $request, $conversation_id) {
    // Check authentication.
    if ($this->currentUser->isAnonymous()) {
      return new JsonResponse([
        'error' => 'Authentication required',
        'message' => 'You must be logged in to delete conversations'
      ], 401);
    }

    try {
      $conversation = Node::load($conversation_id);
      
      if (!$conversation || $conversation->bundle() !== 'ai_conversation') {
        return new JsonResponse([
          'error' => 'Conversation not found',
          'message' => 'The specified conversation does not exist'
        ], 404);
      }

      // Check ownership.
      if ($conversation->getOwnerId() != $this->currentUser->id()) {
        return new JsonResponse([
          'error' => 'Access denied',
          'message' => 'You can only delete your own conversations'
        ], 403);
      }

      $conversation->delete();

      return new JsonResponse([
        'message' => 'Conversation deleted successfully',
        'conversation_id' => (int) $conversation_id
      ], 200);

    } catch (\Exception $e) {
      \Drupal::logger('ai_conversation_api')->error('Error deleting conversation: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to delete conversation',
        'message' => $e->getMessage()
      ], 500);
    }
  }

  /**
   * Get conversation statistics.
   *
   * GET /api/ai-conversation/{conversation_id}/stats
   */
  public function getConversationStats(Request $request, $conversation_id) {
    // Check authentication.
    if ($this->currentUser->isAnonymous()) {
      return new JsonResponse([
        'error' => 'Authentication required',
        'message' => 'You must be logged in to view statistics'
      ], 401);
    }

    try {
      $conversation = Node::load($conversation_id);
      
      if (!$conversation || $conversation->bundle() !== 'ai_conversation') {
        return new JsonResponse([
          'error' => 'Conversation not found',
          'message' => 'The specified conversation does not exist'
        ], 404);
      }

      // Check access.
      if (!$conversation->access('view', $this->currentUser)) {
        return new JsonResponse([
          'error' => 'Access denied',
          'message' => 'You do not have permission to access this conversation'
        ], 403);
      }

      $message_count = 0;
      if ($conversation->hasField('field_messages') && !$conversation->get('field_messages')->isEmpty()) {
        $message_count = $conversation->get('field_messages')->count();
      }

      $token_count = 0;
      if ($conversation->hasField('field_token_count') && !$conversation->get('field_token_count')->isEmpty()) {
        $token_count = $conversation->get('field_token_count')->value;
      }

      return new JsonResponse([
        'conversation_id' => (int) $conversation_id,
        'message_count' => $message_count,
        'token_count' => $token_count,
        'created' => date('c', $conversation->getCreatedTime()),
        'updated' => date('c', $conversation->getChangedTime())
      ], 200);

    } catch (\Exception $e) {
      \Drupal::logger('ai_conversation_api')->error('Error getting stats: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to get conversation statistics',
        'message' => $e->getMessage()
      ], 500);
    }
  }

}
