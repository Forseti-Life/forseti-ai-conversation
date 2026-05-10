<?php

namespace Drupal\ai_conversation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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

  public function __construct(ConfigFactoryInterface $config_factory, OllamaApiService $ollama_service) {
    parent::__construct($config_factory);
    $this->ollamaService = $ollama_service;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('config.factory'),
      $container->get('ai_conversation.ollama_api_service')
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

    $form['default_provider_notice'] = [
      '#type' => 'item',
      '#title' => $this->t('Default AI provider'),
      '#markup' => $this->t('Local LLM is the only active provider. Bedrock integration is disabled.'),
    ];
    $form['default_provider'] = [
      '#type' => 'hidden',
      '#value' => 'ollama',
    ];

    $form['ollama_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Local LLM Configuration'),
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
    $ollama_url = trim($form_state->getValue('ollama_base_url'));

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
    $models_raw = $form_state->getValue('ollama_available_models');
    $models = array_values(array_filter(array_map('trim', explode("\n", $models_raw))));

    $this->config('ai_conversation.provider_settings')
      ->set('default_provider', 'ollama')
      ->set('ollama_base_url', trim($form_state->getValue('ollama_base_url')))
      ->set('ollama_available_models', $models)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
