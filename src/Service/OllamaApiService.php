<?php

namespace Drupal\ai_conversation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for communicating with a self-hosted Ollama LLM instance.
 */
class OllamaApiService {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, ClientInterface $http_client) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ai_conversation');
    $this->httpClient = $http_client;
  }

  /**
   * Returns TRUE if OLLAMA_BASE_URL is configured and non-empty.
   */
  public function isConfigured(): bool {
    $url = $this->getBaseUrl();
    return !empty($url);
  }

  /**
   * Returns the configured Ollama base URL, or empty string if unset.
   */
  public function getBaseUrl(): string {
    $config = $this->configFactory->get('ai_conversation.provider_settings');
    return rtrim((string) ($config->get('ollama_base_url') ?: ''), '/');
  }

  /**
   * Returns the list of available Ollama models from config.
   */
  public function getAvailableModels(): array {
    $config = $this->configFactory->get('ai_conversation.provider_settings');
    $models = $config->get('ollama_available_models') ?: ['llama3'];
    return (array) $models;
  }

  /**
   * Tests connectivity by calling /api/tags on the Ollama server.
   *
   * @return array ['success' => bool, 'error' => string, 'models' => array]
   */
  public function testConnection(): array {
    if (!$this->isConfigured()) {
      return ['success' => FALSE, 'error' => 'OLLAMA_BASE_URL is not configured.', 'models' => []];
    }
    try {
      $response = $this->httpClient->get($this->getBaseUrl() . '/api/tags', ['timeout' => 5]);
      $body = json_decode($response->getBody()->getContents(), TRUE);
      $models = [];
      if (isset($body['models']) && is_array($body['models'])) {
        $models = array_column($body['models'], 'name');
      }
      return ['success' => TRUE, 'error' => '', 'models' => $models];
    }
    catch (ConnectException $e) {
      return ['success' => FALSE, 'error' => 'Connection refused: ' . $e->getMessage(), 'models' => []];
    }
    catch (RequestException $e) {
      return ['success' => FALSE, 'error' => 'Request failed: ' . $e->getMessage(), 'models' => []];
    }
    catch (\Exception $e) {
      return ['success' => FALSE, 'error' => $e->getMessage(), 'models' => []];
    }
  }

  /**
   * Sends a chat request to Ollama /api/chat.
   *
   * @param string $model         Ollama model name (e.g., 'llama3').
   * @param array  $messages      Array of ['role' => ..., 'content' => ...].
   * @param string $system_prompt Optional system prompt prepended as a system message.
   * @param int    $timeout       HTTP timeout in seconds.
   *
   * @return array ['text' => string, 'model' => string] on success.
   *
   * @throws \RuntimeException on connection failure or invalid response.
   */
  public function chat(string $model, array $messages, string $system_prompt = '', int $timeout = 60): array {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('Ollama is not configured. Set OLLAMA_BASE_URL in AI Provider Settings.');
    }

    // Prepend system message if provided.
    $all_messages = [];
    if (!empty($system_prompt)) {
      array_unshift($all_messages, ['role' => 'system', 'content' => $system_prompt]);
    }
    foreach ($messages as $msg) {
      $all_messages[] = $msg;
    }

    $payload = [
      'model' => $model,
      'messages' => $all_messages,
      'stream' => FALSE,
    ];

    try {
      $response = $this->httpClient->post(
        $this->getBaseUrl() . '/api/chat',
        [
          'json' => $payload,
          'timeout' => $timeout,
          'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]
      );
      $body = json_decode($response->getBody()->getContents(), TRUE);
      // Ollama non-streaming response shape: {"message": {"role": "assistant", "content": "..."}, "model": "..."}
      $text = $body['message']['content'] ?? '';
      if ($text === '') {
        throw new \RuntimeException('Empty response from Ollama.');
      }
      return ['text' => $text, 'model' => $body['model'] ?? $model];
    }
    catch (ConnectException $e) {
      throw new \RuntimeException('Ollama unreachable: ' . $e->getMessage(), 0, $e);
    }
    catch (RequestException $e) {
      throw new \RuntimeException('Ollama request failed: ' . $e->getMessage(), 0, $e);
    }
  }

}
