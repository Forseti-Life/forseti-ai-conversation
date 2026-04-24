<?php

namespace Drupal\ai_conversation\Service;

use Drupal\Core\Database\Connection;

/**
 * Storage service for AI conversation API usage data.
 *
 * Encapsulates all direct database access for the ai_conversation_api_usage
 * table, allowing AIApiService to remain free of \Drupal::database() calls.
 */
class AIConversationStorageService {

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Check whether a field exists in the ai_conversation_api_usage table.
   *
   * Used for schema guards to safely skip optional columns that may not yet
   * exist on older installations.
   *
   * @param string $field_name
   *   Column name to check.
   *
   * @return bool
   *   TRUE if the field exists.
   */
  public function usageTableHasField(string $field_name): bool {
    return $this->database->schema()->fieldExists('ai_conversation_api_usage', $field_name);
  }

  /**
   * Insert a usage record into ai_conversation_api_usage.
   *
   * @param array $fields
   *   Field values keyed by column name.
   */
  public function insertUsageRecord(array $fields): void {
    $this->database->insert('ai_conversation_api_usage')
      ->fields($fields)
      ->execute();
  }

  /**
   * Find a cached successful API response matching the given context.
   *
   * Returns the most recent successful record for the module/operation/context
   * combination, or NULL when no matching record exists or when optional schema
   * fields required for caching are absent.
   *
   * @param string $module
   *   Module name (e.g., 'job_hunter').
   * @param string $operation
   *   Operation type (e.g., 'resume_tailoring').
   * @param array $context_data
   *   Key/value pairs matched via JSON_EXTRACT against the context_data column.
   *
   * @return array|null
   *   Response data array or NULL if not found.
   */
  public function findCachedResponse(string $module, string $operation, array $context_data): ?array {
    $schema = $this->database->schema();

    if (!$schema->fieldExists('ai_conversation_api_usage', 'response_preview') ||
        !$schema->fieldExists('ai_conversation_api_usage', 'success')) {
      return NULL;
    }

    $query = $this->database->select('ai_conversation_api_usage', 'u')
      ->fields('u', ['response_preview', 'stop_reason', 'timestamp', 'input_tokens', 'output_tokens'])
      ->condition('module', $module)
      ->condition('operation', $operation)
      ->condition('success', 1)
      ->orderBy('timestamp', 'DESC')
      ->range(0, 1);

    foreach ($context_data as $key => $value) {
      $query->where("JSON_EXTRACT(context_data, '$.$key') = :value_$key", [":value_$key" => $value]);
    }

    $result = $query->execute()->fetchAssoc();

    if ($result && !empty($result['response_preview'])) {
      return [
        'response' => $result['response_preview'],
        'stop_reason' => $result['stop_reason'],
        'timestamp' => $result['timestamp'],
        'input_tokens' => $result['input_tokens'],
        'output_tokens' => $result['output_tokens'],
      ];
    }

    return NULL;
  }

  /**
   * Delete cached API responses matching the given context.
   *
   * @param string $module
   *   Module name.
   * @param string $operation
   *   Operation type.
   * @param array $context_data
   *   Key/value pairs matched via JSON_EXTRACT against the context_data column.
   *
   * @return int
   *   Number of rows deleted.
   */
  public function deleteCachedResponses(string $module, string $operation, array $context_data): int {
    $query = $this->database->delete('ai_conversation_api_usage')
      ->condition('module', $module)
      ->condition('operation', $operation);

    foreach ($context_data as $key => $value) {
      $query->where("JSON_EXTRACT(context_data, '$.$key') = :value_$key", [":value_$key" => $value]);
    }

    return (int) $query->execute();
  }

}
