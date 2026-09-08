<?php

declare(strict_types=1);

if (!class_exists('Drupal', FALSE)) {
  final class Drupal {
    public static ?FakeConnection $database = NULL;

    public static function config(string $name): object {
      return new class {
        public function get(string $key) {
          return NULL;
        }
      };
    }

    public static function database(): FakeConnection {
      return self::$database ?? new FakeConnection(NULL);
    }

    public static function time(): object {
      return new class {
        public function getRequestTime(): int {
          return 1;
        }
      };
    }

    public static function currentUser(): object {
      return new class {
        public function id(): int {
          return 0;
        }
      };
    }
  }
}

require_once __DIR__ . '/../src/Traits/ConfigurableLoggingTrait.php';
require_once __DIR__ . '/../src/Service/AIApiService.php';

use Drupal\ai_conversation\Service\AIApiService;

final class FakeConfig {
  public function __construct(private array $values) {}
  public function get(string $key) {
    return $this->values[$key] ?? NULL;
  }
}

final class FakeConfigFactory {
  public function __construct(private FakeConfig $config) {}
  public function get(string $name): FakeConfig {
    return $this->config;
  }
}

final class FakeBody {
  public function __construct(private string $body) {}
  public function __toString(): string {
    return $this->body;
  }
}

final class FakeResponse {
  public function __construct(private string $body, private int $status = 200) {}
  public function getBody(): FakeBody {
    return new FakeBody($this->body);
  }
  public function getStatusCode(): int {
    return $this->status;
  }
}

final class FakeHttpClient {
  public array $lastRequest = [];
  /** @var FakeResponse[] */
  private array $responses;

  public function __construct(FakeResponse ...$responses) {
    $this->responses = $responses;
  }

  public function request(string $method, string $uri, array $options): FakeResponse {
    $this->lastRequest = [$method, $uri, $options];
    if ($this->responses === []) {
      throw new RuntimeException('No fake response queued.');
    }
    return array_shift($this->responses);
  }
}

final class FakeSchema {
  public function fieldExists(string $table, string $field): bool {
    return FALSE;
  }
}

final class FakeInsert {
  public function fields(array $fields): self {
    return $this;
  }

  public function execute(): int {
    return 1;
  }
}

final class FakeQueryResult {
  public function __construct(private ?array $row) {}

  public function fetchAssoc(): ?array {
    return $this->row;
  }
}

final class FakeQuery {
  public function __construct(private ?array $row) {}

  public function fields(string $alias, array $fields): self {
    return $this;
  }

  public function condition(string $field, mixed $value): self {
    return $this;
  }

  public function orderBy(string $field, string $direction): self {
    return $this;
  }

  public function range(int $start, int $length): self {
    return $this;
  }

  public function where(string $snippet, array $args): self {
    return $this;
  }

  public function execute(): FakeQueryResult {
    return new FakeQueryResult($this->row);
  }
}

final class FakeConnection {
  public function __construct(private ?array $cachedRow) {}

  public function select(string $table, string $alias): FakeQuery {
    return new FakeQuery($this->cachedRow);
  }

  public function insert(string $table): FakeInsert {
    return new FakeInsert();
  }

  public function schema(): FakeSchema {
    return new FakeSchema();
  }
}

function make_service(FakeHttpClient $client): AIApiService {
  $ref = new ReflectionClass(AIApiService::class);
  /** @var AIApiService $service */
  $service = $ref->newInstanceWithoutConstructor();

  $config = new FakeConfig([
    'deepseek_base_url' => 'https://deepseek.test/v1',
    'deepseek_model' => 'deepseek-v4-flash',
  ]);
  foreach ([
    'configFactory' => new FakeConfigFactory($config),
    'httpClient' => $client,
  ] as $property => $value) {
    $prop = $ref->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue($service, $value);
  }
  return $service;
}

function invoke_deepseek(AIApiService $service, array $options = [], int $maxTokens = 12000): array {
  $method = new ReflectionMethod(AIApiService::class, 'invokeDeepSeekPrompt');
  $method->setAccessible(true);
  return $method->invoke($service, 'Generate structured JSON.', $maxTokens, $options);
}

function assert_true(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

putenv('DEEPSEEK_API_KEY=test-key');

$successBody = json_encode([
  'model' => 'deepseek-v4-flash',
  'choices' => [[
    'finish_reason' => 'stop',
    'message' => [
      'content' => '{"ok":true}',
      'reasoning_content' => '',
    ],
  ]],
  'usage' => [
    'completion_tokens_details' => ['reasoning_tokens' => 0],
  ],
], JSON_THROW_ON_ERROR);
$client = new FakeHttpClient(new FakeResponse($successBody));
$result = invoke_deepseek(make_service($client), ['thinking' => 'disabled']);
assert_true($client->lastRequest[2]['json']['thinking'] === ['type' => 'disabled'], 'thinking=disabled was not added to the DeepSeek payload.');
assert_true($result['finish_reason'] === 'stop' && $result['stop_reason'] === 'stop', 'finish_reason/stop_reason were not normalized.');
assert_true($result['reasoning_tokens'] === 0, 'reasoning_tokens was not normalized.');

$client = new FakeHttpClient(new FakeResponse($successBody));
invoke_deepseek(make_service($client), ['thinking' => 'enabled']);
assert_true($client->lastRequest[2]['json']['thinking'] === ['type' => 'enabled'], 'thinking=enabled was not added to the DeepSeek payload.');

Drupal::$database = new FakeConnection(NULL);
$direct = make_service(new FakeHttpClient(new FakeResponse($successBody)))
  ->invokeModelDirect('Generate structured JSON.', 'test_module', 'live_op', [], [
    'provider' => 'deepseek',
    'model_id' => 'deepseek-v4-flash',
    'skip_cache' => TRUE,
  ]);
assert_true($direct['model_id'] === 'deepseek-v4-flash', 'Live invokeModelDirect result omitted model_id.');
assert_true($direct['provider'] === 'deepseek', 'Live invokeModelDirect result omitted provider.');

Drupal::$database = new FakeConnection([
  'response_preview' => '{"cached":true}',
  'stop_reason' => 'stop',
  'timestamp' => 1,
  'model_id' => 'stored-deepseek-model',
  'input_tokens' => 3,
  'output_tokens' => 4,
  'context_data' => json_encode(['provider' => 'deepseek'], JSON_THROW_ON_ERROR),
]);
$cached = make_service(new FakeHttpClient())
  ->invokeModelDirect('Generate structured JSON.', 'test_module', 'cached_op', [], [
    'provider' => 'deepseek',
    'model_id' => 'resolved-deepseek-model',
  ]);
assert_true($cached['cached'] === TRUE, 'Cached invokeModelDirect result did not use cache.');
assert_true($cached['model_id'] === 'stored-deepseek-model', 'Cached invokeModelDirect result did not use cached model_id.');
assert_true($cached['provider'] === 'deepseek', 'Cached invokeModelDirect result omitted provider.');

$emptyBody = json_encode([
  'model' => 'deepseek-v4-flash',
  'choices' => [[
    'finish_reason' => 'length',
    'message' => [
      'content' => '',
      'reasoning_content' => 'thinking...',
    ],
  ]],
  'usage' => [
    'completion_tokens_details' => ['reasoning_tokens' => 12000],
  ],
], JSON_THROW_ON_ERROR);
try {
  invoke_deepseek(make_service(new FakeHttpClient(new FakeResponse($emptyBody))), [], 12000);
  throw new RuntimeException('Empty DeepSeek content did not throw.');
}
catch (RuntimeException $e) {
  $message = $e->getMessage();
  assert_true(str_contains($message, 'finish_reason=length'), 'Empty-content error omitted finish_reason.');
  assert_true(str_contains($message, 'reasoning_tokens=12000'), 'Empty-content error omitted reasoning_tokens.');
  assert_true(str_contains($message, 'max_tokens=12000'), 'Empty-content error omitted max_tokens.');
  assert_true(str_contains($message, 'model=deepseek-v4-flash'), 'Empty-content error omitted model.');
  assert_true(str_contains($message, "options.thinking='disabled'"), 'Empty-content error omitted remediation hint.');
}

try {
  invoke_deepseek(make_service(new FakeHttpClient(new FakeResponse('{not json', 502))));
  throw new RuntimeException('Invalid DeepSeek JSON did not throw.');
}
catch (RuntimeException $e) {
  $message = $e->getMessage();
  assert_true(str_contains($message, 'Unexpected DeepSeek response format (HTTP status 502): {not json'), 'Invalid-json error did not include status and body preview.');
}

try {
  invoke_deepseek(make_service(new FakeHttpClient(new FakeResponse('{"id":"missing choices"}', 200))));
  throw new RuntimeException('Missing DeepSeek choices did not throw.');
}
catch (RuntimeException $e) {
  $message = $e->getMessage();
  assert_true(str_contains($message, 'Unexpected DeepSeek response format (HTTP status 200): {"id":"missing choices"}'), 'Missing-choices error did not include status and body preview.');
}

echo "DeepSeek thinking/error tests passed\n";
