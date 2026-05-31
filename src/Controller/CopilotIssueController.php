<?php

namespace Drupal\ai_conversation\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Controller to create GitHub issues and assign them to Copilot.
 */
class CopilotIssueController extends ControllerBase {

  /**
   * HTTP client for GitHub API requests.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Config factory (reserved for future repo defaults).
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Default repository for issue creation.
   */
  private string $defaultRepo = 'keithaumiller/forseti.life';

  /**
   * Construct the controller.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, LoggerChannelInterface $logger) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('logger.channel.ai_conversation')
    );
  }

  /**
   * Create a GitHub issue and assign to Copilot if available.
   */
  public function createIssue(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE) ?: [];

    $config = $this->config('ai_conversation.settings');

    $title = trim((string) ($payload['title'] ?? ''));
    $body = (string) ($payload['body'] ?? '');
    $repo = (string) ($payload['repo'] ?? $config->get('copilot_default_repo') ?? $this->defaultRepo);
    $labels = $payload['labels'] ?? [];
    $assignToCopilot = $payload['assign_to_copilot'] ?? TRUE;
    $assignees = $payload['assignees'] ?? [];

    if ($title === '' || $body === '') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Missing required fields: title and body are required.',
      ], 400);
    }

    // Prefer caller-provided assignees; otherwise default to Copilot.
    if (empty($assignees) && $assignToCopilot) {
      $assignees = ['copilot'];
    }

    $token = $config->get('copilot_token') ?: (getenv('GITHUB_TOKEN_COPILOT') ?: getenv('GITHUB_TOKEN'));
    if (!$token) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Missing GitHub token. Set GITHUB_TOKEN_COPILOT or GITHUB_TOKEN in the environment.',
      ], 500);
    }

    $issueData = [
      'title' => $title,
      'body' => $body,
    ];

    if (!empty($assignees)) {
      $issueData['assignees'] = $assignees;
    }

    if (!empty($labels) && is_array($labels)) {
      $issueData['labels'] = $labels;
    }

    try {
      $response = $this->httpClient->request('POST', "https://api.github.com/repos/{$repo}/issues", [
        'headers' => [
          'Authorization' => "Bearer {$token}",
          'Accept' => 'application/vnd.github+json',
          'User-Agent' => 'ai_conversation-module',
        ],
        'json' => $issueData,
        'timeout' => 15,
      ]);

      $status = $response->getStatusCode();
      $data = json_decode((string) $response->getBody(), TRUE) ?? [];

      if ($status >= 200 && $status < 300) {
        $this->logger->notice('GitHub issue created via ai_conversation endpoint. repo=@repo issue=#@issue user_id=@uid ip=@ip title="@title"', [
          '@repo' => $repo,
          '@issue' => (string) ($data['number'] ?? 'n/a'),
          '@uid' => (string) $this->currentUser()->id(),
          '@ip' => (string) ($request->getClientIp() ?? 'unknown'),
          '@title' => mb_strimwidth($title, 0, 180, '…'),
        ]);

        return new JsonResponse([
          'success' => TRUE,
          'issue_number' => $data['number'] ?? NULL,
          'issue_url' => $data['html_url'] ?? NULL,
          'assignees' => $data['assignees'] ?? [],
        ], 201);
      }

      return new JsonResponse([
        'success' => FALSE,
        'error' => $data['message'] ?? 'Unknown error from GitHub',
        'details' => $data,
      ], $status);
    }
    catch (GuzzleException $e) {
      $this->logger->error('GitHub issue creation failed: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Unable to create issue: ' . $e->getMessage(),
      ], 502);
    }
  }

}
