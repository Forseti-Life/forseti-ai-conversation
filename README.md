<!-- AUTOMATION VALIDATION: 2026-04-23 - automation of development confirmed for this repo -->
# AI Conversation Module (Shared)

**Canonical shared version of the AI Conversation module used by job-hunter and dungeoncrawler platforms.**

[![License: GPL-3.0](https://img.shields.io/badge/License-GPL--3.0-blue.svg)](LICENSE)
[![Drupal Version](https://img.shields.io/badge/Drupal-9%2B-blue)](https://www.drupal.org)
[![Status: Active](https://img.shields.io/badge/Status-Active-brightgreen)](#)

## Overview

The AI Conversation module provides a sophisticated conversational AI interface powered by **AWS Bedrock** and **Claude 3.5 Sonnet**. It features an intelligent **rolling summary system** that allows for unlimited conversation length while maintaining context efficiency and managing token costs. This is the canonical shared version symlinked by both the Job Hunter and DungeonCrawler platforms.

**Canonical Location (Symlink Target):**
- `sites/forseti/web/modules/custom/ai_conversation`

**Dependent Platforms:**
- Job Hunter: `forseti-job-hunter/web/modules/custom/ai_conversation` (symlink)
- DungeonCrawler: `dungeoncrawler-pf2e/web/modules/custom/ai_conversation` (symlink)

## Features

### 🤖 **AWS Bedrock Integration**
- **Primary Model:** Claude 3.5 Sonnet (anthropic.claude-3-5-sonnet-20240620-v1:0)
- **Fallback Models:** Claude 3 Haiku and Claude 3 Opus
- **Region:** us-west-2
- **Authentication:** Environment variables or IAM roles (no hardcoded credentials)

### 🔄 **Intelligent Rolling Summary System**
- **Automatic Summarization:** Older messages automatically summarized when conversation exceeds limits
- **Recent Message Retention:** Keeps most recent N messages (default: 20) in full detail
- **Context Optimization:** Summary + recent messages provide optimal context for AI responses
- **Configurable Frequency:** Summary updates every N messages (default: 10)
- **Token Management:** Prevents context window overflow in long conversations

### 💾 **Persistent Node-Based Storage**
- Each conversation stored as a Drupal `ai_conversation` content type node
- All messages, settings, and metadata stored in node fields
- Full conversation history maintained indefinitely
- Access chat at `/node/{nid}/chat`

### ⚙️ **Advanced Configuration**
- Custom system prompts for specialized conversations
- Selectable AI models
- Configurable token limits and summary parameters
- Per-conversation context settings
- Temperature and other model parameters

### 📊 **Real-Time Statistics**
- Token usage tracking
- Message count monitoring
- Conversation duration statistics
- Summary event log

## Installation

### Requirements
- Drupal 9, 10, or 11
- MySQL/PostgreSQL database
- AWS Bedrock access (Claude models available in us-west-2)
- PHP 8.0+

### AWS Bedrock Setup

1. **AWS Account Setup:**
   ```bash
   # Ensure AWS credentials are configured
   aws configure
   
   # Verify Bedrock access for Claude 3.5 Sonnet
   aws bedrock list-foundation-models --region us-west-2
   ```

2. **IAM Policy Requirements:**
   ```json
   {
     "Version": "2012-10-17",
     "Statement": [
       {
         "Effect": "Allow",
         "Action": [
           "bedrock:InvokeModel",
           "bedrock:InvokeModelWithResponseStream"
         ],
         "Resource": "arn:aws:bedrock:us-west-2::foundation-model/anthropic.claude-*"
       }
     ]
   }
   ```

3. **Model Access:**
   - Navigate to AWS Bedrock console
   - Ensure Claude 3.5 Sonnet is available in us-west-2
   - Request access if not already enabled

### Module Installation

```bash
# 1. Place module in custom directory (if not symlinked)
cp -r ai_conversation web/modules/custom/

# 2. Enable the module
cd sites/forseti
drush pm:enable ai_conversation

# 3. Clear caches
drush cache:rebuild

# 4. Verify AWS credentials are accessible
drush php:eval "echo \Drupal::state()->get('ai_conversation.bedrock_ready', 'NOT READY');"
```

## Configuration

### Environment Variables

Set these in your `.env` file or server environment:

```bash
# AWS Credentials (IAM recommended over keys)
AWS_ACCESS_KEY_ID=your_access_key
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-west-2

# Module Configuration (optional)
AI_CONVERSATION_MODEL=anthropic.claude-3-5-sonnet-20240620-v1:0
AI_CONVERSATION_TEMPERATURE=0.7
AI_CONVERSATION_MAX_TOKENS=2048
AI_CONVERSATION_SUMMARY_TRIGGER_MESSAGES=10
AI_CONVERSATION_RECENT_MESSAGE_LIMIT=20
```

### Drupal Configuration

Navigate to **Administration > Configuration > AI Conversation** to configure:

| Setting | Default | Description |
|---------|---------|-------------|
| Default AI Model | Claude 3.5 Sonnet | Primary model for all conversations |
| Max Tokens Per Request | 2048 | Maximum tokens for single AI response |
| Recent Messages to Keep | 20 | Number of recent messages kept in full |
| Summary Trigger | 10 | Number of messages before automatic summarization |
| Temperature | 0.7 | Model creativity (0.0-1.0) |
| AWS Region | us-west-2 | AWS Bedrock region |

## Usage

### Creating a Conversation

1. Navigate to **Content → Add content → AI Conversation**
2. **Required fields:**
   - **Title:** Name your conversation
   - **AI Model:** Select model (defaults to Claude 3.5 Sonnet)
   - **System Prompt:** Optional - customize AI behavior
3. **Optional:**
   - Description or notes
   - Custom temperature setting
4. **Save the node** - creates your conversation container

### Starting Chat

1. **Access chat interface:** Navigate to `/node/{nid}/chat`
   - Example: `https://forseti.life/node/11/chat`
2. **Chat interface:**
   - Message history area
   - Message input field with Send button
   - Conversation statistics panel
3. **Send message:**
   - Type in textarea
   - Click Send or press Enter (Shift+Enter for new line)
4. **Conversation continues:**
   - All messages stored in node
   - Statistics update in real-time
   - Automatic summarization when needed

### Example Chat Workflow

```bash
# Create conversation node programmatically
drush php:eval "
\$node = Node::create([
  'type' => 'ai_conversation',
  'title' => 'Project Planning',
  'field_ai_model' => 'anthropic.claude-3-5-sonnet-20240620-v1:0',
  'field_system_prompt' => 'You are a helpful project manager.',
  'status' => 1,
]);
\$node->save();
echo 'Created conversation node ' . \$node->id();
"

# Access chat at /node/{nid}/chat
curl http://localhost/node/1/chat
```

## Dependencies

| Module | Version | Required | Purpose |
|--------|---------|----------|---------|
| Drupal Core | 9+ | Yes | Core framework |
| text | - | Yes | Message field storage |
| field | - | Yes | Custom fields |
| node | - | Yes | Content entities |

## API Documentation

### Core Services

#### `AIApiService` - Main Bedrock Integration

```php
$ai_service = \Drupal::service('ai_conversation.api_service');

// Send message to Claude
$response = $ai_service->invokeModel(
  'Your message here',
  [
    'model' => 'anthropic.claude-3-5-sonnet-20240620-v1:0',
    'system_prompt' => 'You are a helpful assistant.',
    'temperature' => 0.7,
    'max_tokens' => 2048,
  ]
);

echo $response->content;
```

#### `ConversationService` - Node Management

```php
$conv_service = \Drupal::service('ai_conversation.conversation_service');

// Get conversation node
$node = $conv_service->getConversation($nid);

// Add message
$conv_service->addMessage($nid, 'user', 'Hello, AI!');
$conv_service->addMessage($nid, 'assistant', 'Hello! How can I help?');

// Get message history
$messages = $conv_service->getMessages($nid);

// Get statistics
$stats = $conv_service->getStats($nid);
echo "Messages: {$stats->message_count}, Tokens: {$stats->token_usage}";
```

#### `SummarizationService` - Rolling Summaries

```php
$summary_service = \Drupal::service('ai_conversation.summarization_service');

// Check if summarization needed
if ($summary_service->shouldSummarize($nid)) {
  $summary = $summary_service->createSummary($nid);
  echo "Summary: " . $summary->text;
}
```

### Hooks

#### `hook_ai_conversation_alter()`
Allows other modules to alter conversation parameters before API call.

```php
function mymodule_ai_conversation_alter(&$params) {
  // Customize temperature for specific conversations
  if ($params['context'] === 'research') {
    $params['temperature'] = 0.3; // Less creative
  }
}
```

#### `hook_ai_conversation_response()`
Called after receiving AI response. Allows modules to process or modify responses.

```php
function mymodule_ai_conversation_response(&$response, $nid) {
  // Log all AI responses
  \Drupal::logger('mymodule')->info('AI Response: ' . $response->content);
}
```

## Development

### File Structure

```
ai_conversation/
├── src/
│   ├── Controller/
│   │   └── ChatController.php          # Chat interface and message handling
│   ├── Service/
│   │   ├── AIApiService.php            # AWS Bedrock integration
│   │   ├── ConversationService.php     # Node CRUD operations
│   │   └── SummarizationService.php    # Rolling summarization logic
│   ├── Form/
│   │   ├── ConfigForm.php              # Module configuration
│   │   └── ConversationForm.php        # Conversation create/edit
│   ├── Plugin/
│   │   └── views/
│   │       └── filter/ (if views used)
│   └── Entity/
│       └── ConversationInterface.php    # Content type interface
├── templates/
│   ├── chat.html.twig                  # Chat interface template
│   ├── conversation-view.html.twig     # Single conversation view
│   └── statistics.html.twig            # Statistics panel
├── tests/
│   ├── Unit/
│   │   └── AIApiServiceTest.php
│   └── Functional/
│       └── ChatControllerTest.php
├── ai_conversation.info.yml
├── ai_conversation.module
├── ai_conversation.routing.yml
└── README.md (this file)
```

### Running Tests

```bash
cd sites/forseti

# Run all tests
drush test:run ai_conversation

# Run specific test class
drush test:run '\Drupal\Tests\ai_conversation\Unit\AIApiServiceTest'

# Run with verbose output
drush test:run ai_conversation --verbose
```

### Local Development

```bash
# Enable debug logging
drush config:set system.logging error_level all

# Clear cache after changes
drush cache:rebuild

# Watch for errors in Drupal logs
tail -f sites/forseti/files/logs/drupal.log | grep -i "ai_conversation"

# Test Bedrock connectivity
drush php:eval "
\$service = \Drupal::service('ai_conversation.api_service');
try {
  \$response = \$service->invokeModel('Hello', []);
  echo 'Bedrock connected: ' . \$response->content;
} catch (\Exception \$e) {
  echo 'Error: ' . \$e->getMessage();
}
"
```

### Common Tasks

```bash
# Rebuild rolling summary for a conversation
drush php:eval "
\$nid = 1;
\$service = \Drupal::service('ai_conversation.summarization_service');
\$service->rebuildSummary(\$nid);
echo 'Summary rebuilt for node ' . \$nid;
"

# Export conversation messages
drush php:eval "
\$nid = 1;
\$node = \Drupal\node\Entity\Node::load(\$nid);
\$messages = \$node->get('field_messages')->getValue();
echo json_encode(\$messages, JSON_PRETTY_PRINT);
"
```

## Contributing

Contributions are welcome! Please follow these guidelines:

1. **Fork the repository** and create a feature branch
2. **Code standards:**
   - Follow Drupal coding standards
   - Implement PHP 8.0+ features
   - Use typed properties and return types
3. **Testing:**
   - Write tests for new functionality
   - Ensure all tests pass: `drush test:run ai_conversation`
   - Minimum 80% code coverage required
4. **Documentation:**
   - Update README for new features
   - Document API changes
   - Include code examples
5. **Submit a pull request** with:
   - Clear description of changes
   - Link to related issues
   - Test results

## License

GPL-3.0-only. This module is part of the Forseti Life project.

See [LICENSE](LICENSE) file for full license text.

## Support

### Getting Help

- **Documentation:** See README.md and module code comments
- **Issues:** Report bugs on the project issue tracker
- **Contact:** maintainer@forseti.life
- **Community:** Drupal.org module page

### Troubleshooting

#### "AWS credentials not found"
- Verify `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are set
- Check AWS IAM permissions include Bedrock
- Use IAM roles (preferred) instead of credentials

#### "Claude model not available"
- Verify Claude 3.5 Sonnet is enabled in us-west-2
- Check AWS Bedrock console for model access
- Ensure region is us-west-2

#### "Conversation not saving"
- Verify node type `ai_conversation` exists
- Check database permissions
- Review database error logs

## Security

### Vulnerability Reporting

If you discover a security vulnerability, please email **security@forseti.life** instead of using the issue tracker. Do not publicly disclose security issues.

### Security Considerations

1. **AWS Credentials:**
   - Never hardcode credentials in code
   - Use IAM roles when possible
   - Rotate credentials regularly
   - Store in environment variables only

2. **Input Validation:**
   - All user messages sanitized before storage
   - System prompts validated to prevent injection
   - API parameters validated before Bedrock calls

3. **Output Encoding:**
   - All AI responses HTML-escaped in templates
   - JSON responses properly encoded
   - XSS protection enabled

4. **Access Control:**
   - Use Drupal permissions system
   - Verify user can view/edit conversations
   - Admin configuration restricted to trusted roles

5. **Data Privacy:**
   - Conversations stored in Drupal database
   - No logging of raw conversation content
   - Comply with data retention policies

## Maintenance

### Regular Tasks

- **Monthly:** Review AWS costs and token usage
- **Quarterly:** Test with latest Drupal versions
- **Quarterly:** Update Claude model references if new versions released
- **Annually:** Security audit of Bedrock integration

### Performance Monitoring

```bash
# Check average response times
SELECT 
  AVG(CAST(JSON_EXTRACT(data, '$.response_time') AS DECIMAL(10,3))) as avg_ms,
  COUNT(*) as total_requests
FROM ai_conversation_telemetry
WHERE created > DATE_SUB(NOW(), INTERVAL 7 DAY);

# Check token usage trends
SELECT 
  DATE(FROM_UNIXTIME(created)) as date,
  SUM(CAST(JSON_EXTRACT(data, '$.tokens_used') AS UNSIGNED)) as daily_tokens
FROM ai_conversation_telemetry
GROUP BY DATE(FROM_UNIXTIME(created))
ORDER BY date DESC;
```

### Updating Dependencies

```bash
# Check for updated AWS SDK
composer show aws/aws-sdk-php

# Update if available
composer require aws/aws-sdk-php:^latest

# Test after update
drush test:run ai_conversation --verbose
```

### Version History

| Version | Date | Notes |
|---------|------|-------|
| 2.1.0 | 2026-02-06 | Shared module release |
| 2.0.0 | 2026-01-15 | Rolling summary system |
| 1.0.0 | 2025-11-01 | Initial release |

