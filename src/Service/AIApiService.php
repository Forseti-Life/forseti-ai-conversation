<?php

namespace Drupal\ai_conversation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_conversation\Traits\ConfigurableLoggingTrait;

/**
 * Service for AI API communication using AWS Bedrock with rolling conversation summary.
 */
class AIApiService {

  use ConfigurableLoggingTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The prompt manager.
   *
   * @var \Drupal\ai_conversation\Service\PromptManager
   */
  protected $promptManager;

  /**
   * Maximum number of recent messages to keep (configurable).
   *
   * @var int
   */
  protected $maxRecentMessages = 20;

  /**
   * Update summary every N messages.
   *
   * @var int
   */
  protected $summaryFrequency = 10;

  /**
   * Maximum tokens before triggering summary update.
   *
   * @var int
   */
  protected $maxTokensBeforeSummary = 6000;

  /**
   * Constructs a new AIApiService object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager, PromptManager $prompt_manager = NULL) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ai_conversation');
    $this->entityTypeManager = $entity_type_manager;
    
    // Inject PromptManager or create one if not provided (for backwards compatibility)
    if ($prompt_manager) {
      $this->promptManager = $prompt_manager;
    } else {
      // Fallback for contexts where DI isn't available
      $this->promptManager = \Drupal::service('ai_conversation.prompt_manager');
    }
    
    // Load configuration.
    $config = $this->configFactory->get('ai_conversation.settings');
    $this->maxRecentMessages = $config->get('max_recent_messages') ?: 10;
    $this->maxTokensBeforeSummary = $config->get('max_tokens_before_summary') ?: 6000;
    $this->summaryFrequency = $config->get('summary_frequency') ?: 10;
  }

  /**
   * Returns ordered list of model IDs to try: primary from config, then fallbacks.
   */
  private function getModelFallbacks(): array {
    $primary = $this->configFactory->get('ai_conversation.settings')->get('aws_model') ?: 'us.anthropic.claude-sonnet-4-6';
    $fallbacks = [
      'us.anthropic.claude-sonnet-4-6',
      'us.anthropic.claude-haiku-4-5',
      'us.anthropic.claude-3-5-haiku-20241022-v1:0',
    ];
    return array_values(array_unique(array_merge([$primary], $fallbacks)));
  }

  /**
   * Builds a configured Bedrock runtime client using system config only.
   */
  private function buildBedrockClient(): \Aws\BedrockRuntime\BedrockRuntimeClient {
    $config = $this->configFactory->get('ai_conversation.settings');
    $aws_access_key = $config->get('aws_access_key_id') ?: getenv('AWS_ACCESS_KEY_ID');
    $aws_secret_key = $config->get('aws_secret_access_key') ?: getenv('AWS_SECRET_ACCESS_KEY');
    $aws_region = $config->get('aws_region') ?: 'us-east-1';

    $sdk_config = ['region' => $aws_region, 'version' => 'latest'];
    if (!empty($aws_access_key) && !empty($aws_secret_key)) {
      $sdk_config['credentials'] = ['key' => $aws_access_key, 'secret' => $aws_secret_key];
    }

    return (new \Aws\Sdk($sdk_config))->createBedrockRuntime();
  }

  /**
   * Send a message to the AI model with rolling summary management.
   */
  public function sendMessage(NodeInterface $conversation, string $message) {
    try {
      // Check if we need to update the summary before processing.
      $this->checkAndUpdateSummary($conversation);

      $config = $this->configFactory->get('ai_conversation.settings');
      $bedrock = $this->buildBedrockClient();
      $models_to_try = $this->getModelFallbacks();
      $model = $models_to_try[0];

      // Build the optimized conversation context (summary + recent messages).
      $context = $this->buildOptimizedContext($conversation, $message);
      
      // Estimate input tokens.
      $input_tokens = $this->estimateTokens($context);

      // Get max tokens from config.
      $max_tokens = $config->get('max_tokens') ?: 50000;

      // Get system prompt from PromptManager with optional dynamic content from node 10
      $system_prompt = $this->promptManager->getSystemPrompt(10);
      
      // Debug logging for system prompt
      $this->logInfo('System prompt length: @length, First 100 chars: @preview', [
        '@length' => strlen($system_prompt ?? ''),
        '@preview' => substr($system_prompt ?? 'EMPTY', 0, 100),
      ]);

      // Build the request body.
      $request_body = [
        'anthropic_version' => 'bedrock-2023-05-31',
        'max_tokens' => $max_tokens,
        'messages' => [
          [
            'role' => 'user',
            'content' => $context
          ]
        ]
      ];

      // Add system prompt if configured.
      if (!empty($system_prompt)) {
        $request_body['system'] = $system_prompt;
        $this->logInfo('System prompt added to request body');
      } else {
        $this->logInfo('No system prompt found in configuration');
      }

      $start_time = microtime(true);

      // Try models in fallback order.
      $last_exception = NULL;
      $response = NULL;
      foreach ($models_to_try as $candidate_model) {
        try {
          $response = $bedrock->invokeModel([
            'modelId' => $candidate_model,
            'body' => json_encode($request_body),
          ]);
          $model = $candidate_model;
          $last_exception = NULL;
          break;
        } catch (\Aws\Exception\AwsException $e) {
          $this->logError('Model @model failed (@code), trying next. Error: @msg', [
            '@model' => $candidate_model,
            '@code' => $e->getAwsErrorCode(),
            '@msg' => $e->getMessage(),
          ]);
          $last_exception = $e;
        }
      }
      if ($last_exception !== NULL) {
        throw $last_exception;
      }

      $duration_ms = (int)((microtime(true) - $start_time) * 1000);

      $result = json_decode($response['body']->getContents(), true);
      
      if (isset($result['content'][0]['text'])) {
        $ai_response = $result['content'][0]['text'];
        $stop_reason = $result['stop_reason'] ?? 'unknown';
        
        // Estimate output tokens and update total.
        $output_tokens = $this->estimateTokens($ai_response);
        $this->updateTokenCount($conversation, $input_tokens + $output_tokens);
        
        // Track API usage (success case)
        $this->trackApiUsage([
          'module' => 'ai_conversation',
          'operation' => 'chat_message',
          'model_id' => $model,
          'input_tokens' => $input_tokens,
          'output_tokens' => $output_tokens,
          'stop_reason' => $stop_reason,
          'duration_ms' => $duration_ms,
          'context_data' => [
            'conversation_id' => $conversation->id(),
            'conversation_title' => $conversation->getTitle(),
          ],
          'success' => TRUE,
          'prompt' => $context,
          'response' => $ai_response,
        ]);
        
        return $ai_response;
      }
      
      // Track failure - unexpected response format
      $this->trackApiUsage([
        'module' => 'ai_conversation',
        'operation' => 'chat_message',
        'model_id' => $model,
        'input_tokens' => $input_tokens,
        'output_tokens' => 0,
        'stop_reason' => 'error',
        'duration_ms' => $duration_ms,
        'context_data' => [
          'conversation_id' => $conversation->id(),
          'conversation_title' => $conversation->getTitle(),
        ],
        'success' => FALSE,
        'error_message' => 'Unexpected API response format',
        'prompt' => $context,
      ]);
      
      $this->logError('Unexpected API response format: @response', ['@response' => print_r($result, TRUE)]);
      throw new \Exception('Unexpected API response format');
      
    } catch (\Exception $e) {
      // Track failure - exception
      $this->trackApiUsage([
        'module' => 'ai_conversation',
        'operation' => 'chat_message',
        'model_id' => $model ?? 'unknown',
        'input_tokens' => $input_tokens ?? 0,
        'output_tokens' => 0,
        'stop_reason' => 'error',
        'duration_ms' => isset($start_time) ? (int)((microtime(TRUE) - $start_time) * 1000) : 0,
        'context_data' => [
          'conversation_id' => $conversation->id(),
          'conversation_title' => $conversation->getTitle(),
        ],
        'success' => FALSE,
        'error_message' => $e->getMessage(),
        'prompt' => $context ?? '',
      ]);
      
      $this->logError('Error communicating with AI service: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to communicate with AI service: ' . $e->getMessage());
    }
  }

  /**
   * Update total token count for conversation.
   */
  private function updateTokenCount(NodeInterface $conversation, int $tokens) {
    $current_tokens = $conversation->get('field_total_tokens')->value ?: 0;
    $new_total = $current_tokens + $tokens;
    $conversation->set('field_total_tokens', $new_total);
    
    $this->logInfo('Updated token count for conversation @nid: +@tokens (total: @total)', [
      '@nid' => $conversation->id(),
      '@tokens' => $tokens,
      '@total' => $new_total,
    ]);
  }

  /**
   * Get pricing for AWS Bedrock Claude models.
   * 
   * @param string $model_id
   *   AWS Bedrock model identifier.
   * 
   * @return array
   *   Array with 'input' and 'output' pricing per 1M tokens, or NULL if unknown.
   */
  protected function getModelPricing(string $model_id): ?array {
    // Pricing as of February 2026 (per 1M tokens)
    $pricing = [
      // Claude 4 Series (Current Generation)
      'anthropic.claude-opus-4-6-v1' => ['input' => 15.00, 'output' => 75.00],
      'anthropic.claude-opus-4-5-20251101-v1:0' => ['input' => 15.00, 'output' => 75.00],
      'anthropic.claude-opus-4-1-20250805-v1:0' => ['input' => 15.00, 'output' => 75.00],
      'anthropic.claude-sonnet-4-5-20250929-v1:0' => ['input' => 3.00, 'output' => 15.00],
      'anthropic.claude-sonnet-4-20250514-v1:0' => ['input' => 3.00, 'output' => 15.00],
      'anthropic.claude-haiku-4-5-20251001-v1:0' => ['input' => 1.00, 'output' => 5.00],
      
      // Claude 3.5 Series (Legacy/Maintenance)
      'anthropic.claude-3-5-sonnet-20241022-v2:0' => ['input' => 3.00, 'output' => 15.00],
      'anthropic.claude-3-5-sonnet-20240620-v1:0' => ['input' => 3.00, 'output' => 15.00],
      'anthropic.claude-3-5-haiku-20241022-v1:0' => ['input' => 0.25, 'output' => 1.25],
      
      // Claude 3 Series (Legacy)
      'anthropic.claude-3-opus-20240229-v1:0' => ['input' => 15.00, 'output' => 75.00],
      'anthropic.claude-3-sonnet-20240229-v1:0' => ['input' => 3.00, 'output' => 15.00],
      'anthropic.claude-3-haiku-20240307-v1:0' => ['input' => 0.25, 'output' => 1.25],
      
      // Claude 2 Series (Legacy - estimated)
      'anthropic.claude-v2:1' => ['input' => 8.00, 'output' => 24.00],
      'anthropic.claude-v2' => ['input' => 8.00, 'output' => 24.00],
      'anthropic.claude-instant-v1' => ['input' => 0.80, 'output' => 2.40],
    ];
    
    return $pricing[$model_id] ?? NULL;
  }

  /**
   * Get all model pricing information for display.
   * 
   * @return array
   *   Structured pricing data organized by generation/tier.
   */
  public function getAllModelPricing(): array {
    return [
      'claude_4' => [
        'title' => 'Claude 4 Series (Current Generation)',
        'description' => 'Latest production-ready models for building autonomous agents and complex coding workflows.',
        'models' => [
          [
            'name' => 'Opus 4.6',
            'model_id' => 'anthropic.claude-opus-4-6-v1',
            'input_price' => 15.00,
            'output_price' => 75.00,
            'highlights' => 'Latest release (Feb 2026); includes "agent teams" and 1M token beta.',
          ],
          [
            'name' => 'Opus 4.5',
            'model_id' => 'anthropic.claude-opus-4-5-20251101-v1:0',
            'input_price' => 15.00,
            'output_price' => 75.00,
            'highlights' => 'Released Nov 2025; introduced "Infinite Chats" feature.',
          ],
          [
            'name' => 'Opus 4.1',
            'model_id' => 'anthropic.claude-opus-4-1-20250805-v1:0',
            'input_price' => 15.00,
            'output_price' => 75.00,
            'highlights' => 'Aug 2025 "drop-in replacement" for original Opus 4.',
          ],
          [
            'name' => 'Sonnet 4.5',
            'model_id' => 'anthropic.claude-sonnet-4-5-20250929-v1:0',
            'input_price' => 3.00,
            'output_price' => 15.00,
            'highlights' => 'Best intelligence/cost ratio; world-leader in coding tasks.',
          ],
          [
            'name' => 'Sonnet 4.0',
            'model_id' => 'anthropic.claude-sonnet-4-20250514-v1:0',
            'input_price' => 3.00,
            'output_price' => 15.00,
            'highlights' => 'May 2025 release; significant upgrade over Sonnet 3.7.',
          ],
          [
            'name' => 'Haiku 4.5',
            'model_id' => 'anthropic.claude-haiku-4-5-20251001-v1:0',
            'input_price' => 1.00,
            'output_price' => 5.00,
            'highlights' => 'Fastest current model; matches Sonnet 4 performance at 1/3 cost.',
          ],
        ],
      ],
      'claude_3_5' => [
        'title' => 'Claude 3.5 & 3 Series (Legacy/Maintenance)',
        'description' => 'Most users have migrated to the 4.x series, but these remain available for existing applications.',
        'models' => [
          [
            'name' => 'Claude 3.5 Sonnet v2',
            'model_id' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            'input_price' => 3.00,
            'output_price' => 15.00,
            'status' => 'Effective Oct 2024 update.',
          ],
          [
            'name' => 'Claude 3.5 Haiku',
            'model_id' => 'anthropic.claude-3-5-haiku-20241022-v1:0',
            'input_price' => 0.25,
            'output_price' => 1.25,
            'status' => 'Oct 2024 update.',
          ],
          [
            'name' => 'Claude 3 Opus',
            'model_id' => 'anthropic.claude-3-opus-20240229-v1:0',
            'input_price' => 15.00,
            'output_price' => 75.00,
            'status' => 'Discontinued or limited availability in most regions.',
          ],
          [
            'name' => 'Claude 3 Haiku',
            'model_id' => 'anthropic.claude-3-haiku-20240307-v1:0',
            'input_price' => 0.25,
            'output_price' => 1.25,
            'status' => 'Original "fast" model.',
          ],
        ],
      ],
    ];
  }

  /**
   * Track API usage to database for cost monitoring and troubleshooting.
   * 
   * @param array $params
   *   Array with keys:
   *   - module: Module making the call (e.g., 'ai_conversation', 'job_hunter')
   *   - operation: Operation type (e.g., 'chat_message', 'resume_parsing')
   *   - model_id: AWS Bedrock model identifier
   *   - input_tokens: Estimated input tokens
   *   - output_tokens: Estimated output tokens
   *   - stop_reason: API stop reason (end_turn, max_tokens, etc.)
   *   - duration_ms: Duration in milliseconds
   *   - context_data: Additional context (entity_id, queue_id, etc.)
   *   - success: Whether the call succeeded (boolean, default TRUE)
   *   - error_message: Error message if call failed (optional)
   *   - prompt: The FULL prompt sent to AI (optional, stored completely for debugging)
   *   - response: The FULL response from AI (optional, stored completely for debugging)
   */
  public function trackApiUsage(array $params) {
    try {
      $connection = \Drupal::database();
      
      // Calculate estimated cost based on model-specific pricing
      $model_id = $params['model_id'] ?? '';
      $pricing = $this->getModelPricing($model_id);
      
      if ($pricing) {
        // Dynamic pricing based on actual model
        $input_cost = ($params['input_tokens'] ?? 0) * $pricing['input'] / 1000000;
        $output_cost = ($params['output_tokens'] ?? 0) * $pricing['output'] / 1000000;
      } else {
        // Fallback to Claude 3.5 Sonnet pricing if model unknown
        $input_cost = ($params['input_tokens'] ?? 0) * 3.00 / 1000000;
        $output_cost = ($params['output_tokens'] ?? 0) * 15.00 / 1000000;
        $this->logWarning('Unknown model pricing for @model, using Claude 3.5 Sonnet rates', [
          '@model' => $model_id,
        ]);
      }
      
      $estimated_cost = $input_cost + $output_cost;
      
      // Determine success status
      $success = $params['success'] ?? TRUE;
      
      // Store full prompt/response for debugging (not truncated)
      $full_prompt = $params['prompt'] ?? NULL;
      $full_response = $params['response'] ?? NULL;
      
      $fields = [
        'timestamp' => \Drupal::time()->getRequestTime(),
        'uid' => \Drupal::currentUser()->id(),
        'module' => $params['module'] ?? 'unknown',
        'operation' => $params['operation'] ?? 'unknown',
        'model_id' => $params['model_id'] ?? '',
        'input_tokens' => $params['input_tokens'] ?? 0,
        'output_tokens' => $params['output_tokens'] ?? 0,
        'stop_reason' => $params['stop_reason'] ?? '',
        'duration_ms' => $params['duration_ms'] ?? 0,
        'estimated_cost' => $estimated_cost,
        'context_data' => isset($params['context_data']) ? json_encode($params['context_data']) : NULL,
      ];
      
      // Add debugging fields if they exist
      if ($connection->schema()->fieldExists('ai_conversation_api_usage', 'success')) {
        $fields['success'] = $success ? 1 : 0;
      }
      if ($connection->schema()->fieldExists('ai_conversation_api_usage', 'error_message')) {
        $fields['error_message'] = $params['error_message'] ?? NULL;
      }
      if ($connection->schema()->fieldExists('ai_conversation_api_usage', 'prompt_preview')) {
        $fields['prompt_preview'] = mb_substr((string) $full_prompt, 0, 490);
      }
      if ($connection->schema()->fieldExists('ai_conversation_api_usage', 'response_preview')) {
        $fields['response_preview'] = mb_substr((string) $full_response, 0, 490);
      }
      
      $connection->insert('ai_conversation_api_usage')
        ->fields($fields)
        ->execute();
        
      if ($success) {
        $this->logInfo('📊 API usage tracked: @module/@operation - @input_tokens in + @output_tokens out = $@cost', [
          '@module' => $params['module'] ?? 'unknown',
          '@operation' => $params['operation'] ?? 'unknown',
          '@input_tokens' => $params['input_tokens'] ?? 0,
          '@output_tokens' => $params['output_tokens'] ?? 0,
          '@cost' => number_format($estimated_cost, 4),
        ]);
      } else {
        $this->logError('❌ API call failed and tracked: @module/@operation - @error', [
          '@module' => $params['module'] ?? 'unknown',
          '@operation' => $params['operation'] ?? 'unknown',
          '@error' => $params['error_message'] ?? 'Unknown error',
        ]);
      }
    } catch (\Exception $e) {
      $this->logError('Failed to track API usage: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Invoke AWS Bedrock model directly with tracking and caching.
   * 
   * For use by queue workers and batch operations that don't use conversation nodes.
   * Automatically checks for cached successful responses before making new API calls.
   * 
   * @param string $prompt
   *   The prompt to send to the AI.
   * @param string $module
   *   Module making the call (e.g., 'job_hunter').
   * @param string $operation
   *   Operation type (e.g., 'resume_tailoring', 'cover_letter_generation').
   * @param array $context_data
   *   Additional context for tracking (e.g., ['job_id' => 123, 'uid' => 1]).
   * @param array $options
   *   Optional parameters:
   *   - model_id: Override default model
   *   - max_tokens: Override default max_tokens (default: 8000)
   *   - system_prompt: Optional system prompt
   *   - skip_cache: Set to TRUE to bypass cache lookup (default: FALSE)
   * 
   * @return array
   *   Response array with keys:
   *   - success: bool
   *   - response: string (AI response text)
   *   - stop_reason: string
   *   - input_tokens: int
   *   - output_tokens: int
   *   - error: string (if success is false)
   *   - cached: bool (TRUE if response came from cache)
   */
  public function invokeModelDirect(string $prompt, string $module, string $operation, array $context_data = [], array $options = []) {
    try {
      // Check cache first (unless explicitly disabled)
      if (empty($options['skip_cache'])) {
        $cached = $this->getCachedApiResponse($module, $operation, $context_data);
        if ($cached) {
          $this->logInfo('♻️ Reusing cached GenAI response from @timestamp for @module/@operation', [
            '@timestamp' => date('Y-m-d H:i:s', $cached['timestamp']),
            '@module' => $module,
            '@operation' => $operation,
          ]);
          
          return [
            'success' => TRUE,
            'response' => $cached['response'],
            'stop_reason' => $cached['stop_reason'],
            'input_tokens' => $cached['input_tokens'],
            'output_tokens' => $cached['output_tokens'],
            'cached' => TRUE,
          ];
        }
      }
      
      // No cache hit - proceed with API call
      $bedrock = $this->buildBedrockClient();
      $model_id = $options['model_id'] ?? $this->getModelFallbacks()[0];
      $max_tokens = $options['max_tokens'] ?? 8000;
      
      $request_body = [
        'anthropic_version' => 'bedrock-2023-05-31',
        'max_tokens' => $max_tokens,
        'messages' => [
          [
            'role' => 'user',
            'content' => $prompt,
          ],
        ],
      ];

      if (!empty($options['system_prompt'])) {
        $request_body['system'] = $options['system_prompt'];
      }

      $start_time = microtime(TRUE);

      $response = $bedrock->invokeModel([
        'modelId' => $model_id,
        'body' => json_encode($request_body),
      ]);

      $duration_ms = (int)((microtime(TRUE) - $start_time) * 1000);
      $result = json_decode($response['body']->getContents(), TRUE);
      
      if (isset($result['content'][0]['text'])) {
        $ai_response = $result['content'][0]['text'];
        $stop_reason = $result['stop_reason'] ?? 'unknown';
        
        // Estimate tokens
        $input_tokens = $this->estimateTokens($prompt);
        $output_tokens = $this->estimateTokens($ai_response);
        
        // Add max_tokens to context_data for debugging
        $context_data_with_config = $context_data + ['max_tokens' => $max_tokens, 'model_id' => $model_id];
        
        // Track usage (success case)
        $this->trackApiUsage([
          'module' => $module,
          'operation' => $operation,
          'model_id' => $model_id,
          'input_tokens' => $input_tokens,
          'output_tokens' => $output_tokens,
          'stop_reason' => $stop_reason,
          'duration_ms' => $duration_ms,
          'context_data' => $context_data_with_config,
          'success' => TRUE,
          'prompt' => $prompt,
          'response' => $ai_response,
        ]);
        
        return [
          'success' => TRUE,
          'response' => $ai_response,
          'stop_reason' => $stop_reason,
          'input_tokens' => $input_tokens,
          'output_tokens' => $output_tokens,
          'cached' => FALSE,
        ];
      }
      
      // Track failure - unexpected response format
      $context_data_with_config = $context_data + ['max_tokens' => $max_tokens, 'model_id' => $model_id];
      $this->trackApiUsage([
        'module' => $module,
        'operation' => $operation,
        'model_id' => $model_id,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'stop_reason' => 'error',
        'duration_ms' => $duration_ms ?? 0,
        'context_data' => $context_data_with_config,
        'success' => FALSE,
        'error_message' => 'Unexpected API response format',
        'prompt' => $prompt,
      ]);
      
      return [
        'success' => FALSE,
        'error' => 'Unexpected API response format',
      ];
      
    } catch (\Exception $e) {
      $this->logError('AWS Bedrock invocation failed: @message', ['@message' => $e->getMessage()]);
      
      // Track failure - exception
      $max_tokens_for_error = $options['max_tokens'] ?? 8000;
      $context_data_with_config = $context_data + ['max_tokens' => $max_tokens_for_error, 'model_id' => $options['model_id'] ?? 'us.anthropic.claude-sonnet-4-5-20250929-v1:0'];
      $this->trackApiUsage([
        'module' => $module,
        'operation' => $operation,
        'model_id' => $options['model_id'] ?? 'us.anthropic.claude-sonnet-4-5-20250929-v1:0',
        'input_tokens' => 0,
        'output_tokens' => 0,
        'stop_reason' => 'error',
        'duration_ms' => isset($start_time) ? (int)((microtime(TRUE) - $start_time) * 1000) : 0,
        'context_data' => $context_data_with_config,
        'success' => FALSE,
        'error_message' => $e->getMessage(),
        'prompt' => $prompt,
      ]);
      
      return [
        'success' => FALSE,
        'error' => $e->getMessage(),
        'cached' => FALSE,
      ];
    }
  }

  /**
   * Get cached successful API response to avoid redundant calls.
   * 
   * @param string $module
   *   Module name.
   * @param string $operation
   *   Operation type.
   * @param array $context_data
   *   Context data to match against.
   * 
   * @return array|null
   *   Array with response data if found, NULL otherwise.
   */
  private function getCachedApiResponse(string $module, string $operation, array $context_data) {
    $connection = \Drupal::database();
    
    // Build WHERE clauses for context_data matching
    $query = $connection->select('ai_conversation_api_usage', 'u')
      ->fields('u', ['response_preview', 'stop_reason', 'timestamp', 'input_tokens', 'output_tokens'])
      ->condition('module', $module)
      ->condition('operation', $operation)
      ->condition('success', 1)
      ->orderBy('timestamp', 'DESC')
      ->range(0, 1);
    
    // Add JSON_EXTRACT conditions for each context field
    // JSON_EXTRACT handles both numeric and string values correctly
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
   * Clear cached GenAI responses for specific context.
   * 
   * Use this to invalidate cached responses when retrying suspended queue items
   * or when the prompt/input has changed.
   * 
   * @param string $module
   *   Module name (e.g., 'job_hunter').
   * @param string $operation
   *   Operation type (e.g., 'resume_tailoring').
   * @param array $context_data
   *   Context data to match against (e.g., ['uid' => 5, 'job_id' => 123]).
   * 
   * @return int
   *   Number of cached responses cleared.
   */
  public function clearCachedResponse(string $module, string $operation, array $context_data) {
    $connection = \Drupal::database();
    
    // Build delete query matching the context
    $query = $connection->delete('ai_conversation_api_usage')
      ->condition('module', $module)
      ->condition('operation', $operation);
    
    // Add JSON_EXTRACT conditions for each context field
    // JSON_EXTRACT handles both numeric and string values correctly
    foreach ($context_data as $key => $value) {
      $query->where("JSON_EXTRACT(context_data, '$.$key') = :value_$key", [":value_$key" => $value]);
    }
    
    $count = $query->execute();
    
    if ($count > 0) {
      $this->logInfo('🗑️ Cleared @count cached GenAI response(s) for @module/@operation', [
        '@count' => $count,
        '@module' => $module,
        '@operation' => $operation,
      ]);
    }
    
    return $count;
  }

  /**
   * Build optimized context using summary + recent messages.
   */
  private function buildOptimizedContext(NodeInterface $conversation, string $new_message) {
    // Check if this is the start of a conversation (no previous messages).
    $recent_messages = $this->getRecentMessages($conversation);
    $is_conversation_start = empty($recent_messages) && 
      (!$conversation->hasField('field_conversation_summary') || $conversation->get('field_conversation_summary')->isEmpty());
    
    // For new conversations, use enhanced context with Forseti mission info.
    if ($is_conversation_start) {
      $context = $this->promptManager->getBaseSystemPrompt();
    } else {
      // For existing conversations, use the original system prompt.
      $system_prompt = $conversation->get('field_context')->value ?: $this->promptManager->getBaseSystemPrompt();
      $context = $system_prompt . "\n\n";
    }

    // Add conversation summary if it exists.
    if ($conversation->hasField('field_conversation_summary') && !$conversation->get('field_conversation_summary')->isEmpty()) {
      $summary = $conversation->get('field_conversation_summary')->value;
      if (!empty($summary)) {
        $context .= "CONVERSATION SUMMARY (Previous Discussion):\n" . $summary . "\n\n";
      }
    }

    // Add recent messages.
    if (!empty($recent_messages)) {
      $context .= "RECENT CONVERSATION:\n";
      
      foreach ($recent_messages as $msg) {
        $role = $msg['role'] === 'user' ? 'Human' : 'Assistant';
        $context .= $role . ": " . $msg['content'] . "\n\n";
      }
    }

    // Add current message.
    $context .= "Human: " . $new_message . "\n\n";

    return $context;
  }


  /**
   * Get recent messages (up to maxRecentMessages).
   */
  private function getRecentMessages(NodeInterface $conversation) {
    $messages = [];
    
    if ($conversation->hasField('field_messages') && !$conversation->get('field_messages')->isEmpty()) {
      $all_messages = [];
      foreach ($conversation->get('field_messages') as $message_item) {
        $message_data = json_decode($message_item->value, TRUE);
        if ($message_data && isset($message_data['role']) && isset($message_data['content'])) {
          $all_messages[] = [
            'role' => $message_data['role'],
            'content' => $message_data['content'],
            'timestamp' => $message_data['timestamp'] ?? time(),
          ];
        }
      }

      // Sort by timestamp (most recent first) and take the last N messages.
      usort($all_messages, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
      });

      // Take the most recent messages (up to maxRecentMessages).
      $recent_messages = array_slice($all_messages, 0, $this->maxRecentMessages);
      
      // Reverse to get chronological order.
      $messages = array_reverse($recent_messages);
    }

    return $messages;
  }

  /**
   * Check if we need to update the conversation summary.
   */
  private function checkAndUpdateSummary(NodeInterface $conversation) {
    // Use field_summary_message_count exclusively for summary logic.
    $summary_message_count = $conversation->get('field_summary_message_count')->value ?? 0;
    $summary_message_count++;
    $conversation->set('field_summary_message_count', $summary_message_count);

    // If summary_message_count is divisible by summaryFrequency, generate summary and reset counter.
    if ($summary_message_count % $this->summaryFrequency === 0) {
      $this->updateConversationSummary($conversation);
      // Reset summary message count to 0 after summary generation.
      $conversation->set('field_summary_message_count', 0);
    }
  }

  /**
   * Update the conversation summary.
   */
  private function updateConversationSummary(NodeInterface $conversation) {
    try {
      // Get all messages.
      $all_messages = $this->getAllMessages($conversation);
      
      // Keep only the most recent 20 messages, summarize the rest.
      if (count($all_messages) <= $this->maxRecentMessages) {
        return; // Not enough messages to summarize.
      }

      $messages_to_summarize = array_slice($all_messages, 0, -$this->maxRecentMessages);
      
      if (empty($messages_to_summarize)) {
        return;
      }

      // Build context for summary generation.
      $summary_context = $this->buildSummaryContext($conversation, $messages_to_summarize);

      // Generate summary using Claude.
      $summary = $this->generateSummary($summary_context);

      // Update the conversation with the new summary.
      $conversation->set('field_conversation_summary', $summary);
      $conversation->set('field_summary_updated', time());
      
      // Remove old messages, keep only recent ones.
      $recent_messages = array_slice($all_messages, -$this->maxRecentMessages);
      $this->updateMessagesField($conversation, $recent_messages);
      
      $this->logInfo('Updated conversation summary for node @nid: summarized @count messages, kept @keep recent', [
        '@nid' => $conversation->id(),
        '@count' => count($messages_to_summarize),
        '@keep' => count($recent_messages),
      ]);
      
    } catch (\Exception $e) {
      $this->logError('Error updating conversation summary: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Generate a summary of the conversation messages.
   */
  private function generateSummary(string $context) {
    try {
      $bedrock = $this->buildBedrockClient();
      $models_to_try = $this->getModelFallbacks();
      $request_body = json_encode([
        'anthropic_version' => 'bedrock-2023-05-31',
        'max_tokens' => 20000,
        'messages' => [['role' => 'user', 'content' => $context]],
      ]);

      $last_exception = NULL;
      $result = NULL;
      foreach ($models_to_try as $candidate_model) {
        try {
          $response = $bedrock->invokeModel(['modelId' => $candidate_model, 'body' => $request_body]);
          $result = json_decode($response['body']->getContents(), true);
          $last_exception = NULL;
          break;
        } catch (\Aws\Exception\AwsException $e) {
          $last_exception = $e;
        }
      }
      if ($last_exception !== NULL) {
        throw $last_exception;
      }

      if (isset($result['content'][0]['text'])) {
        return $result['content'][0]['text'];
      }
      throw new \Exception('Unexpected API response format');

    } catch (\Exception $e) {
      $this->logError('Error generating summary: @message', ['@message' => $e->getMessage()]);
      return 'Summary generation failed.';
    }
  }

  /**
   * Build context for summary generation.
   */
  private function buildSummaryContext(NodeInterface $conversation, array $messages_to_summarize) {
    $context = "Please create a concise summary of the following conversation. ";
    $context .= "Focus on key topics discussed and important information that would be useful for continuing the conversation. ";
    $context .= "Keep the summary brief but informative.\n\n";

    // Add existing summary if it exists.
    if ($conversation->hasField('field_conversation_summary') && !$conversation->get('field_conversation_summary')->isEmpty()) {
      $existing_summary = $conversation->get('field_conversation_summary')->value;
      if (!empty($existing_summary)) {
        $context .= "EXISTING SUMMARY:\n" . $existing_summary . "\n\n";
        $context .= "UPDATE THE ABOVE SUMMARY WITH THE FOLLOWING NEW MESSAGES:\n\n";
      }
    }

    $context .= "CONVERSATION TO SUMMARIZE:\n";
    foreach ($messages_to_summarize as $msg) {
      $role = $msg['role'] === 'user' ? 'Human' : 'Assistant';
      $context .= $role . ": " . $msg['content'] . "\n\n";
    }

    return $context;
  }

  /**
   * Get all messages from the conversation.
   */
  private function getAllMessages(NodeInterface $conversation) {
    $messages = [];
    
    if ($conversation->hasField('field_messages') && !$conversation->get('field_messages')->isEmpty()) {
      foreach ($conversation->get('field_messages') as $message_item) {
        $message_data = json_decode($message_item->value, TRUE);
        if ($message_data && isset($message_data['role']) && isset($message_data['content'])) {
          $messages[] = [
            'role' => $message_data['role'],
            'content' => $message_data['content'],
            'timestamp' => $message_data['timestamp'] ?? time(),
          ];
        }
      }

      // Sort by timestamp.
      usort($messages, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
      });
    }

    return $messages;
  }

  /**
   * Update the messages field with new message array.
   */
  private function updateMessagesField(NodeInterface $conversation, array $messages) {
    $field_values = [];
    foreach ($messages as $message) {
      $field_values[] = ['value' => json_encode($message)];
    }
    $conversation->set('field_messages', $field_values);
  }

  /**
   * Estimate token count for the conversation context.
   */
  private function estimateTokenCount(NodeInterface $conversation) {
    $context = $this->buildOptimizedContext($conversation, '');
    return $this->estimateTokens($context);
  }

  /**
   * Estimate token count for text (rough approximation).
   */
  private function estimateTokens(string $text) {
    // Rough estimate: 1 token ≈ 4 characters.
    return intval(strlen($text) / 4);
  }

  /**
   * Build conversation history from node messages (legacy method for backward compatibility).
   */
  private function buildConversationHistory(NodeInterface $conversation) {
    // For backward compatibility, this now uses the optimized approach.
    return $this->getRecentMessages($conversation);
  }

  /**
   * Test API connection.
   */
  public function testConnection() {
    try {
      $bedrock = $this->buildBedrockClient();
      $models_to_try = $this->getModelFallbacks();
      $model = $models_to_try[0];

      $response = $bedrock->invokeModel([
        'modelId' => $model,
        'body' => json_encode([
          'anthropic_version' => 'bedrock-2023-05-31',
          'max_tokens' => 256,
          'messages' => [
            [
              'role' => 'user',
              'content' => 'Hello'
            ]
          ]
        ])
      ]);

      $result = json_decode($response['body']->getContents(), true);
      
      if (isset($result['content'][0]['text'])) {
        return ['success' => TRUE, 'message' => 'AWS Bedrock connection successful', 'model' => $model];
      } else {
        return ['success' => FALSE, 'message' => 'Unexpected API response'];
      }

    } catch (\Exception $e) {
      return ['success' => FALSE, 'message' => 'AWS Bedrock connection failed: ' . $e->getMessage()];
    }
  }

  /**
   * Get conversation statistics.
   */
  public function getConversationStats(NodeInterface $conversation) {
    $stats = [
      'total_messages' => $conversation->get('field_message_count')->value ?: 0,
      'recent_messages' => count($this->getRecentMessages($conversation)),
      'total_tokens' => $conversation->get('field_total_tokens')->value ?: 0,
      'has_summary' => !empty($conversation->get('field_conversation_summary')->value),
      'summary_updated' => $conversation->get('field_summary_updated')->value,
      'estimated_tokens' => $this->estimateTokenCount($conversation),
    ];

    return $stats;
  }

  /**
   * Create a community suggestion node.
   *
   * @param \Drupal\node\NodeInterface $conversation
   *   The conversation node where the suggestion was made.
   * @param string $summary
   *   AI-generated summary of the suggestion.
   * @param string $original_message
   *   The original user message containing the suggestion.
   * @param string $category
   *   The suggestion category.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The created suggestion node or NULL on failure.
   */
  public function createSuggestion(NodeInterface $conversation, $summary, $original_message, $category) {
    try {
      // Get the current user (author of the conversation).
      $user = \Drupal::currentUser();
      
      // Create a title from the summary (first 100 chars).
      $title = mb_strlen($summary) > 100 ? mb_substr($summary, 0, 97) . '...' : $summary;
      
      // Create the suggestion node.
      $suggestion = Node::create([
        'type' => 'community_suggestion',
        'title' => $title,
        'uid' => $user->id(),
        'status' => TRUE,
        'field_suggestion_summary' => [
          'value' => $summary,
          'format' => 'plain_text',
        ],
        'field_original_message' => [
          'value' => $original_message,
          'format' => 'plain_text',
        ],
        'field_conversation_reference' => [
          'target_id' => $conversation->id(),
        ],
        'field_suggestion_category' => $category,
        'field_suggestion_status' => 'new',
      ]);
      
      $suggestion->save();
      
      $this->logInfo('Created community suggestion: @title (nid: @nid)', [
        '@title' => $title,
        '@nid' => $suggestion->id(),
      ]);
      
      return $suggestion;
      
    } catch (\Exception $e) {
      $this->logError('Failed to create community suggestion: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Create a community suggestion from in-game context (no conversation node).
   *
   * Used by the dungeoncrawler room chat pipeline where suggestions originate
   * from the GM reply rather than an ai_conversation node.
   *
   * @param string $summary
   *   AI-generated summary of the suggestion.
   * @param string $original_message
   *   The original user message containing the suggestion.
   * @param string $category
   *   The suggestion category.
   * @param array $context
   *   Optional context: campaign_id, room_id, character_id.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The created suggestion node or NULL on failure.
   */
  public function createBacklogSuggestion(string $summary, string $original_message, string $category, array $context = []) {
    try {
      $user = \Drupal::currentUser();
      $title = mb_strlen($summary) > 100 ? mb_substr($summary, 0, 97) . '...' : $summary;

      $suggestion = Node::create([
        'type'                     => 'community_suggestion',
        'title'                    => $title,
        'uid'                      => $user->id(),
        'status'                   => TRUE,
        'field_suggestion_summary' => ['value' => $summary, 'format' => 'plain_text'],
        'field_original_message'   => ['value' => $original_message, 'format' => 'plain_text'],
        'field_suggestion_category' => $category,
        'field_suggestion_status'  => 'new',
      ]);
      $suggestion->save();

      $this->logInfo('Backlog suggestion created from game: @title (nid: @nid, campaign: @cid)', [
        '@title' => $title,
        '@nid'   => $suggestion->id(),
        '@cid'   => $context['campaign_id'] ?? 'unknown',
      ]);

      return $suggestion;
    }
    catch (\Exception $e) {
      $this->logError('Failed to create backlog suggestion from game: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
