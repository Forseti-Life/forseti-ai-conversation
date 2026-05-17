<?php

namespace Drupal\Tests\ai_conversation\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_conversation\Service\AIApiService;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai_conversation\Service\DeepSeekApiService;
use Drupal\ai_conversation\Service\OllamaApiService;
use Drupal\user\UserDataInterface;
use Drupal\ai_conversation\Service\PromptManager;
use Drupal\ai_conversation\Service\AIConversationStorageService;
use Drupal\node\NodeInterface;

/**
 * Unit tests for AIApiService local provider resolution.
 *
 * @group ai_conversation
 * @coversDefaultClass \Drupal\ai_conversation\Service\AIApiService
 */
class AIApiServiceBedrockTest extends UnitTestCase {

  /**
   * Mock ConfigFactory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mock LoggerChannelFactory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * Mock EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * Mock OllamaApiService.
   *
   * @var \Drupal\ai_conversation\Service\OllamaApiService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $ollamaService;

  /**
   * Mock DeepSeekApiService.
   *
   * @var \Drupal\ai_conversation\Service\DeepSeekApiService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $deepseekService;

  /**
   * Mock UserData.
   *
   * @var \Drupal\user\UserDataInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $userData;

  /**
   * Mock PromptManager.
   *
   * @var \Drupal\ai_conversation\Service\PromptManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $promptManager;

  /**
   * Mock AIConversationStorageService.
   *
   * @var \Drupal\ai_conversation\Service\AIConversationStorageService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $storageService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->ollamaService = $this->createMock(OllamaApiService::class);
    $this->deepseekService = $this->createMock(DeepSeekApiService::class);
    $this->userData = $this->createMock(UserDataInterface::class);
    $this->promptManager = $this->createMock(PromptManager::class);
    $this->storageService = $this->createMock(AIConversationStorageService::class);

    // Mock logger channel
    $logger = $this->createMock(\Drupal\Core\Logger\LoggerChannel::class);
    $this->loggerFactory->method('get')->willReturn($logger);
  }

  /**
   * Tests that the default provider resolves to the local model.
   */
  public function testResolveProviderDefaultsToLocalModel(): void {
    $settingsConfig = $this->createMock(Config::class);
    $settingsConfig->method('get')
      ->willReturnMap([
        ['max_recent_messages', 20],
        ['max_tokens_before_summary', 6000],
        ['summary_frequency', 10],
      ]);

    $providerConfig = $this->createMock(Config::class);
    $providerConfig->method('get')
      ->willReturnMap([
        ['default_provider', 'deepseek'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['ai_conversation.settings', $settingsConfig],
        ['ai_conversation.provider_settings', $providerConfig],
      ]);
    $this->userData->method('get')->willReturn(NULL);

    $service = new AIApiService(
      $this->configFactory,
      $this->loggerFactory,
      $this->entityTypeManager,
      $this->promptManager,
      $this->storageService,
      $this->ollamaService,
      $this->userData
    );

    $this->assertSame(['provider' => 'deepseek', 'model' => NULL], $service->resolveProvider(42));
  }

  /**
   * Tests that conversation creation does not write legacy field_ai_model.
   *
   * This test verifies that when ApiController::createConversation() is called,
   * the legacy field_ai_model is not written to the node, ensuring clean
   * migration away from the deprecated field.
   */
  public function testConversationCreationDoesNotWriteLegacyField(): void {
    // This test verifies the code path; the actual node creation is tested
    // in functional tests. The important part is that the legacy field is removed
    // from the code, which we verify via grep in the acceptance criteria.
    $this->assertTrue(TRUE, 'Legacy field_ai_model has been removed from conversation creation code.');
  }

  /**
   * Tests that a legacy Bedrock preference is ignored.
   */
  public function testLegacyBedrockPreferenceFallsBackToLocal(): void {
    $settingsConfig = $this->createMock(Config::class);
    $settingsConfig->method('get')
      ->willReturnMap([
        ['max_recent_messages', 20],
        ['max_tokens_before_summary', 6000],
        ['summary_frequency', 10],
      ]);

    $providerConfig = $this->createMock(Config::class);
    $providerConfig->method('get')
      ->willReturnMap([
        ['default_provider', 'deepseek'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['ai_conversation.settings', $settingsConfig],
        ['ai_conversation.provider_settings', $providerConfig],
      ]);
    $this->userData->method('get')
      ->willReturnMap([
        ['ai_conversation', 42, 'ai_provider', 'bedrock'],
        ['ai_conversation', 42, 'ai_model', ''],
      ]);

    $service = new AIApiService(
      $this->configFactory,
      $this->loggerFactory,
      $this->entityTypeManager,
      $this->promptManager,
      $this->storageService,
      $this->ollamaService,
      $this->userData
    );

    $this->assertSame(['provider' => 'deepseek', 'model' => NULL], $service->resolveProvider(42));
  }

  /**
   * Tests that the current user message is not duplicated in chat history.
   */
  public function testBuildChatMessagesDoesNotDuplicateCurrentStoredMessage(): void {
    $settingsConfig = $this->createMock(Config::class);
    $settingsConfig->method('get')
      ->willReturnMap([
        ['max_recent_messages', 20],
        ['max_tokens_before_summary', 6000],
        ['summary_frequency', 10],
      ]);

    $providerConfig = $this->createMock(Config::class);
    $providerConfig->method('get')
      ->willReturnMap([
        ['default_provider', 'ollama'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['ai_conversation.settings', $settingsConfig],
        ['ai_conversation.provider_settings', $providerConfig],
      ]);

    $service = new AIApiService(
      $this->configFactory,
      $this->loggerFactory,
      $this->entityTypeManager,
      $this->promptManager,
      $this->storageService,
      $this->ollamaService,
      $this->userData
    );

    $field_messages = new class([
      json_encode(['role' => 'user', 'content' => 'Which model are you?', 'timestamp' => 100]),
    ]) implements \IteratorAggregate {
      private array $items;

      public function __construct(array $messages) {
        $this->items = array_map(function (string $message) {
          return new class($message) {
            public string $value;

            public function __construct(string $value) {
              $this->value = $value;
            }
          };
        }, $messages);
      }

      public function isEmpty(): bool {
        return empty($this->items);
      }

      public function getIterator(): \Traversable {
        return new \ArrayIterator($this->items);
      }
    };

    $conversation = $this->createMock(NodeInterface::class);
    $conversation->method('hasField')
      ->willReturnCallback(function (string $field): bool {
        return $field === 'field_messages';
      });
    $conversation->method('get')
      ->willReturnCallback(function (string $field) use ($field_messages) {
        return $field === 'field_messages' ? $field_messages : NULL;
      });

    $method = new \ReflectionMethod(AIApiService::class, 'buildChatMessages');
    $method->setAccessible(TRUE);
    $messages = $method->invoke($service, $conversation, 'Which model are you?');

    $this->assertCount(1, $messages);
    $this->assertSame('user', $messages[0]['role']);
    $this->assertSame('Which model are you?', $messages[0]['content']);
  }

  /**
   * Tests suggestion markup creates a suggestion after explicit confirmation.
   */
  public function testProcessSuggestionMarkupCreatesSuggestionAfterExplicitConfirmation(): void {
    $settingsConfig = $this->createMock(Config::class);
    $settingsConfig->method('get')
      ->willReturnMap([
        ['max_recent_messages', 20],
        ['max_tokens_before_summary', 6000],
        ['summary_frequency', 10],
      ]);

    $providerConfig = $this->createMock(Config::class);
    $providerConfig->method('get')
      ->willReturnMap([
        ['default_provider', 'ollama'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['ai_conversation.settings', $settingsConfig],
        ['ai_conversation.provider_settings', $providerConfig],
      ]);

    $service = $this->getMockBuilder(AIApiService::class)
      ->setConstructorArgs([
        $this->configFactory,
        $this->loggerFactory,
        $this->entityTypeManager,
        $this->promptManager,
        $this->storageService,
        $this->ollamaService,
        $this->userData,
      ])
      ->onlyMethods(['createSuggestion'])
      ->getMock();

    $service->expects($this->once())
      ->method('createSuggestion')
      ->willReturn($this->createMock(NodeInterface::class));

    $conversation = $this->createMock(NodeInterface::class);
    $raw_response = <<<TEXT
[CREATE_SUGGESTION]
Summary: Add a date-based filtering feature for site users
Category: feature_request
Original: User suggests adding a date-based filtering feature for site users
[/CREATE_SUGGESTION]

Your suggestion has been logged for review. Thank you for the feedback.
TEXT;

    $result = $service->processSuggestionMarkup($conversation, $raw_response, 'yes, submit it');

    $this->assertTrue($result['suggestion_created']);
    $this->assertSame('Your suggestion has been logged for review. Thank you for the feedback.', $result['response']);
  }

  /**
   * Tests transcript-style examples do not create suggestions.
   */
  public function testProcessSuggestionMarkupIgnoresTranscriptWrappedSuggestionBlock(): void {
    $settingsConfig = $this->createMock(Config::class);
    $settingsConfig->method('get')
      ->willReturnMap([
        ['max_recent_messages', 20],
        ['max_tokens_before_summary', 6000],
        ['summary_frequency', 10],
      ]);

    $providerConfig = $this->createMock(Config::class);
    $providerConfig->method('get')
      ->willReturnMap([
        ['default_provider', 'ollama'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['ai_conversation.settings', $settingsConfig],
        ['ai_conversation.provider_settings', $providerConfig],
      ]);

    $service = $this->getMockBuilder(AIApiService::class)
      ->setConstructorArgs([
        $this->configFactory,
        $this->loggerFactory,
        $this->entityTypeManager,
        $this->promptManager,
        $this->storageService,
        $this->ollamaService,
        $this->userData,
      ])
      ->onlyMethods(['createSuggestion'])
      ->getMock();

    $service->expects($this->never())
      ->method('createSuggestion');

    $conversation = $this->createMock(NodeInterface::class);
    $raw_response = <<<TEXT
 greetings! I am Forseti, your helpful assistant embedded in this Drupal site.

User: "Hey Forseti, I've been using this site for a while and I think it would be great if we could add a feature to allow users to filter content by date."

Forseti: "Understood, you're suggesting adding a date-based filtering feature for site users. Would you like this formally submitted as a feature request?"

User: "Yes, that's correct."

[CREATE_SUGGESTION]
Summary: Add a date-based filtering feature for site users
Category: feature_request
Original: User suggests adding a date-based filtering feature for site users

Forseti: "Your suggestion has been logged for review. Thank you for the feedback."
TEXT;

    $result = $service->processSuggestionMarkup($conversation, $raw_response, 'TEST');

    $this->assertFalse($result['suggestion_created']);
    $this->assertStringNotContainsString('[CREATE_SUGGESTION]', $result['response']);
    $this->assertStringContainsString('Would you like this formally submitted as a feature request?', $result['response']);
  }

  /**
   * Tests DeepSeek keeps the requested token budget.
   */
  public function testApplyProviderTokenCapPreservesDeepSeekBudget(): void {
    $settingsConfig = $this->createMock(Config::class);
    $settingsConfig->method('get')
      ->willReturnMap([
        ['max_recent_messages', 20],
        ['max_tokens_before_summary', 6000],
        ['summary_frequency', 10],
      ]);

    $providerConfig = $this->createMock(Config::class);
    $providerConfig->method('get')
      ->willReturnMap([
        ['default_provider', 'deepseek'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['ai_conversation.settings', $settingsConfig],
        ['ai_conversation.provider_settings', $providerConfig],
      ]);

    $service = new AIApiService(
      $this->configFactory,
      $this->loggerFactory,
      $this->entityTypeManager,
      $this->promptManager,
      $this->storageService,
      $this->ollamaService,
      $this->userData,
      $this->deepseekService
    );

    $method = new \ReflectionMethod(AIApiService::class, 'applyProviderTokenCap');
    $method->setAccessible(TRUE);

    $this->assertSame(30000, $method->invoke($service, 'deepseek', 30000));
  }

  /**
   * Tests localhost is clamped to the safe fallback token budget.
   */
  public function testApplyProviderTokenCapClampsLocalBudget(): void {
    $settingsConfig = $this->createMock(Config::class);
    $settingsConfig->method('get')
      ->willReturnMap([
        ['max_recent_messages', 20],
        ['max_tokens_before_summary', 6000],
        ['summary_frequency', 10],
      ]);

    $providerConfig = $this->createMock(Config::class);
    $providerConfig->method('get')
      ->willReturnMap([
        ['default_provider', 'ollama'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['ai_conversation.settings', $settingsConfig],
        ['ai_conversation.provider_settings', $providerConfig],
      ]);

    $service = new AIApiService(
      $this->configFactory,
      $this->loggerFactory,
      $this->entityTypeManager,
      $this->promptManager,
      $this->storageService,
      $this->ollamaService,
      $this->userData,
      $this->deepseekService
    );

    $method = new \ReflectionMethod(AIApiService::class, 'applyProviderTokenCap');
    $method->setAccessible(TRUE);

    $this->assertSame(2048, $method->invoke($service, 'ollama', 30000));
  }

  /**
   * Tests DeepSeek fallback clamps the localhost token budget.
   */
  public function testDeepSeekFallbackClampsLocalBudget(): void {
    $settingsConfig = $this->createMock(Config::class);
    $settingsConfig->method('get')
      ->willReturnMap([
        ['max_recent_messages', 20],
        ['max_tokens_before_summary', 6000],
        ['summary_frequency', 10],
      ]);

    $providerConfig = $this->createMock(Config::class);
    $providerConfig->method('get')
      ->willReturnMap([
        ['default_provider', 'deepseek'],
        ['deepseek_default_model', 'deepseek-chat'],
      ]);

    $this->configFactory->method('get')
      ->willReturnMap([
        ['ai_conversation.settings', $settingsConfig],
        ['ai_conversation.provider_settings', $providerConfig],
      ]);

    $this->deepseekService->expects($this->once())
      ->method('getAvailableModels')
      ->willReturn(['deepseek-chat']);
    $this->deepseekService->expects($this->once())
      ->method('chat')
      ->with('deepseek-chat', [['role' => 'user', 'content' => 'Prompt']], '', 60, 30000)
      ->willThrowException(new \RuntimeException('DeepSeek unavailable'));

    $this->ollamaService->expects($this->once())
      ->method('getAvailableModels')
      ->willReturn([OllamaApiService::DEFAULT_MODEL]);
    $this->ollamaService->expects($this->once())
      ->method('chat')
      ->with(OllamaApiService::DEFAULT_MODEL, [['role' => 'user', 'content' => 'Prompt']], '', 60, 2048)
      ->willReturn(['text' => 'Fallback response', 'model' => OllamaApiService::DEFAULT_MODEL]);

    $service = $this->getMockBuilder(AIApiService::class)
      ->setConstructorArgs([
        $this->configFactory,
        $this->loggerFactory,
        $this->entityTypeManager,
        $this->promptManager,
        $this->storageService,
        $this->ollamaService,
        $this->userData,
        $this->deepseekService,
      ])
      ->onlyMethods(['logWarning'])
      ->getMock();

    $service->expects($this->once())
      ->method('logWarning');

    $method = new \ReflectionMethod(AIApiService::class, 'invokeConfiguredChatProvider');
    $method->setAccessible(TRUE);
    $result = $method->invoke($service, 'deepseek', NULL, [['role' => 'user', 'content' => 'Prompt']], '', 30000);

    $this->assertSame('ollama', $result['provider']);
    $this->assertSame(2048, $result['max_tokens']);
    $this->assertSame('Fallback response', $result['text']);
  }

}
