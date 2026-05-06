<?php

namespace Drupal\ai_conversation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\ai_conversation\Service\AIApiService;

/**
 * Controller for GenAI API usage reporting and cost tracking.
 */
class UsageReportController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiService
   */
  protected $aiApiService;

  /**
   * Constructs a UsageReportController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\ai_conversation\Service\AIApiService $ai_api_service
   *   The AI API service.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter, AIApiService $ai_api_service) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->aiApiService = $ai_api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('ai_conversation.ai_api_service')
    );
  }

  /**
   * Displays the GenAI API usage dashboard.
   */
  public function dashboard() {
    $build = [];

    // Summary statistics
    $stats = $this->getUsageStatistics();
    
    $build['summary'] = [
      '#theme' => 'ai_usage_dashboard',
      '#statistics' => $stats,
      '#recent_calls' => $this->getRecentApiCalls(50),
      '#module_breakdown' => $this->getModuleBreakdown(),
      '#daily_usage' => $this->getDailyUsage(30),
    ];

    $build['#attached']['library'][] = 'ai_conversation/usage-dashboard';

    return $build;
  }

  /**
   * Get overall usage statistics.
   */
  protected function getUsageStatistics() {
    $query = $this->database->select('ai_conversation_api_usage', 'u');
    $query->addExpression('COUNT(*)', 'total_calls');
    $query->addExpression('SUM(input_tokens)', 'total_input_tokens');
    $query->addExpression('SUM(output_tokens)', 'total_output_tokens');
    $query->addExpression('SUM(estimated_cost)', 'total_cost');
    $query->addExpression('AVG(duration_ms)', 'avg_duration_ms');
    
    $stats = $query->execute()->fetchAssoc();

    // Today's stats
    $today_start = strtotime('today');
    $today_query = $this->database->select('ai_conversation_api_usage', 'u');
    $today_query->addExpression('COUNT(*)', 'today_calls');
    $today_query->addExpression('SUM(estimated_cost)', 'today_cost');
    $today_query->condition('timestamp', $today_start, '>=');
    $today_stats = $today_query->execute()->fetchAssoc();

    // This month's stats
    $month_start = strtotime('first day of this month');
    $month_query = $this->database->select('ai_conversation_api_usage', 'u');
    $month_query->addExpression('SUM(estimated_cost)', 'month_cost');
    $month_query->condition('timestamp', $month_start, '>=');
    $month_stats = $month_query->execute()->fetchAssoc();

    return [
      'total_calls' => $stats['total_calls'] ?? 0,
      'total_input_tokens' => $stats['total_input_tokens'] ?? 0,
      'total_output_tokens' => $stats['total_output_tokens'] ?? 0,
      'total_tokens' => ($stats['total_input_tokens'] ?? 0) + ($stats['total_output_tokens'] ?? 0),
      'total_cost' => $stats['total_cost'] ?? 0,
      'avg_duration_ms' => round($stats['avg_duration_ms'] ?? 0),
      'today_calls' => $today_stats['today_calls'] ?? 0,
      'today_cost' => $today_stats['today_cost'] ?? 0,
      'month_cost' => $month_stats['month_cost'] ?? 0,
    ];
  }

  /**
   * Get recent API calls.
   */
  protected function getRecentApiCalls($limit = 50) {
    $query = $this->database->select('ai_conversation_api_usage', 'u')
      ->fields('u')
      ->orderBy('timestamp', 'DESC')
      ->range(0, $limit);
    
    $results = $query->execute()->fetchAll();
    
    $calls = [];
    foreach ($results as $row) {
      $calls[] = [
        'timestamp' => $this->dateFormatter->format($row->timestamp, 'custom', 'M d, Y H:i:s'),
        'module' => $row->module,
        'operation' => $row->operation,
        'model_id' => $row->model_id,
        'input_tokens' => $row->input_tokens,
        'output_tokens' => $row->output_tokens,
        'total_tokens' => $row->input_tokens + $row->output_tokens,
        'cost' => number_format($row->estimated_cost, 4),
        'duration_ms' => $row->duration_ms,
        'stop_reason' => $row->stop_reason,
      ];
    }
    
    return $calls;
  }

  /**
   * Get usage breakdown by module.
   */
  protected function getModuleBreakdown() {
    $query = $this->database->select('ai_conversation_api_usage', 'u');
    $query->addField('u', 'module');
    $query->addExpression('COUNT(*)', 'call_count');
    $query->addExpression('SUM(input_tokens + output_tokens)', 'total_tokens');
    $query->addExpression('SUM(estimated_cost)', 'total_cost');
    $query->groupBy('module');
    $query->orderBy('total_cost', 'DESC');
    
    $results = $query->execute()->fetchAll();
    
    $breakdown = [];
    foreach ($results as $row) {
      $breakdown[] = [
        'module' => $row->module,
        'call_count' => $row->call_count,
        'total_tokens' => $row->total_tokens,
        'total_cost' => number_format($row->total_cost, 4),
      ];
    }
    
    return $breakdown;
  }

  /**
   * Get daily usage for the last N days.
   */
  protected function getDailyUsage($days = 30) {
    $start_time = strtotime("-{$days} days");
    
    $query = $this->database->select('ai_conversation_api_usage', 'u');
    $query->addExpression('DATE(FROM_UNIXTIME(timestamp))', 'date');
    $query->addExpression('COUNT(*)', 'call_count');
    $query->addExpression('SUM(estimated_cost)', 'daily_cost');
    $query->condition('timestamp', $start_time, '>=');
    $query->groupBy('date');
    $query->orderBy('date', 'ASC');
    
    $results = $query->execute()->fetchAll();
    
    $daily = [];
    foreach ($results as $row) {
      $daily[] = [
        'date' => $row->date,
        'call_count' => $row->call_count,
        'cost' => number_format($row->daily_cost, 4),
      ];
    }
    
    return $daily;
  }

  /**
   * Displays the Claude model pricing reference page.
   */
  public function pricing() {
    $pricing_data = $this->aiApiService->getAllModelPricing();
    
    $build = [];
    
    $build['pricing'] = [
      '#theme' => 'ai_model_pricing',
      '#pricing_data' => $pricing_data,
    ];
    
    return $build;
  }

}
