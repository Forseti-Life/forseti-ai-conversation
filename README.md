# AI Conversation

**Conversational AI interface with intelligent rolling summaries, powered by AWS Bedrock Claude 3.5 Sonnet for unlimited conversation length and context efficiency.**

## Badges

[![License: GPL-3.0](https://img.shields.io/badge/License-GPL%203.0-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
![Drupal Version](https://img.shields.io/badge/Drupal-9%20%7C%2010%20%7C%2011-blue)
![Status: Stable](https://img.shields.io/badge/Status-Stable-brightgreen)

## Overview

The AI Conversation module provides a sophisticated conversational AI interface powered by **AWS Bedrock and Claude 3.5 Sonnet**. It features an intelligent **rolling summary system** that allows for unlimited conversation length while maintaining context efficiency and managing token costs. Each conversation is stored as a Drupal node, enabling persistent, queryable chat history, integration with other modules, and full audit trails. Perfect for project planning, research collaboration, content generation, and complex multi-turn analysis workflows.

## Features

### 🤖 AWS Bedrock Integration
- **Primary Model**: Claude 3.5 Sonnet (anthropic.claude-3-5-sonnet-20240620-v1:0)
- **Region**: us-west-2
- **Authentication**: Environment variables or IAM roles (no hardcoded credentials)
- **Fallback Models**: Claude 3 Haiku and Claude 3 Opus support
- **Error Handling**: Automatic retry with exponential backoff
- **Cost Tracking**: Per-message token accounting

### 🔄 Intelligent Rolling Summary System
- **Automatic Summarization**: Older messages automatically summarized when conversation exceeds configured limits
- **Recent Message Retention**: Keeps the most recent N messages (default: 20) in full detail
- **Context Optimization**: Summary + recent messages provide optimal context for AI responses
- **Configurable Frequency**: Summary updates every N messages (default: 10)
- **Token Management**: Prevents context window overflow in long conversations
- **Summary Stability**: One-time summary per conversation lifecycle phase

### 💬 Real-time Chat Interface
- **AJAX-powered Messaging**: No page refreshes required
- **Live Statistics**: Real-time token count, message count, and conversation metrics
- **CSRF Protection**: Secure message sending with token validation
- **Access Control**: Users can only access their own conversations
- **Progressive Enhancement**: Works with JavaScript disabled
- **Responsive Design**: Mobile-friendly interface

### 📊 Advanced Analytics & Monitoring
- **Token Tracking**: Comprehensive input/output token monitoring
- **Conversation Statistics**: Message counts, summary status, recent activity
- **Debug Mode**: Detailed logging for troubleshooting
- **Performance Metrics**: Response times and API usage tracking
- **Telemetry Integration**: Optional events for external monitoring

### 🎯 Node-Centric Architecture
- **Persistent Storage**: All conversations stored as Drupal nodes
- **Custom Content Type**: `ai_conversation` type with specialized fields
- **Relationship Mapping**: Easy linking to other content (evaluations, projects, etc.)
- **Permission Control**: Drupal's native access control system
- **Workflow Integration**: Compatible with content moderation

## Installation

### Prerequisites
- Drupal 9, 10, or 11
- AWS Account with Bedrock access
- IAM credentials or instance role with Bedrock permissions

### Installation Steps

```bash
# 1. Place module in custom modules directory
# Already located at: web/modules/custom/ai_conversation/

# 2. Enable the ai_conversation module
drush en ai_conversation -y

# 3. Install database schema
drush updatedb -y

# 4. Clear cache
drush cache:rebuild

# 5. Configure AWS Bedrock settings
drush config:set ai_conversation.settings bedrock_region us-west-2 -y
```

### Verify Installation

```bash
# Check module is enabled
drush pm:list --type=module --status=enabled | grep ai_conversation

# Verify chat interface route exists
curl -sI http://localhost/node/1/chat | head -2

# Test AWS Bedrock connectivity
drush php:eval "echo \Drupal::service('ai_conversation.bedrock_client')->testConnection();"
```

## Configuration

### Module Settings

**Navigate to:** `admin/config/ai-conversation`

#### AWS Bedrock Settings
- **Region**: us-west-2 (configured for Bedrock availability)
- **Model ID**: anthropic.claude-3-5-sonnet-20240620-v1:0
- **Default Model**: Select which model to use for new conversations
- **Enable Caching**: Cache context per model (recommended)

#### Rolling Summary Configuration
- **Summary Trigger**: Number of messages before summarization (default: 10)
- **Recent Messages Count**: How many recent messages to retain (default: 20)
- **Max Context Tokens**: Token limit before forced summarization (default: 100,000)
- **Auto-Summarize**: Enable automatic summarization on message save

#### Performance Settings
- **Max Response Time**: Timeout for API calls (default: 30 seconds)
- **Retry Attempts**: Number of retries on API failure (default: 3)
- **Token Cost Threshold**: Alert when conversation token count exceeds limit
- **Cache Lifetime**: TTL for cached model configurations (default: 1 day)

#### Debug & Monitoring
- **Debug Mode**: Enable detailed logging (disable in production)
- **Log API Calls**: Log all Bedrock requests/responses
- **Performance Logging**: Track response times and token usage
- **Error Notifications**: Alert admins on API failures

### Permission Configuration

**Navigate to:** `admin/people/permissions`

Grant these permissions as needed:

| Permission | Role | Description |
|-----------|------|-------------|
| Administer AI Conversation | Admin | Full module configuration and debug access |
| Create AI Conversation | Content Creator | Can create new conversations |
| Edit Own AI Conversation | Content Creator | Can edit their own conversations |
| View AI Conversation | Authenticated | Can view conversations they own |
| Send Messages in AI Conversation | Authenticated | Can send chat messages |
| Export Conversation | Content Creator | Can export conversation data |

### AWS Bedrock Configuration

Configure AWS credentials via environment variables:

```bash
# Option 1: Environment variables (development)
export AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
export AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
export AWS_DEFAULT_REGION=us-west-2

# Option 2: IAM instance role (production - recommended)
# Attach IAM policy to EC2 instance with bedrock:InvokeModel permission
```

**Configuration File:** `settings.php` or `.env`

```php
// AWS Bedrock settings
$config['ai_conversation.settings']['bedrock_region'] = 'us-west-2';
$config['ai_conversation.settings']['default_model'] = 'anthropic.claude-3-5-sonnet-20240620-v1:0';
$config['ai_conversation.settings']['enable_debug'] = FALSE;

// Summary settings
$config['ai_conversation.settings']['summary_trigger'] = 10;
$config['ai_conversation.settings']['recent_messages'] = 20;
```

## Usage

### Step-by-Step Workflow

#### Step 1: Create Conversation Node

1. Navigate to **Content → Add content → AI Conversation**
2. Fill in required fields:
   - **Title**: Name your conversation (e.g., "Project Planning Discussion")
   - **AI Model**: Select model (defaults to Claude 3.5 Sonnet)
   - **Context** (optional): System prompt to guide AI behavior
3. Optional configuration:
   - Custom system prompt for specialized conversations
   - Choose different AI model if needed
   - Add description or notes
4. Click **Save** to create your conversation container

#### Step 2: Start Chatting

1. After saving, click **"Start Chat"** link or navigate to `/node/{nid}/chat`
   - Example: `https://forseti.com/node/11/chat`
2. Chat interface loads with:
   - Message history area (empty for new conversations)
   - Message input field with Send button
   - Conversation statistics panel
   - Loading indicators and controls

#### Step 3: Send Messages

1. Type your message in the textarea
2. Send using either:
   - Click "Send" button
   - Press Enter (Shift+Enter for new line)
3. Message processing:
   - User message appears immediately in chat
   - Loading indicator shows "AI is thinking..."
   - AI response appears when complete
   - Statistics update in real-time

#### Step 4: Ongoing Conversation

1. Continue chatting - all messages stored in the node
2. Monitor conversation statistics:
   - Total token usage
   - Message count
   - Summary status
3. Automatic summarization occurs in background (if enabled)
4. Conversation preserved between sessions

### Conversation Templates

Create specialized conversations with custom contexts:

#### Project Planning Template
```
Context: "You are an expert project manager. Help me plan this project by:
1. Breaking it into phases and milestones
2. Identifying risks and mitigation strategies
3. Creating realistic timelines
4. Suggesting resource requirements"
```

#### Code Review Template
```
Context: "You are an experienced code reviewer. Review code for:
1. Security vulnerabilities
2. Performance issues
3. Code style and maintainability
4. Testing coverage
Use specific examples and provide constructive feedback."
```

#### Content Strategy Template
```
Context: "You are a content strategist. For this content topic, help me:
1. Define target audience and their needs
2. Create outline with key sections
3. Suggest content formats (blog, video, infographic)
4. Plan distribution channels"
```

### Real-World Examples

#### Example 1: Product Requirements Discussion

```
User: "I need to build a customer feedback system. What should I include?"

AI: "Great project! A comprehensive feedback system should include:

1. **Feedback Collection**
   - In-app feedback widget
   - Email surveys
   - Post-transaction prompts
   - Social listening

2. **Data Management**
   - Centralized storage
   - Data categorization
   - Sentiment analysis
   - Trend detection

3. **Action Management**
   - Assignment workflow
   - Priority tracking
   - Resolution feedback loop

Should we dive deeper into any of these areas?"

User: "Tell me more about the in-app feedback widget. How would you design it?"

AI: [Detailed design discussion with code examples...]
```

#### Example 2: Complex Technical Analysis

```
User: "How do I optimize this SQL query? It's taking 5 seconds on 1M records."

AI: "Let me analyze... [Full optimization strategy]

1. Add index on frequently filtered columns
2. Use EXPLAIN ANALYZE to identify bottlenecks
3. Consider materialized views for complex aggregations

Show me your current query and I'll provide specific recommendations."
```

### Advanced Features

#### Export Conversation

```bash
# Export as JSON
curl -H "Accept: application/json" \
  http://localhost/api/conversations/123/export \
  > conversation.json

# Export as Markdown
curl -H "Accept: text/markdown" \
  http://localhost/api/conversations/123/export \
  > conversation.md
```

#### Programmatic Access

```php
// Load a conversation node
$node = Node::load(123);

// Access messages
$messages = json_decode($node->field_messages->value, TRUE);

// Send a message programmatically
$response = \Drupal::service('ai_conversation.chat_service')
  ->sendMessage($node, 'What are the next steps?');

// Access conversation statistics
$stats = $response->getStatistics();
echo "Tokens used: " . $stats->getTotalTokens();
```

## Dependencies

### Required
- **Drupal Node Module**: Core entity storage system
- **Drupal Field Module**: Core field system for storing messages and settings
- **Drupal User Module**: Authentication and user permissions
- **Drupal System Module**: Core system hooks and services

### Optional
- **Views Module**: For creating conversation listings and filters
- **REST API Module**: For programmatic conversation access
- **Serialization Module**: For JSON/XML export functionality

### External Services
- **AWS Bedrock**: Claude 3.5 Sonnet model inference
- **AWS Region**: us-west-2 (required for Bedrock availability)

### System Requirements
- PHP 8.0+ (8.2+ recommended)
- Composer (for AWS SDK)
- cURL (for HTTP requests)

## API Documentation

### REST Endpoints

#### Create Conversation

```
POST /api/conversations
Content-Type: application/json

{
  "title": "Project Planning Session",
  "field_ai_model": "claude-3-5-sonnet",
  "field_context": "You are a project manager..."
}

Response: 201 Created
{
  "nid": 123,
  "uri": "/api/conversations/123"
}
```

#### Send Message

```
POST /api/conversations/123/messages
Content-Type: application/json

{
  "content": "What are the key risks?"
}

Response: 200 OK
{
  "message_id": "msg_456",
  "role": "assistant",
  "content": "Based on our discussion...",
  "tokens": {
    "input": 250,
    "output": 512,
    "total": 762
  }
}
```

#### Retrieve Conversation

```
GET /api/conversations/123

Response: 200 OK
{
  "nid": 123,
  "title": "Project Planning",
  "messages_count": 12,
  "total_tokens": 8493,
  "created": 1704067200,
  "updated": 1704070800,
  "summary": "Summary of conversation..."
}
```

#### Export Conversation

```
GET /api/conversations/123/export?format=json

Response: 200 OK
{
  "title": "Project Planning",
  "messages": [
    {
      "role": "user",
      "content": "...",
      "timestamp": 1704067200
    },
    {
      "role": "assistant",
      "content": "...",
      "timestamp": 1704067201
    }
  ]
}
```

### Drupal Hooks

#### Custom Message Processing

```php
// Process message before sending
function my_module_ai_conversation_message_preprocess(&$message, $node) {
  // Modify message, add context, etc.
  $message = str_replace('{user_name}', 'John', $message);
}
```

#### Post-Message Hook

```php
// Hook after message is added
function my_module_ai_conversation_message_insert($message, $node) {
  // Log message, trigger workflows, etc.
  \Drupal::logger('my_module')->info('Message added: %msg', [
    '%msg' => substr($message, 0, 100)
  ]);
}
```

## Development

### Module Architecture

```
ai_conversation/
├── src/
│   ├── Controller/
│   │   └── ChatController.php (Chat interface and AJAX)
│   ├── Service/
│   │   ├── ChatService.php (Message processing)
│   │   ├── BedrockClient.php (AWS integration)
│   │   ├── SummaryService.php (Rolling summary logic)
│   │   └── TokenCounter.php (Token accounting)
│   └── Plugin/
│       └── ... (Drupal integrations)
├── config/
│   ├── schema/ (Settings schema)
│   └── install/ (Default configuration)
├── templates/
│   └── (Chat interface Twig templates)
├── js/
│   └── chat-interface.js (AJAX and UI)
├── css/
│   └── chat-interface.css (Styling)
└── ai_conversation.module (Hooks)
```

### Key Services

#### ChatService

```php
// Send a message
$service = \Drupal::service('ai_conversation.chat_service');
$response = $service->sendMessage($node, $user_message);

// Get conversation stats
$stats = $service->getConversationStats($node);
echo "Total tokens: " . $stats->getTotalTokens();
```

#### BedrockClient

```php
// Invoke Bedrock directly
$client = \Drupal::service('ai_conversation.bedrock_client');
$response = $client->invokeModel(
  'anthropic.claude-3-5-sonnet-20240620-v1:0',
  ['messages' => $messages]
);
```

#### SummaryService

```php
// Trigger summarization
$summary_service = \Drupal::service('ai_conversation.summary_service');
$summary = $summary_service->summarizeMessages($node, $messages);

// Update node with summary
$node->field_conversation_summary->value = $summary;
$node->save();
```

### Testing

```bash
# Run unit tests
cd web/modules/custom/ai_conversation
../../../vendor/bin/phpunit tests/Unit/

# Run functional tests with mocked Bedrock
../../../vendor/bin/phpunit tests/Functional/

# Run integration tests (requires AWS credentials)
AWS_ACCESS_KEY_ID=... ../../../vendor/bin/phpunit tests/Integration/
```

### Local Development Setup

```bash
# 1. Create .env.local for development credentials
cat > .env.local << EOF
AWS_ACCESS_KEY_ID=dev_key
AWS_SECRET_ACCESS_KEY=dev_secret
AWS_DEFAULT_REGION=us-west-2
EOF

# 2. Enable debug mode
drush config:set ai_conversation.settings debug_mode TRUE -y

# 3. View debug logs
drush watchdog:tail ai_conversation

# 4. Test Bedrock connection
drush php:eval "
  \$client = \Drupal::service('ai_conversation.bedrock_client');
  echo \$client->testConnection() ? 'Connected!' : 'Connection failed';
"
```

### Performance Optimization

```php
// Enable message caching
$config['ai_conversation.settings']['cache_messages'] = TRUE;

// Configure summary triggers for efficiency
$config['ai_conversation.settings']['summary_trigger'] = 15;  // Every 15 messages
$config['ai_conversation.settings']['recent_messages'] = 25;  // Keep last 25

// Batch process token accounting
$batch = [
  'title' => 'Recalculating tokens...',
  'operations' => [
    ['ai_conversation_batch_tokens', []],
  ],
];
batch_set($batch);
```

## Contributing

### Contribution Guidelines

We welcome contributions! Please follow these guidelines:

1. **Fork & Branch**: Create a feature branch (`feature/my-feature`)
2. **Code Standards**: Follow Drupal coding standards (phpcs)
3. **Tests**: Add tests for new functionality
4. **Documentation**: Update this README for new features
5. **Commit Message**: Use descriptive messages with issue references

### Code Quality

```bash
# Check code standards
phpcs src/

# Fix formatting
phpcbf src/

# Run static analysis
phpstan --level=7 src/

# Check test coverage
phpunit --coverage-html=coverage/ tests/
```

### Reporting Issues

When reporting issues, please include:
- Drupal version
- Module version
- AWS region and model used
- Reproduction steps
- Expected vs. actual behavior
- Conversation token count when issue occurred

### Security Considerations

- **No Credentials in Code**: Never commit AWS keys or secrets
- **Input Validation**: Sanitize all user input before sending to API
- **Output Encoding**: Always escape output in templates
- **CSRF Protection**: All forms include token validation
- **Access Control**: Verify permissions before exposing conversation data
- **Data Privacy**: Respect user privacy for conversation content

### Performance Optimization

Key optimization strategies:

```php
// Cache Bedrock models
\Drupal::cache('default')->set('bedrock_models', $models, 86400);

// Batch summarization
foreach ($conversations as $node) {
  if ($node->field_message_count->value > 50) {
    $this->summarizationQueue->createItem(['nid' => $node->id()]);
  }
}

// Token tracking
$stats = [
  'total_messages' => $message_count,
  'total_tokens' => $token_count,
  'avg_tokens_per_message' => $token_count / $message_count,
];
```

## License

This module is licensed under the **GNU General Public License v3.0 (GPL-3.0-only)**.

See the LICENSE file for full details.

```
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, version 3 of the License.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## Support

### Getting Help

- **Documentation**: See ARCHITECTURE.md, GENAI_CACHING.md, and AI_TROUBLESHOOTING.md
- **Issues**: File bugs via issue tracker
- **Community**: Ask questions in Drupal forums
- **Commercial Support**: Contact module maintainers

### Common Issues

#### AWS Bedrock Connection Fails

```bash
# Check credentials
echo "Access Key: ${AWS_ACCESS_KEY_ID:0:10}..."
echo "Region: $AWS_DEFAULT_REGION"

# Test Bedrock API
aws bedrock list-foundation-models --region us-west-2

# Check IAM policy includes Bedrock permissions
aws iam get-user-policy --user-name your-user --policy-name your-policy
```

#### Messages Not Saving

```bash
# Check field_messages database
drush sql:query "SELECT * FROM node__field_messages WHERE entity_id=123;"

# Verify permissions
drush php:eval "
  \$user = \Drupal\user\Entity\User::load(1);
  echo \$user->hasPermission('send messages in ai conversation') ? 'Yes' : 'No';
"
```

#### High Token Usage

```bash
# Monitor token spending
drush watchdog:tail ai_conversation | grep tokens

# Reduce recent message count
drush config:set ai_conversation.settings recent_messages 15 -y

# Increase summary frequency
drush config:set ai_conversation.settings summary_trigger 8 -y
```

#### Performance Degradation

```bash
# Check conversation sizes
drush sql:query "
  SELECT entity_id, LENGTH(field_messages_value) as size_bytes 
  FROM node__field_messages 
  ORDER BY size_bytes DESC LIMIT 10;
"

# Archive old conversations
drush eval "
  \$nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['type' => 'ai_conversation', 'created' => [time() - 7776000, '<']]);
  foreach (\$nodes as \$node) \$node->delete();
"
```

## Security

### Security Considerations

#### Authentication & Authorization
- **Node Access**: Respects Drupal's node access control system
- **User Isolation**: Users can only access their own conversations
- **CSRF Protection**: All AJAX endpoints use Drupal's token validation
- **API Authorization**: Requires user authentication for REST endpoints

#### Data Protection
- **AWS Encryption**: Data in transit over HTTPS to AWS Bedrock
- **Input Sanitization**: All user input validated before API calls
- **Output Escaping**: All responses escaped in templates
- **No Credential Storage**: AWS credentials never stored in database

#### Audit Trail
- **Message Logging**: All messages timestamped and stored in nodes
- **Change Tracking**: Version control through Drupal's revision system
- **Admin Visibility**: Administrators can view conversation summaries
- **Compliance Ready**: Suitable for GDPR, SOC 2, and HIPAA compliance

### Reporting Security Issues

Do not file public security issues. Instead:
1. Email security concerns to maintainers
2. Include detailed reproduction steps
3. Allow 90 days for response and patching

## Maintenance

### Upgrade Path

```bash
# Update module
cd web/modules/custom/ai_conversation
git pull origin main

# Run database updates
drush updatedb -y

# Clear cache
drush cache:rebuild

# Verify
drush pm:list --type=module | grep ai_conversation
```

### Database Maintenance

```bash
# Optimize message storage
drush sql:query "OPTIMIZE TABLE node__field_messages, node__field_conversation_summary;"

# Archive old conversations (optional)
drush sql:query "
  UPDATE node SET status=0 
  WHERE type='ai_conversation' AND created < DATE_SUB(NOW(), INTERVAL 2 YEAR)
"

# View database stats
drush sql:query "
  SELECT COUNT(*) as total, 
    AVG(LENGTH(field_messages_value)) as avg_size 
  FROM node__field_messages 
  WHERE entity_id IN (SELECT nid FROM node WHERE type='ai_conversation');
"
```

### Monitoring & Alerts

Monitor these metrics:

| Metric | Target | Action if Exceeded |
|--------|--------|-------------------|
| Average Response Time | < 2s | Optimize Bedrock region/model |
| API Errors | < 0.5% | Check AWS service status |
| Token Usage | < Budget | Configure alerts or limits |
| Database Size | < 20GB | Archive or optimize queries |
| Failed Messages | < 1% | Review error logs |

### Version History

- **1.0.0** (Feb 2026): Initial release with Bedrock integration
- **1.1.0** (Future): Multi-model support and model switching
- **1.2.0** (Future): Conversation branching and versioning
- **2.0.0** (Future): Custom fine-tuned models support
