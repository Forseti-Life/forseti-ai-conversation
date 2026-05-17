<?php

namespace Drupal\ai_conversation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserDataInterface;
use Drupal\ai_conversation\Service\DeepSeekApiService;
use Drupal\ai_conversation\Traits\ConfigurableLoggingTrait;

/**
 * Service for AI API communication using the local LLM with rolling summaries.
 */
class AIApiService {

  use ConfigurableLoggingTrait;

  /**
   * Conservative output cap for the current localhost fallback model.
   */
  private const LOCAL_FALLBACK_MAX_TOKENS = 2048;

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
   * The AI conversation storage service.
   *
   * @var \Drupal\ai_conversation\Service\AIConversationStorageService
   */
  protected $storage;

  /**
   * @var \Drupal\ai_conversation\Service\OllamaApiService|null
   */
  protected $ollamaService;

  /**
   * @var \Drupal\user\UserDataInterface|null
   */
  protected $userData;

  /**
   * @var \Drupal\ai_conversation\Service\DeepSeekApiService|null
   */
  protected $deepseekService;

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
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager, PromptManager $prompt_manager = NULL, AIConversationStorageService $storage = NULL, OllamaApiService $ollama_service = NULL, UserDataInterface $user_data = NULL, DeepSeekApiService $deepseek_service = NULL) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ai_conversation');
    $this->entityTypeManager = $entity_type_manager;
    $this->ollamaService = $ollama_service;
    $this->userData = $user_data;
    $this->deepseekService = $deepseek_service;

    // Inject PromptManager or create one if not provided (for backwards compatibility)
    if ($prompt_manager) {
      $this->promptManager = $prompt_manager;
    } else {
      // Fallback for contexts where DI isn't available
      $this->promptManager = \Drupal::service('ai_conversation.prompt_manager');
    }

    // Inject storage service or resolve lazily for backwards compatibility.
    if ($storage) {
      $this->storage = $storage;
    } else {
      $this->storage = \Drupal::service('ai_conversation.storage');
    }
    
    // Load configuration.
    $config = $this->configFactory->get('ai_conversation.settings');
    $this->maxRecentMessages = $config->get('max_recent_messages') ?: 10;
    $this->maxTokensBeforeSummary = $config->get('max_tokens_before_summary') ?: 6000;
    $this->summaryFrequency = $config->get('summary_frequency') ?: 10;
  }

  /**
   * Resolves the effective local model to use.
   */
  private function getLocalModelName(?string $preferred_model = NULL): string {
    $ollama = $this->ollamaService ?? \Drupal::service('ai_conversation.ollama_api_service');
    $models = array_values(array_filter($ollama->getAvailableModels()));

    if ($preferred_model && in_array($preferred_model, $models, TRUE)) {
      return $preferred_model;
    }

    return $models[0] ?? OllamaApiService::DEFAULT_MODEL;
  }

  /**
   * Resolves the effective DeepSeek model to use.
   */
  private function getDeepSeekModelName(?string $preferred_model = NULL): string {
    $deepseek = $this->deepseekService ?? \Drupal::service('ai_conversation.deepseek_api_service');
    $models = array_values(array_filter($deepseek->getAvailableModels()));
    $provider_config = $this->configFactory->get('ai_conversation.provider_settings');
    $default_model = (string) ($provider_config->get('deepseek_default_model') ?: DeepSeekApiService::DEFAULT_MODEL);

    if ($preferred_model && in_array($preferred_model, $models, TRUE)) {
      return $preferred_model;
    }
    if ($default_model !== '') {
      return $default_model;
    }

    return $models[0] ?? DeepSeekApiService::DEFAULT_MODEL;
  }

  /**
   * Resolves the effective provider for the given uid.
   * Resolution order: user preference → org default → local provider fallback.
   *
   * @return array ['provider' => 'ollama', 'model' => string|NULL]
   */
  public function resolveProvider(int $uid): array {
    $provider_config = $this->configFactory->get('ai_conversation.provider_settings');
    $default_provider = (string) ($provider_config->get('default_provider') ?: 'deepseek');

    // Check user preference via user.data service.
    $ud = $this->userData ?? \Drupal::service('user.data');
    $user_provider = $ud->get('ai_conversation', $uid, 'ai_provider');
    $user_model    = $ud->get('ai_conversation', $uid, 'ai_model');

    if ($user_provider === 'deepseek') {
      return ['provider' => 'deepseek', 'model' => $user_model ?: NULL];
    }

    if ($user_provider === 'ollama') {
      return ['provider' => 'ollama', 'model' => $user_model ?: NULL];
    }

    if ($default_provider === 'ollama') {
      return ['provider' => 'ollama', 'model' => NULL];
    }

    return ['provider' => 'deepseek', 'model' => NULL];
  }

  /**
   * Applies the provider-specific token cap for the current runtime.
   */
  private function applyProviderTokenCap(string $provider, int $requested_max_tokens): int {
    $requested_max_tokens = max(1, $requested_max_tokens);

    if ($provider === 'deepseek') {
      return $requested_max_tokens;
    }

    return min($requested_max_tokens, self::LOCAL_FALLBACK_MAX_TOKENS);
  }

  /**
   * Builds a configured Bedrock runtime client using system config only.
   */
  private function buildBedrockClient(): \Aws\BedrockRuntime\BedrockRuntimeClient {
    $config = $this->configFactory->get('ai_conversation.settings');
    $aws_access_key = $config->get('aws_access_key_id');
    $aws_secret_key = $config->get('aws_secret_access_key');
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

      $messages = $this->buildChatMessages($conversation, $message);
      $system_prompt = $this->buildSystemPrompt($conversation);
      $context = $this->buildPromptPreview($system_prompt, $messages);

      // Estimate input tokens.
      $input_tokens = $this->estimateTokens($context);

      // Debug logging for system prompt
      $this->logInfo('System prompt length: @length, First 100 chars: @preview', [
        '@length' => strlen($system_prompt ?? ''),
        '@preview' => substr($system_prompt ?? 'EMPTY', 0, 100),
      ]);

      // Resolve provider: user preference → org default → local provider.
      $uid = (int) \Drupal::currentUser()->id();
      $resolved = $this->resolveProvider($uid);
      $effective_provider = $resolved['provider'];
      $effective_model    = $resolved['model'];

      $this->logInfo('Effective AI provider: @provider', ['@provider' => $effective_provider]);

      $requested_max_tokens = (int) ($config->get('max_tokens') ?: 50000);
      $max_tokens = $this->applyProviderTokenCap($effective_provider, $requested_max_tokens);
      $start_time = microtime(true);
      $this->logInfo('Token budget resolved for chat message: requested_provider=@provider, requested_max_tokens=@requested, initial_max_tokens=@applied', [
        '@provider' => $effective_provider,
        '@requested' => $requested_max_tokens,
        '@applied' => $max_tokens,
      ]);
      $provider_result = $this->invokeConfiguredChatProvider(
        $effective_provider,
        $effective_model,
        $messages,
        (string) ($system_prompt ?? ''),
        $requested_max_tokens
      );
      $duration_ms = (int)((microtime(true) - $start_time) * 1000);
      $ai_response = $provider_result['text'];
      $model = $provider_result['model'];
      $effective_provider = $provider_result['provider'];
      $max_tokens = (int) ($provider_result['max_tokens'] ?? $max_tokens);
      $output_tokens = $this->estimateTokens($ai_response);
      $this->updateTokenCount($conversation, $input_tokens + $output_tokens);
      $this->trackApiUsage([
        'module' => 'ai_conversation',
        'operation' => 'chat_message',
        'model_id' => ($effective_provider === 'deepseek' ? 'deepseek/' : 'local/') . $model,
        'input_tokens' => $input_tokens,
        'output_tokens' => $output_tokens,
        'stop_reason' => 'stop',
        'duration_ms' => $duration_ms,
        'context_data' => [
          'conversation_id' => $conversation->id(),
          'conversation_title' => $conversation->getTitle(),
          'provider' => $effective_provider,
          'requested_max_tokens' => $requested_max_tokens,
          'max_tokens' => $max_tokens,
        ],
        'success' => TRUE,
        'prompt' => $context,
        'response' => $ai_response,
      ]);

      return $ai_response;
      
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
          'provider' => $effective_provider ?? 'unknown',
          'requested_max_tokens' => $requested_max_tokens ?? NULL,
          'max_tokens' => $max_tokens ?? NULL,
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
   *   - model_id: Runtime model identifier
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
      // Calculate estimated cost based on model-specific pricing
      $model_id = $params['model_id'] ?? '';
      $pricing = $this->getModelPricing($model_id);
      
      if ($pricing) {
        // Dynamic pricing based on actual model
        $input_cost = ($params['input_tokens'] ?? 0) * $pricing['input'] / 1000000;
        $output_cost = ($params['output_tokens'] ?? 0) * $pricing['output'] / 1000000;
      } elseif (strpos($model_id, 'local/') === 0) {
        $input_cost = 0.0;
        $output_cost = 0.0;
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
      
      // Add debugging fields if they exist (schema guard via storage service).
      if ($this->storage->usageTableHasField('success')) {
        $fields['success'] = $success ? 1 : 0;
      }
      if ($this->storage->usageTableHasField('error_message')) {
        $fields['error_message'] = $params['error_message'] ?? NULL;
      }
      if ($this->storage->usageTableHasField('prompt_preview')) {
        $fields['prompt_preview'] = mb_substr((string) $full_prompt, 0, 250);
      }
      if ($this->storage->usageTableHasField('response_preview')) {
        $fields['response_preview'] = mb_substr((string) $full_response, 0, 250);
      }
      
      $this->storage->insertUsageRecord($fields);
        
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
       *   - provider: Override provider ('deepseek'|'ollama')
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
      
      // No cache hit - proceed with the configured provider, falling back to
      // the local model if DeepSeek is unavailable.
      $uid = (int) \Drupal::currentUser()->id();
      $resolved = $this->resolveProvider($uid);
      $provider = (string) ($options['provider'] ?? $resolved['provider'] ?? 'deepseek');
      $preferred_model = (string) ($options['model_id'] ?? $resolved['model'] ?? '');
      $requested_max_tokens = (int) ($options['max_tokens'] ?? 8000);
      $max_tokens = $this->applyProviderTokenCap($provider, $requested_max_tokens);
      
      $this->logInfo('📤 Sending to configured model provider: provider=@provider, requested_max_tokens=@requested_max_tokens, initial_max_tokens=@max_tokens, model=@model, prompt_chars=@prompt_chars', [
        '@provider' => $provider,
        '@requested_max_tokens' => $requested_max_tokens,
        '@max_tokens' => $max_tokens,
        '@model' => $preferred_model !== '' ? $preferred_model : 'auto',
        '@prompt_chars' => strlen($prompt),
      ]);

      $start_time = microtime(TRUE);
      $result = $this->invokeConfiguredChatProvider(
        $provider,
        $preferred_model !== '' ? $preferred_model : NULL,
        [['role' => 'user', 'content' => $prompt]],
        (string) ($options['system_prompt'] ?? ''),
        $requested_max_tokens
      );
      $duration_ms = (int)((microtime(TRUE) - $start_time) * 1000);
      $max_tokens = (int) ($result['max_tokens'] ?? $max_tokens);
      $this->logInfo('📥 Provider response: provider=@provider, output_tokens_estimated=@output, duration_ms=@duration', [
        '@provider' => $result['provider'],
        '@output' => $this->estimateTokens($result['text']),
        '@duration' => $duration_ms,
      ]);

      $ai_response = $result['text'];
      $stop_reason = 'stop';
      $input_tokens = $this->estimateTokens($prompt);
      $output_tokens = $this->estimateTokens($ai_response);
      $context_data_with_config = $context_data + ['requested_max_tokens' => $requested_max_tokens, 'max_tokens' => $max_tokens, 'model_id' => $result['model'], 'provider' => $result['provider']];
      $this->trackApiUsage([
        'module' => $module,
        'operation' => $operation,
        'model_id' => ($result['provider'] === 'deepseek' ? 'deepseek/' : 'local/') . $result['model'],
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
      
    } catch (\Exception $e) {
      $this->logError('Configured model invocation failed: @message', ['@message' => $e->getMessage()]);
      
      // Track failure - exception
      $requested_max_tokens_for_error = (int) ($options['max_tokens'] ?? 8000);
      $uid = (int) \Drupal::currentUser()->id();
      $resolved = $this->resolveProvider($uid);
      $provider = (string) ($options['provider'] ?? $resolved['provider'] ?? 'deepseek');
      $max_tokens_for_error = $this->applyProviderTokenCap($provider, $requested_max_tokens_for_error);
      $model_id = (string) ($options['model_id'] ?? $resolved['model'] ?? ($provider === 'deepseek' ? $this->getDeepSeekModelName() : $this->getLocalModelName()));
      $context_data_with_config = $context_data + ['requested_max_tokens' => $requested_max_tokens_for_error, 'max_tokens' => $max_tokens_for_error, 'model_id' => $model_id, 'provider' => $provider];
      $this->trackApiUsage([
        'module' => $module,
        'operation' => $operation,
        'model_id' => ($provider === 'deepseek' ? 'deepseek/' : 'local/') . $model_id,
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
    return $this->storage->findCachedResponse($module, $operation, $context_data);
  }

  /**
   * Invoke the configured provider, falling back from DeepSeek to local LLM.
   *
   * @return array
   *   ['text' => string, 'model' => string, 'provider' => string, 'max_tokens' => int]
   */
  private function invokeConfiguredChatProvider(string $provider, ?string $preferred_model, array $messages, string $system_prompt, int $max_tokens): array {
    if ($provider === 'deepseek') {
      try {
        $deepseek = $this->deepseekService ?? \Drupal::service('ai_conversation.deepseek_api_service');
        $model = $this->getDeepSeekModelName($preferred_model);
        $deepseek_max_tokens = $this->applyProviderTokenCap('deepseek', $max_tokens);
        $result = $deepseek->chat($model, $messages, $system_prompt, 60, $deepseek_max_tokens);
        return [
          'text' => $result['text'],
          'model' => $result['model'],
          'provider' => 'deepseek',
          'max_tokens' => $deepseek_max_tokens,
        ];
      }
      catch (\Exception $e) {
        $ollama_max_tokens = $this->applyProviderTokenCap('ollama', $max_tokens);
        $this->logWarning('DeepSeek provider failed, falling back to local LLM with max_tokens=@max_tokens: @message', [
          '@message' => $e->getMessage(),
          '@max_tokens' => $ollama_max_tokens,
        ]);
      }
    }

    $ollama = $this->ollamaService ?? \Drupal::service('ai_conversation.ollama_api_service');
    $model = $this->getLocalModelName($provider === 'ollama' ? $preferred_model : NULL);
    $ollama_max_tokens = $this->applyProviderTokenCap('ollama', $max_tokens);
    $result = $ollama->chat($model, $messages, $system_prompt, 60, $ollama_max_tokens);
    return [
      'text' => $result['text'],
      'model' => $result['model'],
      'provider' => 'ollama',
      'max_tokens' => $ollama_max_tokens,
    ];
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
    $count = $this->storage->deleteCachedResponses($module, $operation, $context_data);

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
   * Build the structured system prompt for a conversation.
   */
  private function buildSystemPrompt(NodeInterface $conversation): string {
    $sections = [];
    $base_prompt = trim((string) $this->promptManager->getSystemPrompt(10));
    if ($base_prompt !== '') {
      $sections[] = $base_prompt;
    }

    if ($conversation->hasField('field_context') && !$conversation->get('field_context')->isEmpty()) {
      $conversation_context = trim((string) $conversation->get('field_context')->value);
      if ($conversation_context !== '') {
        $sections[] = "CONVERSATION CONTEXT:\n" . $this->promptManager->normalizePromptText($conversation_context);
      }
    }

    if ($conversation->hasField('field_conversation_summary') && !$conversation->get('field_conversation_summary')->isEmpty()) {
      $summary = trim((string) $conversation->get('field_conversation_summary')->value);
      if ($summary !== '') {
        $sections[] = "CONVERSATION SUMMARY:\n" . $summary;
      }
    }

    return implode("\n\n", $sections);
  }

  /**
   * Build chat messages using stored history without duplicating the latest input.
   */
  private function buildChatMessages(NodeInterface $conversation, string $new_message): array {
    $messages = $this->getRecentMessages($conversation);
    $new_message = trim($new_message);

    if ($new_message !== '' && !$this->hasTrailingCurrentUserMessage($messages, $new_message)) {
      $messages[] = [
        'role' => 'user',
        'content' => $new_message,
      ];
    }

    return array_map(function (array $message): array {
      $role = in_array($message['role'], ['user', 'assistant', 'system'], TRUE) ? $message['role'] : 'user';
      return [
        'role' => $role,
        'content' => (string) $message['content'],
      ];
    }, $messages);
  }

  /**
   * Returns TRUE when the last stored message is the same user prompt.
   */
  private function hasTrailingCurrentUserMessage(array $messages, string $new_message): bool {
    if (empty($messages)) {
      return FALSE;
    }

    $last_message = end($messages);
    return !empty($last_message['role'])
      && $last_message['role'] === 'user'
      && isset($last_message['content'])
      && trim((string) $last_message['content']) === $new_message;
  }

  /**
   * Build a text preview of the full prompt for token estimation/debugging.
   */
  private function buildPromptPreview(string $system_prompt, array $messages): string {
    $parts = [];

    if ($system_prompt !== '') {
      $parts[] = "SYSTEM:\n" . $system_prompt;
    }

    foreach ($messages as $message) {
      $role = strtoupper((string) $message['role']);
      $parts[] = $role . ":\n" . (string) $message['content'];
    }

    return implode("\n\n", $parts);
  }

  /**
   * Build optimized context using the runtime prompt plus recent messages.
   */
  private function buildOptimizedContext(NodeInterface $conversation, string $new_message) {
    return $this->buildPromptPreview(
      $this->buildSystemPrompt($conversation),
      $this->buildChatMessages($conversation, $new_message)
    );
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

      // Generate summary using the local model.
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
      $ollama = $this->ollamaService ?? \Drupal::service('ai_conversation.ollama_api_service');
      $result = $ollama->chat(
        $this->getLocalModelName(),
        [['role' => 'user', 'content' => $context]],
        '',
        60,
        20000
      );

      return $result['text'];
      
    } catch (\Exception $e) {
      $this->logError('Error generating summary: @message', [
        '@message' => $e->getMessage(),
      ]);
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
    $ollama = $this->ollamaService ?? \Drupal::service('ai_conversation.ollama_api_service');
    $result = $ollama->testConnection();

    if ($result['success']) {
      return [
        'success' => TRUE,
        'message' => 'Local LLM connection successful',
        'model' => $result['models'][0] ?? $this->getLocalModelName(),
        'details' => !empty($result['models']) ? implode(', ', $result['models']) : '',
      ];
    }

    return [
      'success' => FALSE,
      'message' => 'Local LLM connection failed',
      'details' => $result['error'] ?? 'Unable to reach the configured local model server.',
    ];
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
   * Extracts, executes, and removes suggestion markup from an AI response.
   *
   * Supports both well-formed blocks with closing tags and malformed blocks
   * where the model emits only the opening tag and payload.
   *
   * @param \Drupal\node\NodeInterface $conversation
   *   The conversation node where the suggestion was made.
   * @param string $ai_response
   *   Raw AI response text.
   * @param string $original_message
   *   The user message that triggered the response.
   *
   * @return array
   *   Array with:
   *   - response: Cleaned user-visible response.
   *   - suggestion_created: TRUE when a suggestion node was created.
   */
  public function processSuggestionMarkup(NodeInterface $conversation, string $ai_response, string $original_message): array {
    if (strpos($ai_response, '[CREATE_SUGGESTION]') === FALSE) {
      return [
        'response' => trim($ai_response),
        'suggestion_created' => FALSE,
      ];
    }

    $default_confirmation = 'Your suggestion has been logged for review. Thank you for the feedback.';
    $open_tag = '[CREATE_SUGGESTION]';
    $close_tag = '[/CREATE_SUGGESTION]';
    $open_pos = strpos($ai_response, $open_tag);
    $payload_start = $open_pos + strlen($open_tag);
    $close_pos = strpos($ai_response, $close_tag, $payload_start);

    $before = trim(substr($ai_response, 0, $open_pos));
    $after = '';
    if ($close_pos !== FALSE) {
      $payload = substr($ai_response, $payload_start, $close_pos - $payload_start);
      $after = trim(substr($ai_response, $close_pos + strlen($close_tag)));
    }
    else {
      $payload = substr($ai_response, $payload_start);
    }

    $summary = '';
    $category = 'general_feedback';
    $original = $original_message;

    if (preg_match('/Summary:\s*(.+?)(?=\nCategory:|$)/s', $payload, $summary_match)) {
      $summary = trim($summary_match[1]);
    }
    if (preg_match('/Category:\s*(\w+)/i', $payload, $category_match)) {
      $category = strtolower(trim($category_match[1]));
    }
    if (preg_match('/Original:\s*(.+?)(?=\n[A-Z][A-Za-z _-]+:|$)/s', $payload, $original_match)) {
      $original = trim($original_match[1]);
    }

    $cleaned_parts = array_values(array_filter([$before, $after], static fn($value) => $value !== ''));
    $cleaned_response = trim(implode("\n\n", $cleaned_parts));
    $has_transcript_wrapper = preg_match('/(^|\n)\s*(User|Assistant|Human|Forseti):/m', $before) || preg_match('/(^|\n)\s*(User|Assistant|Human|Forseti):/m', $after);
    $has_explicit_confirmation = $this->hasExplicitSuggestionConfirmation($original_message);

    $suggestion_created = FALSE;
    if ($summary !== '' && $has_explicit_confirmation && !$has_transcript_wrapper) {
      $suggestion_created = (bool) $this->createSuggestion($conversation, $summary, $original, $category);
    }

    if ($suggestion_created && ($cleaned_response === '' || preg_match('/(^|\n)\s*(User|Assistant|Human|Forseti):/m', $cleaned_response))) {
      $cleaned_response = $default_confirmation;
    }

    return [
      'response' => $cleaned_response !== '' ? $cleaned_response : trim($ai_response),
      'suggestion_created' => $suggestion_created,
    ];
  }

  /**
   * Returns TRUE when the user message explicitly confirms suggestion submission.
   */
  private function hasExplicitSuggestionConfirmation(string $message): bool {
    $normalized = mb_strtolower($message);
    $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
    $normalized = preg_replace('/\s+/u', ' ', trim($normalized));

    if ($normalized === '') {
      return FALSE;
    }

    if (preg_match('/\b(no|not|dont|don t|do not|cancel|stop|wait)\b/u', $normalized)) {
      return FALSE;
    }

    return (bool) preg_match('/^(yes|yep|yeah|sure|ok|okay|correct|confirmed|submit|submit it|please submit|please submit it|please do|go ahead|sounds good|that s correct|that is correct|that s right|that is right|yes submit it|yes please submit it)$/u', $normalized);
  }

}
