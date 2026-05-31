<?php

namespace Drupal\ai_conversation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Utility controller to fetch node content.
 */
class UtilityController extends ControllerBase {

  /**
    * Get content from node 10 (Forseti world context information).
   */
  public function getNode10Content() {
    $content = "Node not found";
    
    try {
      $node = $this->entityTypeManager()->getStorage('node')->load(10);
      if ($node && $node->access('view')) {
        $title = $node->getTitle();
        $body = '';
        
        if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
          $body = strip_tags($node->get('body')->value);
        }
        
        return new JsonResponse([
          'title' => $title,
          'body' => $body,
          'success' => TRUE,
        ]);
      }
    } catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'success' => FALSE,
      ]);
    }
    
    return new JsonResponse([
      'error' => 'Node 10 not found or not accessible',
      'success' => FALSE,
    ]);
  }

}