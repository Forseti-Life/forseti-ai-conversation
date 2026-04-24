<?php

namespace Drupal\ai_conversation\Commands;

use Drupal\Component\Serialization\Json;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for debugging AI API calls.
 */
class AiDebugCommands extends DrushCommands {

  /**
   * List recent AI API failures for troubleshooting.
   *
   * @param array $options
   *   Options array.
   *
   * @option hours
   *   Only show failures from the last N hours (default: 24).
   * @option module
   *   Filter by module name (e.g., job_hunter, ai_conversation).
   * @option operation
   *   Filter by operation type (e.g., resume_tailoring, chat_message).
   * @option limit
   *   Maximum number of results to show (default: 20).
   * @option verbose
   *   Show full error messages and context.
   *
   * @command ai:failures
   * @aliases ai-failures
   * @usage ai:failures
   *   Show AI failures from the last 24 hours.
   * @usage ai:failures --hours=4 --module=job_hunter
   *   Show job_hunter failures from last 4 hours.
   * @usage ai:failures --operation=resume_tailoring --verbose
   *   Show resume tailoring failures with full details.
   */
  public function listFailures(array $options = [
    'hours' => 24,
    'module' => NULL,
    'operation' => NULL,
    'limit' => 20,
    'verbose' => FALSE,
  ]) {
    $database = \Drupal::database();
    
    // Build query
    $query = $database->select('ai_conversation_api_usage', 'u')
      ->fields('u')
      ->condition('success', 0)
      ->orderBy('timestamp', 'DESC')
      ->range(0, $options['limit']);
    
    // Add time filter
    if ($options['hours']) {
      $cutoff = time() - ($options['hours'] * 3600);
      $query->condition('timestamp', $cutoff, '>=');
    }
    
    // Add optional filters
    if ($options['module']) {
      $query->condition('module', $options['module']);
    }
    if ($options['operation']) {
      $query->condition('operation', $options['operation']);
    }
    
    $results = $query->execute()->fetchAll();
    
    if (empty($results)) {
      $this->output()->writeln('<info>✓ No AI failures found matching criteria.</info>');
      return;
    }
    
    $this->output()->writeln('<error>Found ' . count($results) . ' AI failures:</error>');
    $this->output()->writeln('');
    
    foreach ($results as $row) {
      $time = date('Y-m-d H:i:s', $row->timestamp);
      $this->output()->writeln("<comment>[$time] {$row->module}/{$row->operation}</comment>");
      $this->output()->writeln("  Error: <error>{$row->error_message}</error>");
      
      if ($options['verbose']) {
        $this->output()->writeln("  User ID: {$row->uid}");
        $this->output()->writeln("  Model: {$row->model_id}");
        $this->output()->writeln("  Duration: {$row->duration_ms}ms");
        
        if ($row->context_data) {
          $context = Json::decode($row->context_data);
          $this->output()->writeln("  Context: " . Json::encode($context));
        }
        
        if ($row->prompt_preview) {
          $prompt_len = strlen($row->prompt_preview);
          $this->output()->writeln("  Prompt: {$prompt_len} chars");
          $this->output()->writeln("    " . substr($row->prompt_preview, 0, 200) . "...");
        }
      }
      
      $this->output()->writeln('');
    }
  }

  /**
   * Show AI API usage statistics.
   *
   * @param array $options
   *   Options array.
   *
   * @option hours
   *   Show stats from the last N hours (default: 24).
   * @option module
   *   Filter by module name.
   * @option operation
   *   Filter by operation type.
   *
   * @command ai:stats
   * @aliases ai-stats
   * @usage ai:stats
   *   Show AI usage stats from last 24 hours.
   * @usage ai:stats --hours=168 --module=job_hunter
   *   Show job_hunter stats from last week.
   */
  public function showStats(array $options = [
    'hours' => 24,
    'module' => NULL,
    'operation' => NULL,
  ]) {
    $database = \Drupal::database();
    
    // Build query
    $query = $database->select('ai_conversation_api_usage', 'u');
    
    // Add time filter
    if ($options['hours']) {
      $cutoff = time() - ($options['hours'] * 3600);
      $query->condition('timestamp', $cutoff, '>=');
    }
    
    // Add optional filters
    if ($options['module']) {
      $query->condition('module', $options['module']);
    }
    if ($options['operation']) {
      $query->condition('operation', $options['operation']);
    }
    
    // Get counts
    $total_calls = (clone $query)->countQuery()->execute()->fetchField();
    $failed_calls = (clone $query)->condition('success', 0)->countQuery()->execute()->fetchField();
    $success_calls = $total_calls - $failed_calls;
    
    // Get token stats
    $query->addExpression('SUM(input_tokens)', 'total_input');
    $query->addExpression('SUM(output_tokens)', 'total_output');
    $query->addExpression('SUM(estimated_cost)', 'total_cost');
    $query->addExpression('AVG(duration_ms)', 'avg_duration');
    $stats = $query->execute()->fetchAssoc();
    
    $this->output()->writeln('<info>AI API Usage Statistics</info>');
    $this->output()->writeln('');
    $this->output()->writeln("<comment>Total Calls:</comment> {$total_calls}");
    $this->output()->writeln("  <info>Success:</info> {$success_calls} (" . ($total_calls > 0 ? round($success_calls / $total_calls * 100, 1) : 0) . "%)");
    $this->output()->writeln("  <error>Failed:</error> {$failed_calls} (" . ($total_calls > 0 ? round($failed_calls / $total_calls * 100, 1) : 0) . "%)");
    $this->output()->writeln('');
    $this->output()->writeln("<comment>Token Usage:</comment>");
    $this->output()->writeln("  Input: " . number_format($stats['total_input']));
    $this->output()->writeln("  Output: " . number_format($stats['total_output']));
    $this->output()->writeln("  Total: " . number_format($stats['total_input'] + $stats['total_output']));
    $this->output()->writeln('');
    $this->output()->writeln("<comment>Cost:</comment> $" . number_format($stats['total_cost'], 4));
    $this->output()->writeln("<comment>Avg Duration:</comment> " . round($stats['avg_duration']) . "ms");
    $this->output()->writeln('');
    
    // Show breakdown by operation
    $by_operation = $database->select('ai_conversation_api_usage', 'u')
      ->fields('u', ['operation'])
      ->condition('timestamp', $cutoff, '>=');
    
    if ($options['module']) {
      $by_operation->condition('module', $options['module']);
    }
    
    $by_operation->addExpression('COUNT(*)', 'count');
    $by_operation->addExpression('SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END)', 'failures');
    $by_operation->addExpression('SUM(estimated_cost)', 'cost');
    $by_operation->groupBy('operation')
      ->orderBy('count', 'DESC');
    
    $operations = $by_operation->execute()->fetchAll();
    
    if (!empty($operations)) {
      $this->output()->writeln('<comment>By Operation:</comment>');
      foreach ($operations as $op) {
        $failure_pct = $op->count > 0 ? round($op->failures / $op->count * 100, 1) : 0;
        $this->output()->writeln("  {$op->operation}: {$op->count} calls, {$op->failures} failures ({$failure_pct}%), $" . number_format($op->cost, 4));
      }
    }
  }

  /**
   * Inspect a specific AI API call by showing full details.
   *
   * @param int $id
   *   The ID of the API call to inspect.
   *
   * @command ai:inspect
   * @aliases ai-inspect
   * @usage ai:inspect 42
   *   Show full details of API call #42.
   */
  public function inspectCall($id) {
    $database = \Drupal::database();
    
    $call = $database->select('ai_conversation_api_usage', 'u')
      ->fields('u')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();
    
    if (!$call) {
      $this->output()->writeln("<error>API call #{$id} not found.</error>");
      return;
    }
    
    $time = date('Y-m-d H:i:s', $call->timestamp);
    $status = $call->success ? '<info>SUCCESS</info>' : '<error>FAILED</error>';
    
    $this->output()->writeln("<comment>AI API Call #{$id}</comment>");
    $this->output()->writeln('');
    $this->output()->writeln("Status: {$status}");
    $this->output()->writeln("Timestamp: {$time}");
    $this->output()->writeln("User ID: {$call->uid}");
    $this->output()->writeln("Module: {$call->module}");
    $this->output()->writeln("Operation: {$call->operation}");
    $this->output()->writeln("Model: {$call->model_id}");
    $this->output()->writeln('');
    $this->output()->writeln("<comment>Performance:</comment>");
    $this->output()->writeln("  Duration: {$call->duration_ms}ms");
    $this->output()->writeln("  Stop Reason: {$call->stop_reason}");
    $this->output()->writeln('');
    $this->output()->writeln("<comment>Token Usage:</comment>");
    $this->output()->writeln("  Input: " . number_format($call->input_tokens));
    $this->output()->writeln("  Output: " . number_format($call->output_tokens));
    $this->output()->writeln("  Cost: $" . number_format($call->estimated_cost, 4));
    $this->output()->writeln('');
    
    if ($call->context_data) {
      $this->output()->writeln("<comment>Context Data:</comment>");
      $context = Json::decode($call->context_data);
      $this->output()->writeln(Json::encode($context, JSON_PRETTY_PRINT));
      $this->output()->writeln('');
    }
    
    if (!$call->success && $call->error_message) {
      $this->output()->writeln("<error>Error Message:</error>");
      $this->output()->writeln($call->error_message);
      $this->output()->writeln('');
    }
    
    if ($call->prompt_preview) {
      $prompt_len = strlen($call->prompt_preview);
      $this->output()->writeln("<comment>Full Prompt ({$prompt_len} characters):</comment>");
      $this->output()->writeln($call->prompt_preview);
      $this->output()->writeln('');
    }
    
    if ($call->response_preview) {
      $response_len = strlen($call->response_preview);
      $this->output()->writeln("<comment>Full Response ({$response_len} characters):</comment>");
      $this->output()->writeln($call->response_preview);
      $this->output()->writeln('');
    }
  }

}
