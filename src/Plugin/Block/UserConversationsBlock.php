<?php

namespace Drupal\ai_conversation\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'User AI Conversations' Block.
 *
 * @Block(
 *   id = "user_ai_conversations",
 *   admin_label = @Translation("User AI Conversations"),
 *   category = @Translation("AI Conversation")
 * )
 */
class UserConversationsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * Constructs a new UserConversationsBlock instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];

    // Only show for authenticated users
    if ($this->currentUser->isAnonymous()) {
      return $build;
    }

    // Get user's AI conversation nodes
    $conversations = $this->getUserConversations();

    if (empty($conversations)) {
      $build = [
        '#type' => 'markup',
        '#markup' => '<div class="user-conversations-empty">' . $this->t('No previous conversations found. <a href="@create_url">Start a new conversation</a>?', [
          '@create_url' => '/clauddemo'
        ]) . '</div>',
        '#attached' => [
          'library' => [
            'ai_conversation/user-conversations-block',
          ],
        ],
      ];
      return $build;
    }

    $build = [
      '#theme' => 'user_ai_conversations',
      '#conversations' => $conversations,
      '#total' => count($conversations),
      '#attached' => [
        'library' => [
          'ai_conversation/user-conversations-block',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Get the current user's AI conversation nodes.
   *
   * @return array
   *   Array of conversation data.
   */
  protected function getUserConversations() {
    $conversations = [];

    try {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'ai_conversation')
        ->condition('uid', $this->currentUser->id())
        ->condition('status', 1)
        ->sort('changed', 'DESC')
        ->range(0, 10) // Limit to 10 most recent conversations
        ->accessCheck(TRUE);

      $nids = $query->execute();

      if (!empty($nids)) {
        $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

        foreach ($nodes as $node) {
          $message_count = $node->get('field_message_count')->value ?: 0;
          $total_tokens = $node->get('field_total_tokens')->value ?: 0;
          
          // Get the first few words of the latest message for preview
          $preview = $this->getConversationPreview($node);
          
          $conversations[] = [
            'nid' => $node->id(),
            'title' => $node->getTitle(),
            'changed' => $node->getChangedTime(),
            'message_count' => $message_count,
            'total_tokens' => $total_tokens,
            'preview' => $preview,
            'chat_url' => Url::fromRoute('ai_conversation.chat_interface', ['node' => $node->id()])->toString(),
            'view_url' => $node->toUrl()->toString(),
          ];
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('ai_conversation')->error('Error loading user conversations: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $conversations;
  }

  /**
   * Get a preview of the conversation (first user message or recent activity).
   *
   * @param \Drupal\node\NodeInterface $node
   *   The conversation node.
   *
   * @return string
   *   Preview text.
   */
  protected function getConversationPreview($node) {
    $preview = $this->t('New conversation');

    try {
      if ($node->hasField('field_messages') && !$node->get('field_messages')->isEmpty()) {
        // Get the first message for preview
        $first_message = $node->get('field_messages')->first();
        if ($first_message) {
          $message_data = json_decode($first_message->value, TRUE);
          if ($message_data && isset($message_data['content'])) {
            $content = strip_tags($message_data['content']);
            $preview = strlen($content) > 80 ? substr($content, 0, 80) . '...' : $content;
          }
        }
      }
    } catch (\Exception $e) {
      // Return default preview on error
    }

    return $preview;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // Cache tags to invalidate when user's nodes change
    $tags = parent::getCacheTags();
    $tags[] = 'node_list:ai_conversation';
    $tags[] = 'user:' . $this->currentUser->id();
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // Cache per user
    return Cache::mergeContexts(parent::getCacheContexts(), ['user']);
  }

}