<?php

namespace Drupal\ai_conversation\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Provides a navigation block for AI Conversation utilities.
 *
 * @Block(
 *   id = "ai_conversation_nav_block",
 *   admin_label = @Translation("AI Conversation Navigation")
 * )
 */
class AiConversationNavBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $items = [];

    $links = [
      'usage' => [
        'title' => $this->t('GenAI API Usage & Costs'),
        'route' => 'ai_conversation.usage_dashboard',
      ],
      'settings' => [
        'title' => $this->t('AI Conversation Settings'),
        'route' => 'ai_conversation.settings',
      ],
      'prompt' => [
        'title' => $this->t('Update System Prompt'),
        'route' => 'ai_conversation.update_prompt',
      ],
      'node10' => [
        'title' => $this->t('Fetch Node 10 Content'),
        'route' => 'ai_conversation.get_node10',
      ],
      'chat' => [
        'title' => $this->t('Start AI Chat'),
        'route' => 'ai_conversation.start_chat',
      ],
      'claude_demo' => [
        'title' => $this->t('Claude Demo'),
        'route' => 'ai_conversation.claude_demo',
      ],
    ];

    foreach ($links as $link) {
      $items[] = Link::fromTextAndUrl($link['title'], Url::fromRoute($link['route']))->toRenderable();
    }

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('AI Conversation Navigation'),
      '#items' => $items,
      '#attributes' => ['class' => ['ai-conversation-nav-block']],
    ];
  }

}
