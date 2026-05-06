<?php

namespace Drupal\ai_conversation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai_conversation\Service\OllamaApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin configuration form for AI provider selection (Bedrock vs Ollama).
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

    $form['default_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Default AI provider'),
      '#description' => $this->t('The org-wide default provider used when users have no personal preference.'),
      '#options' => [
        'bedrock' => $this->t('AWS Bedrock (default)'),
        'ollama' => $this->t('Ollama (self-hosted local LLM)'),
      ],
      '#default_value' => $config->get('default_provider') ?: 'bedrock',
      '#required' => TRUE,
    ];

    $form['ollama_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Ollama Configuration'),
    ];

    $form['ollama_section']['ollama_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ollama Base URL'),
      '#description' => $this->t('Base URL of the Ollama instance (e.g., <code>http://localhost:11434</code>). Leave empty to disable Ollama.'),
      '#default_value' => $config->get('ollama_base_url') ?: '',
      '#placeholder' => 'http://localhost:11434',
      '#maxlength' => 512,
    ];

    // Show connection status if URL is set.
    $current_url = $config->get('ollama_base_url');
    if (!empty($current_url)) {
      $test = $this->ollamaService->testConnection();
      if ($test['success']) {
        $status_msg = $this->t('✅ Connected. Detected models on server: @models', [
          '@models' => !empty($test['models']) ? implode(', ', $test['models']) : $this->t('(none listed)'),
        ]);
        $form['ollama_section']['connection_status'] = ['#markup' => '<p style="color:green;">' . $status_msg . '</p>'];
      }
      else {
        $status_msg = $this->t('⚠️ Cannot reach Ollama: @error', ['@error' => $test['error']]);
        $form['ollama_section']['connection_status'] = ['#markup' => '<p style="color:orange;">' . $status_msg . '</p>'];
      }
    }

    $models_value = implode("\n", (array) ($config->get('ollama_available_models') ?: ['llama3', 'llama3.1', 'mistral', 'gemma2']));
    $form['ollama_section']['ollama_available_models'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Available Ollama Models'),
      '#description' => $this->t('One model name per line. Users will choose from this list when selecting Ollama as their provider.'),
      '#default_value' => $models_value,
      '#rows' => 6,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $provider = $form_state->getValue('default_provider');
    $ollama_url = trim($form_state->getValue('ollama_base_url'));

    if ($provider === 'ollama' && empty($ollama_url)) {
      $form_state->setErrorByName('ollama_base_url', $this->t('Ollama Base URL is required when Ollama is set as the default provider.'));
    }

    if (!empty($ollama_url)) {
      // Validate URL structure (scheme + host).
      if (!filter_var($ollama_url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $ollama_url)) {
        $form_state->setErrorByName('ollama_section][ollama_base_url', $this->t('Ollama Base URL must be a valid http:// or https:// URL.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $models_raw = $form_state->getValue('ollama_available_models');
    $models = array_values(array_filter(array_map('trim', explode("\n", $models_raw))));

    $this->config('ai_conversation.provider_settings')
      ->set('default_provider', $form_state->getValue('default_provider'))
      ->set('ollama_base_url', trim($form_state->getValue('ollama_base_url')))
      ->set('ollama_available_models', $models)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
