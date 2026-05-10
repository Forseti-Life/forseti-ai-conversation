<?php

namespace Drupal\ai_conversation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_conversation\Service\AIApiServiceInterface;
use Drupal\ai_conversation\Service\OllamaApiService;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Configure AI Conversation settings.
 */
class AIConversationSettingsForm extends ConfigFormBase {

  /**
   * Default values for various settings.
   */
  const DEFAULT_MAX_TOKENS = 30000;
  const DEFAULT_MAX_TOKENS_RESUME_TAILORING = 30000;
  const DEFAULT_MAX_TOKENS_RESUME_PARSING = 30000;
  const DEFAULT_MAX_TOKENS_COVER_LETTER = 30000;
  const DEFAULT_MAX_TOKENS_JOB_PARSING = 30000;
  const DEFAULT_MAX_RECENT_MESSAGES = 20;
  const DEFAULT_SUMMARY_FREQUENCY = 10;
  const DEFAULT_MAX_TOKENS_BEFORE_SUMMARY = 6000;
  const DEFAULT_REGION = 'us-west-2';
  const DEFAULT_MODEL = 'us.anthropic.claude-sonnet-4-5-20250929-v1:0';
  const DEFAULT_SYSTEM_PROMPT_ROWS = 15;

  /**
   * Min/Max limits for token settings.
   */
  const MIN_DEFAULT_TOKENS = 100;
  const MAX_DEFAULT_TOKENS = 100000;
  const MIN_RESUME_TAILORING_TOKENS = 8000;
  const MIN_RESUME_PARSING_TOKENS = 5000;
  const MIN_COVER_LETTER_TOKENS = 1000;
  const MAX_COVER_LETTER_TOKENS = 50000;
  const MIN_JOB_PARSING_TOKENS = 2000;
  const MAX_JOB_PARSING_TOKENS = 50000;
  const MIN_RECENT_MESSAGES = 5;
  const MAX_RECENT_MESSAGES = 50;
  const MIN_SUMMARY_TOKENS = 2000;
  const MAX_SUMMARY_TOKENS = 15000;

  /**
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiServiceInterface
   */
  protected $aiApiService;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->aiApiService = $container->get('ai_conversation.ai_api_service');
    $instance->logger = $container->get('logger.channel.ai_conversation');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_conversation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_conversation_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_conversation.settings');

    // Version debug display
    $form['version_debug'] = [
      '#type' => 'markup',
      '#markup' => '<div style="background: #fff3cd; border: 2px solid #ff9800; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; color: #ff6f00;">⚙️ FORM VERSION: 2025-02-27-v5 (Debug: Changes should appear here)</div>',
      '#weight' => -100,
    ];

    // Credential status
    $form['credential_status'] = $this->buildCredentialStatus($config);

    // Connection test result status
    $form['test_result_status'] = $this->buildTestResultStatus();

    // AWS Bedrock settings
    $form['aws_settings'] = $this->buildAwsSettings($config);

    // Conversation settings
    $form['conversation_settings'] = $this->buildConversationSettings($config);

    // Operation-specific token limits
    $form['operation_tokens'] = $this->buildOperationTokenSettings($config);

    // Debug settings
    $form['debug_settings'] = $this->buildDebugSettings($config);

    // Connection test
    $form['connection_test'] = $this->buildConnectionTest();

    return parent::buildForm($form, $form_state);
  }

  /**
   * Build credential status display.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object.
   *
   * @return array
   *   Form element array.
   */
  protected function buildCredentialStatus($config): array {
    $provider_config = \Drupal::config('ai_conversation.provider_settings');
    $ollama_service = \Drupal::service('ai_conversation.ollama_api_service');
    $status_items = [];

    $status_items[] = ['#markup' => $this->t('Active provider: Local LLM')];
    $status_items[] = ['#markup' => $this->t('Endpoint: @url', ['@url' => $ollama_service->getBaseUrl()])];
    $status_items[] = ['#markup' => $this->t('Configured models: @models', [
      '@models' => implode(', ', (array) ($provider_config->get('ollama_available_models') ?: [OllamaApiService::DEFAULT_MODEL])),
    ])];
    $status_items[] = ['#markup' => $this->t('Bedrock integration is disabled.')];

    return [
      '#type' => 'item',
      '#title' => $this->t('Current Status'),
      '#theme' => 'item_list',
      '#list_type' => 'ul',
      '#items' => $status_items,
      '#wrapper_attributes' => ['class' => ['messages', 'messages--status']],
    ];
  }

  /**
   * Build test result status display.
   *
   * @return array
   *   Form element array.
   */
  protected function buildTestResultStatus(): array {
    $last_status = \Drupal::state()->get('ai_conversation.last_connection_test_status', 'Connection: NOT TESTED');
    $last_details = \Drupal::state()->get('ai_conversation.last_connection_test_details', '');

    $markup = '<div id="aws-test-result-status">' . htmlspecialchars($last_status) . '</div>';
    if (!empty($last_details)) {
      $markup .= '<div style="margin-top:8px;" id="aws-test-result-details">' . htmlspecialchars($last_details) . '</div>';
    }

    return [
      '#type' => 'markup',
      '#markup' => $markup,
      '#weight' => -50,
    ];
  }

  /**
   * Build model settings fieldset.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object.
   *
   * @return array
   *   Form element array.
   */
  protected function buildAwsSettings($config): array {
    $fieldset = [
      '#type' => 'fieldset',
      '#title' => $this->t('Model Settings'),
      '#description' => $this->t('Conversation traffic uses the local LLM configured on the <a href="@provider_url">AI Provider Settings</a> page. This form now manages shared prompt and token settings only. <br><strong>Monitor usage:</strong> <a href="@debug_url">GenAI Debug Inspector</a> | <a href="@usage_url">Usage Dashboard</a>', [
        '@provider_url' => '/admin/config/forseti/ai-provider',
        '@debug_url' => '/admin/reports/genai-debug',
        '@usage_url' => '/admin/reports/genai-usage',
      ]),
    ];

    $fieldset['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Prompt'),
      '#default_value' => $config->get('system_prompt'),
      '#description' => $this->t('The system prompt that defines the AI assistant\'s role, personality, and knowledge context.'),
      '#rows' => self::DEFAULT_SYSTEM_PROMPT_ROWS,
      '#required' => FALSE,
    ];

    return $fieldset;
  }

  /**
   * Build conversation settings fieldset.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object.
   *
   * @return array
   *   Form element array.
   */
  protected function buildConversationSettings($config): array {
    $fieldset = [
      '#type' => 'fieldset',
      '#title' => $this->t('Conversation Settings'),
    ];

    $fieldset['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Tokens (Default)'),
      '#default_value' => $config->get('max_tokens') ?: self::DEFAULT_MAX_TOKENS,
      '#description' => $this->t('Default maximum number of tokens for AI responses. Used when no operation-specific limit is set.'),
      '#min' => self::MIN_DEFAULT_TOKENS,
      '#max' => self::MAX_DEFAULT_TOKENS,
      '#required' => TRUE,
    ];

    $fieldset['max_recent_messages'] = [
      '#type' => 'number',
      '#title' => $this->t('Recent Messages'),
      '#default_value' => $config->get('max_recent_messages') ?: self::DEFAULT_MAX_RECENT_MESSAGES,
      '#description' => $this->t('Number of recent messages to keep in memory.'),
      '#min' => self::MIN_RECENT_MESSAGES,
      '#max' => self::MAX_RECENT_MESSAGES,
      '#required' => TRUE,
    ];

    $fieldset['summary_frequency'] = [
      '#type' => 'number',
      '#title' => $this->t('Summary Frequency'),
      '#default_value' => $config->get('summary_frequency') ?: self::DEFAULT_SUMMARY_FREQUENCY,
      '#description' => $this->t('Create a summary every N messages.'),
      '#min' => self::MIN_RECENT_MESSAGES,
      '#max' => self::MAX_RECENT_MESSAGES,
      '#required' => TRUE,
    ];

    $fieldset['max_tokens_before_summary'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens Before Summary'),
      '#description' => $this->t('Maximum estimated tokens in conversation context before triggering summary update.'),
      '#default_value' => $config->get('max_tokens_before_summary') ?: self::DEFAULT_MAX_TOKENS_BEFORE_SUMMARY,
      '#min' => self::MIN_SUMMARY_TOKENS,
      '#max' => self::MAX_SUMMARY_TOKENS,
      '#required' => TRUE,
    ];

    $fieldset['enable_auto_summary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Automatic Summary Updates'),
      '#description' => $this->t('Automatically update conversation summaries when thresholds are reached.'),
      '#default_value' => $config->get('enable_auto_summary') ?? TRUE,
    ];

    return $fieldset;
  }

  /**
   * Build operation-specific token limits.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object.
   *
   * @return array
   *   Form element array.
   */
  protected function buildOperationTokenSettings($config): array {
    $operations = $this->getOperationTokenDefinitions();

    $fieldset = [
      '#type' => 'details',
      '#title' => $this->t('Operation-Specific Token Limits'),
      '#description' => $this->t('Configure maximum tokens for specific operations. These override the default max_tokens setting for their respective operations.'),
      '#open' => TRUE,
    ];

    foreach ($operations as $key => $operation) {
      $fieldset[$key] = [
        '#type' => 'number',
        '#title' => $this->t($operation['title']),
        '#default_value' => $config->get($key) ?: $operation['default'],
        '#description' => $this->t($operation['description']),
        '#min' => $operation['min'],
        '#max' => $operation['max'],
        '#required' => TRUE,
      ];
    }

    return $fieldset;
  }

  /**
   * Build debug settings.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object.
   *
   * @return array
   *   Form element array.
   */
  protected function buildDebugSettings($config): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Debug Settings'),
      '#open' => FALSE,
      'debug_mode' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable Debug Mode'),
        '#description' => $this->t('Log detailed information about summary generation and token usage.'),
        '#default_value' => $config->get('debug_mode') ?? FALSE,
      ],
      'show_stats' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show Conversation Statistics'),
        '#description' => $this->t('Display conversation statistics in the chat interface.'),
        '#default_value' => $config->get('show_stats') ?? TRUE,
      ],
    ];
  }

  /**
   * Build connection test section.
   *
   * @return array
   *   Form element array.
   */
  protected function buildConnectionTest(): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('Connection Test'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['ai-connection-test']],
      'instructions' => [
        '#type' => 'markup',
        '#markup' => '<p style="margin-bottom: 15px;"><strong>Click the button below to verify your local LLM connection.</strong> You will see a clear <strong style="color: green;">✅ PASS</strong> or <strong style="color: red;">❌ FAIL</strong> message.</p>',
      ],
      'test_connection' => [
        '#type' => 'submit',
        '#name' => 'test_connection_btn',
        '#value' => $this->t('🔌 Test Local LLM Connection'),
        '#submit' => ['::testConnectionSubmit'],
        '#validate' => [],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::testConnectionAjax',
          'event' => 'click',
          'wrapper' => 'connection-test-result',
          'method' => 'html',
          'effect' => 'fade',
          'progress' => [
            'type' => 'throbber',
            'message' => $this->t('🔄 Testing connection... (this may take up to 15 seconds)'),
          ],
        ],
        '#attributes' => [
          'class' => ['button', 'button--primary', 'test-connection-btn'],
          'id' => 'test-connection-btn',
          'style' => 'padding: 12px 24px; font-size: 16px;',
        ],
      ],
      'connection_status' => [
        '#type' => 'markup',
        '#markup' => '<div id="connection-test-result" style="margin-top: 20px; min-height: 60px; padding: 15px; border-radius: 4px; border: 2px solid #ddd; background-color: #f9f9f9;" class="connection-test-status"></div>',
      ],
      'test_note' => [
        '#type' => 'markup',
        '#markup' => '<p style="margin-top: 15px; font-size: 12px; color: #666;"><strong>Note:</strong> The first test may take a few seconds while the local model warms up.</p>',
      ],
    ];
  }

  /**
   * Get AWS region options.
   *
   * @return array
   *   Array of region options.
   */
  protected function getAwsRegionOptions(): array {
    return [
      // Americas
      'us-east-1' => 'US East (N. Virginia)',
      'us-east-2' => 'US East (Ohio)',
      'us-west-1' => 'US West (N. California)',
      'us-west-2' => 'US West (Oregon)',
      'ca-central-1' => 'Canada (Central)',
      'ca-west-1' => 'Canada (Calgary)',
      'sa-east-1' => 'South America (São Paulo)',
      'mx-central-1' => 'Mexico (Central)',
      
      // Europe
      'eu-central-1' => 'Europe (Frankfurt)',
      'eu-central-2' => 'Europe (Zurich)',
      'eu-west-1' => 'Europe (Ireland)',
      'eu-west-2' => 'Europe (London)',
      'eu-west-3' => 'Europe (Paris)',
      'eu-north-1' => 'Europe (Stockholm)',
      'eu-south-1' => 'Europe (Milan)',
      'eu-south-2' => 'Europe (Spain)',
      
      // Asia Pacific
      'ap-northeast-1' => 'Asia Pacific (Tokyo)',
      'ap-northeast-2' => 'Asia Pacific (Seoul)',
      'ap-northeast-3' => 'Asia Pacific (Osaka)',
      'ap-south-1' => 'Asia Pacific (Mumbai)',
      'ap-south-2' => 'Asia Pacific (Hyderabad)',
      'ap-southeast-1' => 'Asia Pacific (Singapore)',
      'ap-southeast-2' => 'Asia Pacific (Sydney)',
      'ap-southeast-3' => 'Asia Pacific (Jakarta)',
      'ap-southeast-4' => 'Asia Pacific (Melbourne)',
      'ap-southeast-5' => 'Asia Pacific (Malaysia)',
      'ap-southeast-7' => 'Asia Pacific (Thailand)',
      'ap-east-2' => 'Asia Pacific (Hong Kong)',
      
      // Middle East & Africa
      'me-central-1' => 'Middle East (UAE)',
      'me-south-1' => 'Middle East (Bahrain)',
      'il-central-1' => 'Israel (Tel Aviv)',
      'af-south-1' => 'Africa (Cape Town)',
      
      // AWS GovCloud
      'us-gov-west-1' => 'AWS GovCloud (US-West)',
      'us-gov-east-1' => 'AWS GovCloud (US-East)',
    ];
  }

  /**
   * Get AI model options with pricing information.
   *
   * Output Token Limits:
   * - Claude 4.x models: Check model-specific documentation
   * - Claude 3.x models: Typically 4,096 tokens (batching required for larger outputs)
   * - Claude 2.x models: 4,096 tokens
   *
   * Pricing shown as: Input/Output per 1M tokens
   *
   * @return array
   *   Array of model options with pricing.
   */
  protected function getModelOptions(): array {
    return [
      // Claude 4 Models (Latest Generation)
      'anthropic.claude-opus-4-6-v1' => 'Claude Opus 4.6 — $15/$75 per 1M tokens (Most Capable - Latest)',
      'anthropic.claude-sonnet-4-5-20250929-v1:0' => 'Claude Sonnet 4.5 — $3/$15 per 1M tokens (Best Balance - Recommended)',
      'anthropic.claude-sonnet-4-20250514-v1:0' => 'Claude Sonnet 4.0 — $3/$15 per 1M tokens',
      'anthropic.claude-opus-4-5-20251101-v1:0' => 'Claude Opus 4.5 — $15/$75 per 1M tokens',
      'anthropic.claude-opus-4-1-20250805-v1:0' => 'Claude Opus 4.1 — $15/$75 per 1M tokens',
      'anthropic.claude-haiku-4-5-20251001-v1:0' => 'Claude Haiku 4.5 — $1/$5 per 1M tokens (Fastest & Cheapest)',
      
      // Claude 3.5 Models (Previous generation - Still excellent)
      'anthropic.claude-3-5-sonnet-20241022-v2:0' => 'Claude 3.5 Sonnet v2 — $3/$15 per 1M tokens',
      'anthropic.claude-3-5-sonnet-20240620-v1:0' => 'Claude 3.5 Sonnet v1 — $3/$15 per 1M tokens (EOL — do not use)',
      'anthropic.claude-3-5-haiku-20241022-v1:0' => 'Claude 3.5 Haiku — $0.25/$1.25 per 1M tokens (Very Affordable)',
      
      // Claude 3 Models (Stable & Reliable)
      'anthropic.claude-3-opus-20240229-v1:0' => 'Claude 3 Opus — $15/$75 per 1M tokens',
      'anthropic.claude-3-sonnet-20240229-v1:0' => 'Claude 3 Sonnet — $3/$15 per 1M tokens',
      'anthropic.claude-3-haiku-20240307-v1:0' => 'Claude 3 Haiku — $0.25/$1.25 per 1M tokens',
      
      // Claude 2 Models (Legacy - Not Recommended)
      'anthropic.claude-v2:1' => 'Claude 2.1 — ~$8/$24 per 1M tokens (Legacy)',
      'anthropic.claude-v2' => 'Claude 2.0 — ~$8/$24 per 1M tokens (Legacy)',
      'anthropic.claude-instant-v1' => 'Claude Instant — ~$0.80/$2.40 per 1M tokens (Legacy)',
    ];
  }

  /**
   * Get operation token definitions.
   *
   * @return array
   *   Array of operation configurations.
   */
  protected function getOperationTokenDefinitions(): array {
    return [
      'max_tokens_resume_tailoring' => [
        'title' => 'Resume Tailoring',
        'description' => 'Maximum tokens for resume tailoring operations. Needs large output for complete resume JSON.',
        'default' => self::DEFAULT_MAX_TOKENS_RESUME_TAILORING,
        'min' => self::MIN_RESUME_TAILORING_TOKENS,
        'max' => self::MAX_DEFAULT_TOKENS,
      ],
      'max_tokens_resume_parsing' => [
        'title' => 'Resume Parsing',
        'description' => 'Maximum tokens for parsing and extracting resume data.',
        'default' => self::DEFAULT_MAX_TOKENS_RESUME_PARSING,
        'min' => self::MIN_RESUME_PARSING_TOKENS,
        'max' => self::MAX_DEFAULT_TOKENS,
      ],
      'max_tokens_cover_letter' => [
        'title' => 'Cover Letter Generation',
        'description' => 'Maximum tokens for generating cover letters.',
        'default' => self::DEFAULT_MAX_TOKENS_COVER_LETTER,
        'min' => self::MIN_COVER_LETTER_TOKENS,
        'max' => self::MAX_COVER_LETTER_TOKENS,
      ],
      'max_tokens_job_parsing' => [
        'title' => 'Job Parsing',
        'description' => 'Maximum tokens for parsing job descriptions and requirements.',
        'default' => self::DEFAULT_MAX_TOKENS_JOB_PARSING,
        'min' => self::MIN_JOB_PARSING_TOKENS,
        'max' => self::MAX_JOB_PARSING_TOKENS,
      ],
    ];
  }

  /**
   * AJAX callback for testing local LLM connection.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   AJAX response with connection test result.
   */
  public function testConnectionAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $this->logger->info('Local LLM connection test initiated');
    
    try {
      $result = $form_state->get('connection_test_result');
      if (!$result) {
        $this->logger->info('Calling AIApiService::testConnection() from AJAX callback');
        $result = $this->aiApiService->testConnection();
      }
      $this->logger->info('Connection test result: @result', ['@result' => json_encode($result)]);

      if ($result['success']) {
        $status_html = 'Connection: PASS';
        $detail_html = 'PASS - ' . htmlspecialchars($result['message']);
        if (!empty($result['model'])) {
          $detail_html .= ' (Model: ' . htmlspecialchars($result['model']) . ')';
        }
        $this->logger->notice('Local LLM connection test PASSED');
      } else {
        $message = (string) ($result['message'] ?? 'Connection failed');
        $is_timeout = stripos($message, 'timeout') !== FALSE;
        $status_html = $is_timeout ? 'Connection: FAIL (TIMEOUT)' : 'Connection: FAIL';
        $detail_html = 'FAIL - ' . htmlspecialchars($message);
        if (!empty($result['details'])) {
          $detail_html .= ' Details: ' . htmlspecialchars($result['details']);
        }
        $this->logger->error('Local LLM connection test FAILED: @message', ['@message' => $message]);
      }
    }
    catch (\Exception $e) {
      $error_message = (string) $e->getMessage();
      $is_timeout = stripos($error_message, 'timeout') !== FALSE;
      $status_html = $is_timeout ? 'Connection: FAIL (TIMEOUT)' : 'Connection: FAIL';
      $detail_html = 'FAIL - ' . htmlspecialchars($error_message);
      $this->logger->error('Local LLM connection test FAILED with exception: @error', ['@error' => $error_message]);
    }

    \Drupal::state()->set('ai_conversation.last_connection_test_status', $status_html);
    \Drupal::state()->set('ai_conversation.last_connection_test_details', $detail_html);
    
    // Update both the status area at top AND the detailed results below
    $response->addCommand(new HtmlCommand('#aws-test-result-status', $status_html));
    $response->addCommand(new HtmlCommand('#connection-test-result', $detail_html));
    return $response;
  }

  /**
   * Submit handler for AJAX connection test button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function testConnectionSubmit(array &$form, FormStateInterface $form_state): void {
    $this->logger->info('Local LLM connection test button clicked from UI (submit handler)');

    try {
      $result = $this->aiApiService->testConnection();
      $form_state->set('connection_test_result', $result);

      if (!empty($result['success'])) {
        $status = 'Connection: PASS';
        $details = 'PASS - ' . ($result['message'] ?? 'Connection successful');
      }
      else {
        $message = (string) ($result['message'] ?? 'Connection failed');
        $is_timeout = stripos($message, 'timeout') !== FALSE;
        $status = $is_timeout ? 'Connection: FAIL (TIMEOUT)' : 'Connection: FAIL';
        $details = 'FAIL - ' . $message;
        if (!empty($result['details'])) {
          $details .= ' Details: ' . $result['details'];
        }
      }

      \Drupal::state()->set('ai_conversation.last_connection_test_status', $status);
      \Drupal::state()->set('ai_conversation.last_connection_test_details', $details);
      $this->logger->info('Connection test submit handler status: @status', ['@status' => $status]);
    }
    catch (\Exception $e) {
      $message = (string) $e->getMessage();
      $is_timeout = stripos($message, 'timeout') !== FALSE;
      $status = $is_timeout ? 'Connection: FAIL (TIMEOUT)' : 'Connection: FAIL';
      $details = 'FAIL - ' . $message;
      \Drupal::state()->set('ai_conversation.last_connection_test_status', $status);
      \Drupal::state()->set('ai_conversation.last_connection_test_details', $details);
      $this->logger->error('Connection test submit handler exception: @message', ['@message' => $message]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $trigger_name = $trigger['#name'] ?? 'unknown';
    $this->logger->info('AI settings submitForm triggered by: @trigger', ['@trigger' => $trigger_name]);

    if ($trigger_name === 'test_connection_btn') {
      $this->logger->info('Skipping config save during connection test button submit');
      return;
    }

    $config = $this->config('ai_conversation.settings');

    $config->set('system_prompt', $form_state->getValue('system_prompt'));

    // Conversation settings
    $config->set('max_tokens', $form_state->getValue('max_tokens'));
    $config->set('max_recent_messages', $form_state->getValue('max_recent_messages'));
    $config->set('summary_frequency', $form_state->getValue('summary_frequency'));
    $config->set('max_tokens_before_summary', $form_state->getValue('max_tokens_before_summary'));
    $config->set('enable_auto_summary', $form_state->getValue('enable_auto_summary'));

    // Operation-specific token limits
    $operations = $this->getOperationTokenDefinitions();
    foreach (array_keys($operations) as $operation_key) {
      $config->set($operation_key, $form_state->getValue($operation_key));
    }

    // Debug settings
    $config->set('debug_mode', $form_state->getValue('debug_mode'));
    $config->set('show_stats', $form_state->getValue('show_stats'));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
