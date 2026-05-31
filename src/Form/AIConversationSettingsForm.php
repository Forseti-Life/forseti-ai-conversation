<?php

namespace Drupal\ai_conversation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_conversation\Service\AIApiServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->aiApiService = $container->get('ai_conversation.ai_api_service');
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

    // Credential status
    $form['credential_status'] = $this->buildCredentialStatus($config);

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
    $status_items = [];

    // Check AWS credentials
    if (!empty($config->get('aws_access_key_id'))) {
      $status_items[] = ['#markup' => $this->t('Using configured AWS credentials')];
    }
    elseif (getenv('AWS_ACCESS_KEY_ID')) {
      $status_items[] = ['#markup' => $this->t('Using AWS_ACCESS_KEY_ID environment variable')];
    }
    else {
      $status_items[] = ['#markup' => $this->t('No AWS credentials found')];
    }

    // Check AWS region
    if ($region = $config->get('aws_region')) {
      $status_items[] = ['#markup' => $this->t('Region from configuration: @region', ['@region' => $region])];
    }
    elseif ($env_region = getenv('AWS_DEFAULT_REGION')) {
      $status_items[] = ['#markup' => $this->t('Region from AWS_DEFAULT_REGION: @region', ['@region' => $env_region])];
    }
    else {
      $status_items[] = ['#markup' => $this->t('Using default region: @region', ['@region' => self::DEFAULT_REGION])];
    }

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
   * Build AWS Bedrock settings fieldset.
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
      '#title' => $this->t('AWS Bedrock Settings'),
      '#description' => $this->t('Configure your AWS credentials to connect to Bedrock AI services. Leave fields empty to use environment variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION). <br><strong>Monitor usage:</strong> <a href="@debug_url">GenAI Debug Inspector</a> | <a href="@usage_url">Usage Dashboard</a> | <a href="@pricing_url">Model Pricing</a>', [
        '@debug_url' => '/admin/reports/genai-debug',
        '@usage_url' => '/admin/reports/genai-usage',
        '@pricing_url' => '/admin/reports/genai-pricing',
      ]),
    ];

    $fieldset['aws_access_key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS Access Key ID'),
      '#default_value' => $config->get('aws_access_key_id'),
      '#description' => $this->t('Your AWS Access Key ID with permissions to use Bedrock. Leave empty to use AWS_ACCESS_KEY_ID environment variable.'),
      '#required' => FALSE,
    ];

    $fieldset['aws_secret_access_key'] = [
      '#type' => 'password',
      '#title' => $this->t('AWS Secret Access Key'),
      '#default_value' => $config->get('aws_secret_access_key'),
      '#description' => $this->t('Your AWS Secret Access Key. Leave blank to keep current value or use AWS_SECRET_ACCESS_KEY environment variable.'),
      '#attributes' => ['autocomplete' => 'off'],
      '#required' => FALSE,
    ];

    $fieldset['aws_region'] = [
      '#type' => 'select',
      '#title' => $this->t('AWS Region'),
      '#default_value' => $config->get('aws_region') ?: self::DEFAULT_REGION,
      '#options' => $this->getAwsRegionOptions(),
      '#description' => $this->t('The AWS region where Bedrock is available.'),
      '#required' => TRUE,
    ];

    $fieldset['aws_model'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Model'),
      '#default_value' => $config->get('aws_model') ?: self::DEFAULT_MODEL,
      '#options' => $this->getModelOptions(),
      '#description' => $this->t('Select the AI model for conversations. Pricing shown as <strong>Input/Output per 1M tokens</strong>. <br><strong>Example:</strong> A 10K input + 5K output request with Sonnet 4.5 costs: (10K × $3 / 1M) + (5K × $15 / 1M) = $0.03 + $0.075 = <strong>$0.105</strong><br><strong>Recommendation:</strong> Sonnet 4.5 offers the best balance for most tasks. Use Haiku 4.5 for high-volume/simple tasks, Opus 4.6 for complex reasoning.<br><a href="@pricing_url" target="_blank">View detailed pricing comparison →</a> | <a href="@debug_url" target="_blank">Monitor actual usage →</a>', [
        '@pricing_url' => '/admin/reports/genai-pricing',
        '@debug_url' => '/admin/reports/genai-debug',
      ]),
      '#required' => TRUE,
    ];

    $fieldset['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Prompt'),
      '#default_value' => $config->get('system_prompt'),
      '#description' => $this->t('The system prompt that defines Forseti\'s Game Master role, voice, and world context.'),
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
      '#open' => FALSE,
      'test_connection' => [
        '#type' => 'button',
        '#value' => $this->t('Test AWS Bedrock Connection'),
        '#ajax' => [
          'callback' => '::testConnection',
          'wrapper' => 'connection-test-result',
          'method' => 'replace',
          'effect' => 'fade',
        ],
      ],
      'connection_result' => [
        '#type' => 'markup',
        '#markup' => '<div id="connection-test-result"></div>',
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
   * Test the AWS Bedrock connection.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Render array for AJAX response.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): array {
    try {
      $result = $this->aiApiService->testConnection();

      $status = $result['success'] ? 'status' : 'error';
      $message_text = $result['message'];

      if ($result['success'] && !empty($result['model'])) {
        $message_text .= ' <strong>Model:</strong> ' . $result['model'];
      }

      if (!$result['success'] && !empty($result['details'])) {
        $message_text .= '<br><strong>Details:</strong> ' . $result['details'];
      }

      $message = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--' . $status . '">' . $message_text . '</div>',
      ];
    }
    catch (\Exception $e) {
      $message = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--error">' .
          $this->t('Connection test failed: @error', ['@error' => $e->getMessage()]) .
          '</div>',
      ];
    }

    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate AWS credentials
    $access_key = $form_state->getValue('aws_access_key_id');
    $secret_key = $form_state->getValue('aws_secret_access_key');
    $has_env_access_key = !empty(getenv('AWS_ACCESS_KEY_ID'));
    $has_env_secret_key = !empty(getenv('AWS_SECRET_ACCESS_KEY'));
    $has_config_access_key = !empty($this->config('ai_conversation.settings')->get('aws_access_key_id'));

    // If access key provided, secret key must also be provided (unless already in config or env)
    if (!empty($access_key) && empty($secret_key) && !$has_config_access_key && !$has_env_secret_key) {
      $form_state->setErrorByName(
        'aws_secret_access_key',
        $this->t('AWS Secret Access Key is required when providing a new Access Key ID.')
      );
    }

    // If neither config values nor environment variables exist, both must be provided
    if (empty($access_key) && empty($secret_key) && !$has_env_access_key && !$has_config_access_key) {
      $form_state->setErrorByName(
        'aws_access_key_id',
        $this->t('AWS credentials must be provided either in the form or via environment variables.')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('ai_conversation.settings');

    // AWS credentials
    $access_key = $form_state->getValue('aws_access_key_id');
    if (!empty($access_key)) {
      $config->set('aws_access_key_id', $access_key);
    }

    $secret_key = $form_state->getValue('aws_secret_access_key');
    if (!empty($secret_key)) {
      $config->set('aws_secret_access_key', $secret_key);
    }

    // AWS settings
    $config->set('aws_region', $form_state->getValue('aws_region'));
    $config->set('aws_model', $form_state->getValue('aws_model'));
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