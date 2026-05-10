<?php

namespace Drupal\Tests\ai_conversation\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_conversation\Service\PromptManager;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for prompt normalization and retrieval.
 *
 * @group ai_conversation
 * @coversDefaultClass \Drupal\ai_conversation\Service\PromptManager
 */
class PromptManagerTest extends UnitTestCase {

  /**
   * Tests that configured prompts are normalized away from Anthropic branding.
   */
  public function testGetConfiguredPromptNormalizesLegacyProviderBranding(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config = $this->createMock(Config::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    $config->method('get')
      ->with('system_prompt')
      ->willReturn("You are Keith Aumiller and this conversation is powered by Anthropic's Claude technology.");
    $config_factory->method('get')
      ->with('ai_conversation.settings')
      ->willReturn($config);

    $manager = new PromptManager($config_factory, $entity_type_manager, $logger);
    $prompt = $manager->getConfiguredPrompt();

    $this->assertStringNotContainsString('Anthropic', $prompt);
    $this->assertStringNotContainsString('Claude', $prompt);
    $this->assertStringContainsString("Forseti's locally hosted language model", $prompt);
  }

  /**
   * Tests that old Bedrock-era architecture text is normalized.
   */
  public function testNormalizePromptTextRemovesBedrockEraStackDetails(): void {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $logger = $this->createMock(LoggerInterface::class);

    $manager = new PromptManager($config_factory, $entity_type_manager, $logger);
    $prompt = $manager->normalizePromptText('AI Service: AWS Bedrock Runtime API with Claude 3.5 Sonnet');

    $this->assertStringNotContainsString('Bedrock', $prompt);
    $this->assertStringNotContainsString('Claude 3.5 Sonnet', $prompt);
    $this->assertStringContainsString('local OpenAI-compatible model runtime', $prompt);
    $this->assertStringContainsString('Mistral 7B local model', $prompt);
  }
}
