<?php

namespace Drupal\ai_conversation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai_conversation\Service\DeepSeekApiService;
use Drupal\ai_conversation\Service\OllamaApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin configuration form for the local LLM provider.
 */
class AiProviderSettingsForm extends ConfigFormBase {

  /**
   * @var \Drupal\ai_conversation\Service\OllamaApiService
   */
  protected $ollamaService;

  /**
   * @var \Drupal\ai_conversation\Service\DeepSeekApiService
   */
  protected $deepseekService;

  public function __construct(ConfigFactoryInterface $config_factory, OllamaApiService $ollama_service, DeepSeekApiService $deepseek_service) {
    parent::__construct($config_factory);
    $this->ollamaService = $ollama_service;
    $this->deepseekService = $deepseek_service;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('config.factory'),
      $container->get('ai_conversation.ollama_api_service'),
      $container->get('ai_conversation.deepseek_api_service')
    );
  }

  protected function getEditableConfigNames(): array {
    return ['ai_conversation.provider_settings'];
  }

  public function getFormId(): string {
    return 'ai_conversation_provider_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_conversation.provider_settings');

    $form['#prefix'] = '<div class="ai-provider-settings">';
    $form['#suffix'] = '</div>';

    $form['default_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Primary AI provider'),
      '#options' => [
        'deepseek' => $this->t('DeepSeek (primary)'),
        'ollama' => $this->t('Local LLM only'),
      ],
      '#default_value' => $config->get('default_provider') ?: 'deepseek',
      '#description' => $this->t('DeepSeek is used first when selected here. The local LLM remains the automatic fallback if DeepSeek is unavailable.'),
    ];

    $form['deepseek_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('DeepSeek Configuration'),
    ];

    $form['deepseek_section']['deepseek_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('DeepSeek Base URL'),
      '#description' => $this->t('OpenAI-compatible DeepSeek API base URL.'),
      '#default_value' => $config->get('deepseek_base_url') ?: DeepSeekApiService::DEFAULT_BASE_URL,
      '#placeholder' => DeepSeekApiService::DEFAULT_BASE_URL,
      '#maxlength' => 512,
    ];

    $deepseek_key = trim((string) ($config->get('deepseek_api_key') ?: ''));
    $form['deepseek_section']['deepseek_api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('DeepSeek API Key'),
      '#description' => $this->t('Leave blank to keep the existing active-config value. The DEEPSEEK_API_KEY environment variable overrides this and is preferred for local/private configuration.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $deepseek_models_value = implode("\n", (array) ($config->get('deepseek_available_models') ?: [DeepSeekApiService::DEFAULT_MODEL]));
    $form['deepseek_section']['deepseek_available_models'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Available DeepSeek Models'),
      '#description' => $this->t('One model name per line. The first model is used when no override is provided.'),
      '#default_value' => $deepseek_models_value,
      '#rows' => 4,
    ];

    $form['deepseek_section']['deepseek_default_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default DeepSeek Model'),
      '#default_value' => $config->get('deepseek_default_model') ?: DeepSeekApiService::DEFAULT_MODEL,
      '#placeholder' => DeepSeekApiService::DEFAULT_MODEL,
    ];

    $deepseek_test = $this->deepseekService->testConnection();
    if ($deepseek_test['success']) {
      $status_msg = $this->t('✅ Connected. Detected DeepSeek models: @models', [
        '@models' => !empty($deepseek_test['models']) ? implode(', ', $deepseek_test['models']) : $this->t('(none listed)'),
      ]);
      $form['deepseek_section']['connection_status'] = ['#markup' => '<p style="color:green;">' . $status_msg . '</p>'];
    }
    elseif ($deepseek_key !== '' || getenv('DEEPSEEK_API_KEY')) {
      $status_msg = $this->t('⚠️ Cannot reach DeepSeek: @error', ['@error' => $deepseek_test['error']]);
      $form['deepseek_section']['connection_status'] = ['#markup' => '<p style="color:orange;">' . $status_msg . '</p>'];
    }
    else {
      $form['deepseek_section']['connection_status'] = ['#markup' => '<p style="color:orange;">' . $this->t('DeepSeek API key not configured yet.') . '</p>'];
    }

    $form['fallback_notice'] = [
      '#type' => 'item',
      '#title' => $this->t('Fallback behavior'),
      '#markup' => $this->t('If DeepSeek fails or is not configured, the site falls back to the local LLM automatically.'),
    ];

    $form['ollama_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Local LLM Fallback Configuration'),
    ];

    $form['ollama_section']['ollama_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Local LLM Base URL'),
      '#description' => $this->t('Base URL of the local llama.cpp/OpenAI-compatible server (for example <code>http://127.0.0.1:8080</code>).'),
      '#default_value' => $config->get('ollama_base_url') ?: OllamaApiService::DEFAULT_BASE_URL,
      '#placeholder' => OllamaApiService::DEFAULT_BASE_URL,
      '#maxlength' => 512,
    ];

    // Show connection status if URL is set.
    $current_url = $config->get('ollama_base_url') ?: OllamaApiService::DEFAULT_BASE_URL;
    if (!empty($current_url)) {
      $test = $this->ollamaService->testConnection();
      if ($test['success']) {
        $status_msg = $this->t('✅ Connected. Detected models on server: @models', [
          '@models' => !empty($test['models']) ? implode(', ', $test['models']) : $this->t('(none listed)'),
        ]);
        $form['ollama_section']['connection_status'] = ['#markup' => '<p style="color:green;">' . $status_msg . '</p>'];
      }
      else {
        $status_msg = $this->t('⚠️ Cannot reach local LLM: @error', ['@error' => $test['error']]);
        $form['ollama_section']['connection_status'] = ['#markup' => '<p style="color:orange;">' . $status_msg . '</p>'];
      }
    }

    $models_value = implode("\n", (array) ($config->get('ollama_available_models') ?: [OllamaApiService::DEFAULT_MODEL]));
    $form['ollama_section']['ollama_available_models'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Available Local Models'),
      '#description' => $this->t('One model name per line. Users will choose from this list when selecting the local provider.'),
      '#default_value' => $models_value,
      '#rows' => 6,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $deepseek_url = trim((string) $form_state->getValue('deepseek_base_url'));
    $ollama_url = trim($form_state->getValue('ollama_base_url'));

    if (!empty($deepseek_url) && (!filter_var($deepseek_url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $deepseek_url))) {
      $form_state->setErrorByName('deepseek_section][deepseek_base_url', $this->t('DeepSeek Base URL must be a valid http:// or https:// URL.'));
    }

    if (empty($ollama_url)) {
      $form_state->setErrorByName('ollama_base_url', $this->t('Local LLM Base URL is required.'));
    }

    if (!empty($ollama_url)) {
      // Validate URL structure (scheme + host).
      if (!filter_var($ollama_url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $ollama_url)) {
        $form_state->setErrorByName('ollama_section][ollama_base_url', $this->t('Local LLM Base URL must be a valid http:// or https:// URL.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $deepseek_models_raw = $form_state->getValue('deepseek_available_models');
    $deepseek_models = array_values(array_filter(array_map('trim', explode("\n", $deepseek_models_raw))));
    $existing_api_key = (string) $this->config('ai_conversation.provider_settings')->get('deepseek_api_key');
    $submitted_api_key = trim((string) $form_state->getValue('deepseek_api_key'));
    $models_raw = $form_state->getValue('ollama_available_models');
    $models = array_values(array_filter(array_map('trim', explode("\n", $models_raw))));

    $this->config('ai_conversation.provider_settings')
      ->set('default_provider', (string) $form_state->getValue('default_provider'))
      ->set('deepseek_base_url', trim((string) $form_state->getValue('deepseek_base_url')))
      ->set('deepseek_api_key', $submitted_api_key !== '' ? $submitted_api_key : $existing_api_key)
      ->set('deepseek_default_model', trim((string) $form_state->getValue('deepseek_default_model')) ?: DeepSeekApiService::DEFAULT_MODEL)
      ->set('deepseek_available_models', $deepseek_models)
      ->set('ollama_base_url', trim($form_state->getValue('ollama_base_url')))
      ->set('ollama_available_models', $models)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
