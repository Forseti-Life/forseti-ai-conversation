<?php

namespace Drupal\ai_conversation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for communicating with a self-hosted OpenAI-compatible local LLM.
 *
 * The service name remains unchanged for backwards compatibility with existing
 * container wiring and configuration keys.
 */
class OllamaApiService {

  public const DEFAULT_BASE_URL = 'http://127.0.0.1:8080';

  public const DEFAULT_MODEL = 'mistral-7b-instruct-v0.2.Q4_K_M.gguf';

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
   * Returns TRUE if the local LLM base URL resolves to a non-empty value.
   */
  public function isConfigured(): bool {
    $url = $this->getBaseUrl();
    return !empty($url);
  }

  /**
   * Returns the configured local LLM base URL.
   */
  public function getBaseUrl(): string {
    $config = $this->configFactory->get('ai_conversation.provider_settings');
    $configured = trim((string) ($config->get('ollama_base_url') ?: ''));
    return rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
  }

  /**
   * Returns the list of available local models from config.
   */
  public function getAvailableModels(): array {
    $config = $this->configFactory->get('ai_conversation.provider_settings');
    $models = $config->get('ollama_available_models') ?: [self::DEFAULT_MODEL];
    return (array) $models;
  }

  /**
   * Tests connectivity by calling /v1/models on the local server.
   *
   * @return array ['success' => bool, 'error' => string, 'models' => array]
   */
  public function testConnection(): array {
    if (!$this->isConfigured()) {
      return ['success' => FALSE, 'error' => 'Local LLM base URL is not configured.', 'models' => []];
    }
    try {
      $response = $this->httpClient->get($this->getBaseUrl() . '/v1/models', ['timeout' => 5]);
      $body = json_decode($response->getBody()->getContents(), TRUE);
      $models = [];
      if (isset($body['data']) && is_array($body['data'])) {
        foreach ($body['data'] as $model) {
          if (!empty($model['id'])) {
            $models[] = $model['id'];
          }
        }
      }
      elseif (isset($body['models']) && is_array($body['models'])) {
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
   * Sends a chat request to the OpenAI-compatible local endpoint.
   *
   * @param string $model         Local model name.
   * @param array  $messages      Array of ['role' => ..., 'content' => ...].
   * @param string $system_prompt Optional system prompt prepended as a system message.
   * @param int    $timeout       HTTP timeout in seconds.
   * @param int|null $max_tokens  Optional response token cap.
   *
   * @return array ['text' => string, 'model' => string] on success.
   *
   * @throws \RuntimeException on connection failure or invalid response.
   */
  public function chat(string $model, array $messages, string $system_prompt = '', int $timeout = 60, ?int $max_tokens = NULL): array {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('Local LLM is not configured.');
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
    if ($max_tokens !== NULL && $max_tokens > 0) {
      $payload['max_tokens'] = $max_tokens;
    }

    try {
      $response = $this->httpClient->post(
        $this->getBaseUrl() . '/v1/chat/completions',
        [
          'json' => $payload,
          'timeout' => $timeout,
          'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]
      );
      $body = json_decode($response->getBody()->getContents(), TRUE);
      $text = $body['choices'][0]['message']['content'] ?? '';
      if (is_array($text)) {
        $parts = [];
        foreach ($text as $part) {
          if (is_array($part) && isset($part['text'])) {
            $parts[] = $part['text'];
          }
        }
        $text = implode('', $parts);
      }
      if ($text === '') {
        throw new \RuntimeException('Empty response from local LLM.');
      }
      return ['text' => $text, 'model' => $body['model'] ?? $model];
    }
    catch (ConnectException $e) {
      throw new \RuntimeException('Local LLM unreachable: ' . $e->getMessage(), 0, $e);
    }
    catch (RequestException $e) {
      throw new \RuntimeException('Local LLM request failed: ' . $e->getMessage(), 0, $e);
    }
  }

}
