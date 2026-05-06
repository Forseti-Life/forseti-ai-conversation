<?php

namespace Drupal\ai_conversation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for AI Conversation settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_conversation_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_conversation.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_conversation.settings');

    $form['api_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Settings'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    $form['api_settings']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens'),
      '#description' => $this->t('Maximum number of tokens to use for API responses.'),
      '#default_value' => $config->get('max_tokens') ?: 20000,
      '#min' => 100,
      '#max' => 8000,
      '#required' => TRUE,
    ];

    $form['summary_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Rolling Summary Settings'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    $form['summary_settings']['max_recent_messages'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Recent Messages'),
      '#description' => $this->t('Maximum number of recent messages to keep in full. Older messages will be summarized.'),
      '#default_value' => $config->get('max_recent_messages') ?: 10,
      '#min' => 5,
      '#max' => 50,
      '#required' => TRUE,
    ];

    $form['summary_settings']['max_tokens_before_summary'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens Before Summary'),
      '#description' => $this->t('Maximum estimated tokens in conversation context before triggering summary update.'),
      '#default_value' => $config->get('max_tokens_before_summary') ?: 6000,
      '#min' => 2000,
      '#max' => 15000,
      '#required' => TRUE,
    ];

    $form['summary_settings']['summary_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Summary Update Frequency'),
      '#description' => $this->t('How often to update conversation summaries.'),
      '#options' => [
        '10' => $this->t('Every 10 messages'),
        '20' => $this->t('Every 20 messages'),
        '30' => $this->t('Every 30 messages'),
        '50' => $this->t('Every 50 messages'),
      ],
      '#default_value' => $config->get('summary_frequency') ?: '20',
      '#required' => TRUE,
    ];

    $form['summary_settings']['enable_auto_summary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Automatic Summary Updates'),
      '#description' => $this->t('Automatically update conversation summaries when thresholds are reached.'),
      '#default_value' => $config->get('enable_auto_summary') ?? TRUE,
    ];

    $form['debug_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Debug Settings'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['debug_settings']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Debug Mode'),
      '#description' => $this->t('Log detailed information about summary generation and token usage.'),
      '#default_value' => $config->get('debug_mode') ?? FALSE,
    ];

    $form['debug_settings']['show_stats'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Conversation Statistics'),
      '#description' => $this->t('Display conversation statistics in the chat interface.'),
      '#default_value' => $config->get('show_stats') ?? TRUE,
    ];

    // Add connection test button.
    $form['connection_test'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Connection Test'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['connection_test']['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test AWS Bedrock Connection'),
      '#ajax' => [
        'callback' => '::testConnection',
        'wrapper' => 'connection-test-result',
        'method' => 'replace',
        'effect' => 'fade',
      ],
    ];

    $form['connection_test']['connection_result'] = [
      '#type' => 'markup',
      '#markup' => '<div id="connection-test-result"></div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback for connection test.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    $ai_service = \Drupal::service('ai_conversation.api_service');
    $result = $ai_service->testConnection();

    $class = $result['success'] ? 'messages messages--status' : 'messages messages--error';
    $icon = $result['success'] ? '✅' : '❌';

    $markup = '<div id="connection-test-result" class="' . $class . '">';
    $markup .= '<strong>' . $icon . ' ' . $result['message'] . '</strong>';
    $markup .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => $markup,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $max_tokens = $form_state->getValue('max_tokens');
    $max_tokens_before_summary = $form_state->getValue('max_tokens_before_summary');

    if ($max_tokens_before_summary <= $max_tokens) {
      $form_state->setErrorByName('max_tokens_before_summary', 
        $this->t('Max tokens before summary must be greater than max tokens for responses.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ai_conversation.settings');

    $config
      ->set('max_tokens', $form_state->getValue('max_tokens'))
      ->set('max_recent_messages', $form_state->getValue('max_recent_messages'))
      ->set('max_tokens_before_summary', $form_state->getValue('max_tokens_before_summary'))
      ->set('summary_frequency', $form_state->getValue('summary_frequency'))
      ->set('enable_auto_summary', $form_state->getValue('enable_auto_summary'))
      ->set('debug_mode', $form_state->getValue('debug_mode'))
      ->set('show_stats', $form_state->getValue('show_stats'))
      ->save();

    parent::submitForm($form, $form_state);

    $this->messenger()->addStatus($this->t('AI Conversation settings have been saved.'));
  }

}
