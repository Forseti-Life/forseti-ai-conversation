<?php

namespace Drupal\ai_conversation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for suggestion tracking dashboard.
 */
class SuggestionTrackingController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a SuggestionTrackingController object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * Builds the suggestion tracking page.
   */
  public function page() {
    $build = [];

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'community_suggestion')
      ->sort('created', 'DESC')
      ->range(0, 200)
      ->accessCheck(FALSE);
    $nids = $query->execute();

    $status_counts = [
      'new' => 0,
      'under_review' => 0,
      'in_progress' => 0,
      'deferred' => 0,
      'declined' => 0,
      'implemented' => 0,
      'other' => 0,
    ];

    $rows = [];
    if (!empty($nids)) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

      foreach ($nodes as $node) {
        $status = (string) ($node->get('field_suggestion_status')->value ?? '');
        $category = (string) ($node->get('field_suggestion_category')->value ?? '');
        $conversation_nid = $node->get('field_conversation_reference')->target_id ?? NULL;

        if (isset($status_counts[$status])) {
          $status_counts[$status]++;
        }
        else {
          $status_counts['other']++;
        }

        $node_link = Link::fromTextAndUrl(
          '#' . $node->id(),
          Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])
        )->toString();

        $conversation_link = '-';
        if ($conversation_nid) {
          $conversation_link = Link::fromTextAndUrl(
            'Conversation ' . $conversation_nid,
            Url::fromRoute('entity.node.canonical', ['node' => $conversation_nid])
          )->toString();
        }

        $rows[] = [
          'id' => [
            'data' => [
              '#markup' => $node_link,
            ],
          ],
          'created' => $this->dateFormatter->format($node->getCreatedTime(), 'custom', 'Y-m-d H:i:s'),
          'status' => $status ?: '-',
          'category' => $category ?: '-',
          'title' => $node->label(),
          'conversation' => [
            'data' => [
              '#markup' => $conversation_link,
            ],
          ],
        ];
      }
    }

    $build['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Status summary'),
      '#markup' => $this->t(
        'New: @new | Under review: @under_review | In progress: @in_progress | Deferred: @deferred | Declined: @declined | Implemented: @implemented | Other: @other',
        [
          '@new' => $status_counts['new'],
          '@under_review' => $status_counts['under_review'],
          '@in_progress' => $status_counts['in_progress'],
          '@deferred' => $status_counts['deferred'],
          '@declined' => $status_counts['declined'],
          '@implemented' => $status_counts['implemented'],
          '@other' => $status_counts['other'],
        ]
      ),
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('ID'),
        $this->t('Created'),
        $this->t('Status'),
        $this->t('Category'),
        $this->t('Title'),
        $this->t('Conversation'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No suggestions found.'),
      '#sticky' => TRUE,
    ];

    return $build;
  }

}
