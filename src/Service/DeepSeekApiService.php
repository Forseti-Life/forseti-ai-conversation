<?php

namespace Drupal\ai_conversation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for communicating with DeepSeek's OpenAI-compatible chat API.
 */
class DeepSeekApiService {

  public const DEFAULT_BASE_URL = 'https://api.deepseek.com/v1';

  public const DEFAULT_MODEL = 'deepseek-chat';

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, ClientInterface $http_client) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ai_conversation');
    $this->httpClient = $http_client;
  }

  /**
   * Returns the configured DeepSeek base URL.
   */
  public function getBaseUrl(): string {
    $config = $this->configFactory->get('ai_conversation.provider_settings');
    $configured = trim((string) ($config->get('deepseek_base_url') ?: ''));
    return rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
  }

  /**
   * Returns the configured DeepSeek API key, preferring env override.
   */
  public function getApiKey(): string {
    $env_key = trim((string) getenv('DEEPSEEK_API_KEY'));
    if ($env_key !== '') {
      return $env_key;
    }

    $config = $this->configFactory->get('ai_conversation.provider_settings');
    return trim((string) ($config->get('deepseek_api_key') ?: ''));
  }

  /**
   * Returns the configured DeepSeek model list.
   */
  public function getAvailableModels(): array {
    $config = $this->configFactory->get('ai_conversation.provider_settings');
    $models = $config->get('deepseek_available_models') ?: [self::DEFAULT_MODEL];
    return array_values(array_filter(array_map('strval', (array) $models)));
  }

  /**
   * Returns TRUE if the service is configured well enough to attempt requests.
   */
  public function isConfigured(): bool {
    return $this->getBaseUrl() !== '' && $this->getApiKey() !== '';
  }

  /**
   * Tests connectivity against DeepSeek's models endpoint.
   *
   * @return array
   *   ['success' => bool, 'error' => string, 'models' => array]
   */
  public function testConnection(): array {
    if (!$this->isConfigured()) {
      return ['success' => FALSE, 'error' => 'DeepSeek API key is not configured.', 'models' => []];
    }

    try {
      $response = $this->httpClient->get($this->getBaseUrl() . '/models', [
        'timeout' => 10,
        'headers' => [
          'Accept' => 'application/json',
          'Authorization' => 'Bearer ' . $this->getApiKey(),
        ],
      ]);
      $body = json_decode($response->getBody()->getContents(), TRUE);
      $models = [];
      foreach (($body['data'] ?? []) as $model) {
        if (!empty($model['id'])) {
          $models[] = (string) $model['id'];
        }
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
   * Sends a chat request to DeepSeek.
   *
   * @param string $model
   *   DeepSeek model ID.
   * @param array $messages
   *   Array of chat messages.
   * @param string $system_prompt
   *   Optional system prompt.
   * @param int $timeout
   *   Request timeout in seconds.
   * @param int|null $max_tokens
   *   Optional token cap.
   *
   * @return array
   *   ['text' => string, 'model' => string] on success.
   *
   * @throws \RuntimeException
   *   When DeepSeek is unavailable or returns an invalid response.
   */
  public function chat(string $model, array $messages, string $system_prompt = '', int $timeout = 60, ?int $max_tokens = NULL): array {
    if (!$this->isConfigured()) {
      throw new \RuntimeException('DeepSeek API key is not configured.');
    }

    $all_messages = [];
    if ($system_prompt !== '') {
      $all_messages[] = ['role' => 'system', 'content' => $system_prompt];
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
      $response = $this->httpClient->post($this->getBaseUrl() . '/chat/completions', [
        'json' => $payload,
        'timeout' => $timeout,
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'Authorization' => 'Bearer ' . $this->getApiKey(),
        ],
      ]);
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
        throw new \RuntimeException('Empty response from DeepSeek.');
      }
      return ['text' => $text, 'model' => $body['model'] ?? $model];
    }
    catch (ConnectException $e) {
      throw new \RuntimeException('DeepSeek unreachable: ' . $e->getMessage(), 0, $e);
    }
    catch (RequestException $e) {
      throw new \RuntimeException('DeepSeek request failed: ' . $e->getMessage(), 0, $e);
    }
  }

}
