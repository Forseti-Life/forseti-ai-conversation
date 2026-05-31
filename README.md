# AI Conversation Module

**Last Updated:** February 18, 2026

# AI Conversation Module

## Overview

The AI Conversation module provides a sophisticated conversational AI interface powered by AWS Bedrock and Claude 3.5 Sonnet. It features an intelligent **rolling summary system** that allows for unlimited conversation length while maintaining context efficiency and managing token costs.

For Dungeoncrawler, the assistant persona is configured as **Forseti, the Game Master**, with all player-facing chat copy and default prompt language aligned to that voice.

## Complete Workflow

### 🎯 **Node-Based Architecture**

The AI Conversation module uses a **node-centric approach** where each conversation is stored as a Drupal content entity:

1. **Create AI Conversation Node** → Each conversation is a `ai_conversation` content type node
2. **Node as Storage Container** → All messages, settings, and metadata stored in node fields  
3. **Chat Interface via Node** → Access chat at `/node/{nid}/chat`
4. **Persistent Conversations** → Full conversation history maintained in the node

### 📋 **Step-by-Step User Workflow**

#### **Step 1: Create Conversation Node**
1. Navigate to **Content → Add content → AI Conversation**
2. **Required fields:**
   - **Title:** Name your conversation (e.g., "Project Planning Discussion")
   - **AI Model:** Select model (defaults to Claude 3.5 Sonnet)
   - **Context:** Optional system prompt to guide AI behavior

3. **Optional configuration:**
   - Set custom system prompt for specialized conversations
   - Choose different AI model if needed
   - Add description or notes

4. **Save the node** → This creates your conversation container

#### **Step 2: Start Chatting**
1. **Access chat interface:** Navigate to `/node/{nid}/chat` 
  - Example: `https://dungeoncrawler.forseti.life/node/11/chat`
   - Or click "Start Chat" link from node view page

2. **Chat interface loads:**
   - Message history area (empty for new conversations)
   - Message input field with Send button
   - Conversation statistics panel
   - Loading indicators and controls

#### **Step 3: Send Messages**
1. **Type message** in the textarea input field
2. **Send methods:**
   - Click "Send" button
   - Press Enter (Shift+Enter for new line)
   
3. **Message processing:**
   - User message immediately appears in chat
   - Loading indicator shows "AI is thinking..."
   - AI response appears when complete
   - Statistics update in real-time

#### **Step 4: Ongoing Conversation**
1. **Continue chatting** - all messages stored in the node
2. **Statistics tracking** - monitor token usage and message count
3. **Automatic summarization** - when conversation gets long
4. **Persistent storage** - conversation preserved between sessions

## Key Features

### 🤖 **AWS Bedrock Integration**
- **Primary Model:** Claude 3.5 Sonnet (anthropic.claude-3-5-sonnet-20240620-v1:0)
- **Region:** us-west-2
- **Authentication:** Environment variables or IAM roles (no hardcoded credentials)
- **Fallback Models:** Claude 3 Haiku and Claude 3 Opus support

### 🔄 **Intelligent Rolling Summary System**
- **Automatic Summarization:** Older messages are automatically summarized when conversation exceeds configured limits
- **Recent Message Retention:** Keeps the most recent N messages (default: 20) in full detail
- **Context Optimization:** Summary + recent messages provide optimal context for AI responses
- **Configurable Frequency:** Summary updates every N messages (default: 10)
- **Token Management:** Prevents context window overflow in long conversations

### 💬 **Real-time Chat Interface**
- **AJAX-powered messaging:** No page refreshes required
- **Live statistics:** Real-time token count, message count, and conversation metrics
- **CSRF protection:** Secure message sending with token validation
- **Access control:** Users can only access their own conversations
- **Progressive enhancement:** Works with JavaScript disabled

### 📊 **Advanced Analytics & Monitoring**
- **Token tracking:** Comprehensive input/output token monitoring
- **Conversation statistics:** Message counts, summary status, recent activity
- **Debug mode:** Detailed logging for troubleshooting
- **Performance metrics:** Response times and API usage tracking

## Technical Architecture

## Technical Architecture Deep Dive

### 🏗️ **Node-Centric Storage System**

The module creates a custom content type `ai_conversation` that serves as the complete storage container:

#### **Content Type: `ai_conversation`**

**Node URL Pattern:** `/node/{nid}/chat` for chat interface

**Core Fields:**
- **`field_messages`** (text_long, unlimited): JSON-encoded message objects
  ```json
  {
    "role": "user|assistant", 
    "content": "message text",
    "timestamp": 1704067200
  }
  ```
- **`field_ai_model`** (string): AI model identifier (defaults to Claude 3.5 Sonnet)
- **`field_context`** (text_long): System prompt/conversation context

**Rolling Summary Fields:**
- **`field_conversation_summary`** (text_long): AI-generated summary of older messages
- **`field_message_count`** (integer): Total message count for the conversation
- **`field_summary_updated`** (timestamp): When summary was last regenerated
- **`field_summary_message_count`** (integer): Counter for summary frequency logic
- **`field_total_tokens`** (integer): Cumulative token usage tracking

### 🔄 **Request/Response Flow**

#### **Chat Interface Loading (`/node/{nid}/chat`)**
1. **Route:** `ai_conversation.chat_interface`
2. **Controller:** `ChatController::chatInterface()`
3. **Access Control:** Node owner or admin only
4. **Data Loading:**
   - Load conversation node
   - Extract recent messages for display
   - Calculate conversation statistics
   - Build render array with JavaScript settings

#### **AJAX Message Sending (`/ai-conversation/send-message`)**
1. **Route:** `ai_conversation.send_message` (POST with CSRF)
2. **Controller:** `ChatController::sendMessage()`
3. **Process Flow:**
   ```php
   // 1. Validate CSRF token and parameters
   $token = $request->request->get('csrf_token');
   $node_id = $request->request->get('node_id');
   $message = $request->request->get('message');
   
   // 2. Load and validate conversation node
   $node = $this->entityTypeManager->getStorage('node')->load($node_id);
   
   // 3. Add user message to node
   $user_message = [
     'role' => 'user',
     'content' => $message,
     'timestamp' => time(),
   ];
   $this->addMessageToNode($node, $user_message);
   $node->save();
   
   // 4. Get AI response (includes summary check)
   $ai_response = $this->aiApiService->sendMessage($node, $message);
   
   // 5. Add AI message to node
   $ai_message = [
     'role' => 'assistant', 
     'content' => $ai_response,
     'timestamp' => time(),
   ];
   $this->addMessageToNode($node, $ai_message);
   $node->save();
   
   // 6. Return response with updated stats
   return new JsonResponse([
     'success' => TRUE,
     'response' => $ai_response,
     'stats' => $this->aiApiService->getConversationStats($node),
   ]);
   ```

### 🧠 **AIApiService - Core AI Logic**

**Location:** `src/Service/AIApiService.php`

**Primary Method:** `sendMessage(NodeInterface $conversation, string $message)`

**Processing Steps:**
1. **Context Building:**
   ```php
   // Build optimized context from node data
   $context = $this->buildOptimizedContext($conversation, $message);
   // Context = system prompt + summary + recent messages + current message
   ```

2. **Summary Check:**
   ```php
   // Auto-summarize if thresholds exceeded
   $this->checkAndUpdateSummary($conversation);
   // Triggers when: message_count > threshold OR tokens > limit
   ```

3. **AWS Bedrock Call:**
   ```php
   // Send to Claude 3.5 Sonnet
   $response = $bedrock->invokeModel([
     'modelId' => 'anthropic.claude-3-5-sonnet-20240620-v1:0',
     'contentType' => 'application/json',
     'body' => json_encode($request_body)
   ]);
   ```

4. **Response Processing:**
   ```php
   // Extract AI response and update node statistics
   $content = json_decode($response['body'], true);
   return $content['content'][0]['text'];
   ```

### 🎛️ **Frontend JavaScript Integration**

**File:** `js/chat-interface.js`

**Initialization:**
```javascript
Drupal.behaviors.aiConversationChat = {
  attach: function(context, settings) {
    const chatSettings = settings.aiConversation || {};
    // Settings include: nodeId, sendMessageUrl, csrfToken, stats
  }
};
```

**AJAX Message Flow:**
```javascript
function sendMessage() {
  console.log('🚀 Starting sendMessage for node:', chatSettings.nodeId);
  
  $.ajax({
    url: chatSettings.sendMessageUrl,  // '/ai-conversation/send-message'
    type: 'POST',
    data: {
      node_id: chatSettings.nodeId,    // The conversation node ID
      message: message,
      csrf_token: chatSettings.csrfToken
    },
    success: function(response) {
      // Add AI response to chat interface
      addMessageToChat('assistant', response.response);
      // Update statistics display
      updateMetricsDisplay(response.stats);
    }
  });
}
```

### 🔐 **Security & Access Control**

**Access Method:** `ChatController::chatAccess()`
```php
public function chatAccess(NodeInterface $node, AccountInterface $account) {
  // Only ai_conversation nodes
  if ($node->bundle() !== 'ai_conversation') {
    return AccessResult::forbidden();
  }
  
  // Node owner or admin only
  if ($node->getOwnerId() === $account->id() || 
      $account->hasPermission('administer content')) {
    return AccessResult::allowed();
  }
  
  return AccessResult::forbidden();
}
```

**CSRF Protection:** All POST requests require valid CSRF tokens

### 📊 **Statistics & Monitoring**

**Real-time Stats Endpoint:** `/ai-conversation/stats?node_id={nid}`

**Statistics Calculated:**
- **Total Messages:** Complete message count from `field_message_count`
- **Recent Messages:** Count of messages currently stored in `field_messages`
- **Has Summary:** Boolean if `field_conversation_summary` exists
- **Token Estimates:** Calculated from message content length
- **Summary Status:** Last update timestamp

### 🔄 **Rolling Summary System Implementation**

**Trigger Logic:** `checkAndUpdateSummary()` in `AIApiService`
```php
$message_count = $conversation->get('field_message_count')->value ?: 0;
$max_recent = $this->config->get('max_recent_messages') ?: 10;

if ($message_count > $max_recent) {
  $this->updateConversationSummary($conversation);
  $this->pruneOldMessages($conversation);
}
```

**Summary Generation:**
1. **Collect older messages** beyond recent limit
2. **Send to AI** with summarization prompt
3. **Update** `field_conversation_summary` 
4. **Remove old messages** from `field_messages`
5. **Update timestamps** and counters

### **Frontend Integration**

#### **Chat Interface Template**
**Location:** `templates/ai-conversation-chat.html.twig`

**Features:**
- **Message History**: Displays conversation with role-based styling
- **Live Statistics**: Real-time metrics (token count, message count, summary status)
- **Summary Indicator**: Visual indication when conversation has been summarized
- **Context Display**: Shows system prompt/context information

#### **JavaScript Integration**
**Location:** `js/chat-interface.js`

**Functionality:**
- **AJAX Messaging**: Real-time message sending without page reload
- **Live Metrics**: Auto-updating conversation statistics every 30 seconds
- **Progressive Enhancement**: Graceful degradation without JavaScript
- **Loading States**: Visual feedback during AI response generation

#### **CSS Styling**
**Location:** `css/chat-interface.css`

**Design Elements:**
- **Message Bubbles**: Distinct styling for user vs AI messages
- **Statistics Panel**: Collapsible metrics display
- **Responsive Layout**: Mobile-friendly design
- **Loading Animations**: Visual feedback for processing states

### **Configuration System**

#### **Settings Form** (`/admin/config/ai-conversation`)
**Location:** `src/Form/SettingsForm.php`

**Configuration Options:**
- **API Settings**: Max tokens per response (default: 4000)
- **Rolling Summary**: Max recent messages (default: 10), frequency (default: 20)
- **Token Management**: Max tokens before summary trigger (default: 6000)
- **Debug Options**: Logging level, statistics display
- **Connection Testing**: Live AWS Bedrock connectivity check

### **Permission System**

**Defined Permissions:**
- **`use ai conversation`**: Access to AI chat features
- **`administer ai conversation`**: Module configuration access

**Content Type Permissions** (auto-granted to authenticated users):
- **`create ai_conversation content`**
- **`edit own ai_conversation content`**
- **`delete own ai_conversation content`**
- **`view own ai_conversation content`**

## Installation & Setup

### **Module Installation**
1. Enable the `ai_conversation` module
2. The install process automatically:
   - Creates `ai_conversation` content type
   - Adds all required fields
   - Sets up default permissions

### **Module Uninstallation (Data Preservation)**
⚠️ **Important:** The ai_conversation module uses a **safe uninstall process** that preserves your conversation data.

**Uninstall Behavior:**
- **Fields Preserved:** All conversation fields and data remain intact
- **Content Type:** Only removed if no conversations exist
- **Settings:** Module configuration is removed
- **Data Safety:** No conversation data is lost during uninstall

**Complete Data Removal (Optional):**
If you need to completely remove all conversation data, use this Drush command:
```bash
drush php-eval "_ai_conversation_complete_removal();"
```
**⚠️ WARNING:** This permanently deletes all conversation data!

### **AWS Bedrock Configuration**

#### **Environment Variables (Recommended)**
```bash
AWS_ACCESS_KEY_ID=your_access_key_id
AWS_SECRET_ACCESS_KEY=your_secret_key
AWS_DEFAULT_REGION=us-west-2
```

#### **IAM Role (Production)**
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": "bedrock:InvokeModel",
      "Resource": "arn:aws:bedrock:us-west-2::foundation-model/anthropic.claude-*"
    }
  ]
}
```

### **Module Configuration**
1. Navigate to `/admin/config/ai-conversation`
2. Configure token limits and summary settings
3. Test AWS Bedrock connection
4. Adjust debug settings as needed

## Usage

### **Creating Conversations**
1. Create a new "AI Conversation" content node
2. Set title and optional system context
3. Save the node
4. Visit `/node/{nid}/chat` to start chatting

### **Chat Features**
- **Send Messages**: Type and send messages to the AI
- **View History**: See conversation history with user/AI message distinction
- **Monitor Usage**: Track token usage and conversation statistics
- **Summary Management**: View when conversation has been summarized

### **Administrative Features**
- **Manual Summary**: Force summary generation via `/node/{nid}/trigger-summary`
- **Statistics Monitoring**: Real-time conversation metrics
- **Debug Logging**: Detailed logging for troubleshooting
- **Connection Testing**: Validate AWS Bedrock connectivity

## Integration with Other Modules

### **As Foundation Service Provider**
The ai_conversation module serves as the foundational AI service for other modules:

```php
// Other modules can use the AI service
$ai_service = \Drupal::service('ai_conversation.api_service');
$response = $ai_service->sendMessage($conversation_node, $message);
```

### **Dependent Modules**
- **resume_tailoring**: Uses AI service for resume customization
- **Future AI modules**: Can leverage centralized AI infrastructure

## Token Economy & Cost Management

### **Token Estimation**
- **Rough Formula**: 1 token ≈ 4 characters
- **Context Optimization**: Summary + recent messages minimize token usage
- **Automatic Management**: Rolling summary prevents exponential token growth

### **Cost Efficiency Features**
- **Smart Summarization**: Reduces context size while preserving information
- **Configurable Limits**: Adjustable max tokens and summary frequency
- **Usage Tracking**: Monitor token consumption per conversation
- **Model Selection**: Support for different Claude models (cost vs capability)

## Troubleshooting

### **Common Issues**

#### **"Failed to communicate with AI service"**
- Check AWS credentials (environment variables or IAM role)
- Verify network connectivity to AWS Bedrock
- Test connection via settings page

#### **"Summary generation failed"**
- Check AWS Bedrock permissions
- Verify model availability in us-west-2 region
- Review error logs for detailed messages

#### **Missing conversation history**
- Check if conversation has been summarized (look for summary indicator)
- Recent messages are preserved, older messages are in summary
- Use manual summary trigger for testing

### **Debug Mode**
Enable debug mode in settings to get detailed logging:
- API request/response details
- Token usage breakdown
- Summary generation process
- Performance metrics

This module provides a production-ready, scalable foundation for AI-powered conversations with intelligent context management and cost optimization.
- **Region**: `us-west-2`
- **Max Tokens**: Configurable (default: 4000)

### Core Architecture

#### AIApiService Class
Main service handling AI communication and conversation management.

**Key Methods**:
- `sendMessage(NodeInterface $conversation, string $message)`: Send messages to AI
- `buildOptimizedContext()`: Creates context with summary + recent messages
- `checkAndUpdateSummary()`: Manages rolling summary updates
- `estimateTokens()`: Estimates token usage for optimization

#### Rolling Summary System
- **Automatic Summarization**: Triggers when conversation exceeds thresholds
- **Context Optimization**: Maintains summary + recent messages only
- **Token-Based Logic**: Summarizes based on token count, not just message count
- **Intelligent Pruning**: Removes older messages while preserving context

### Database Schema

#### Conversation Content Type Fields
- **`field_conversation_summary`**: Stores rolling summary of older messages
- **`field_message_count`**: Tracks total messages for summary logic
- **`field_summary_updated`**: Timestamp of last summary update
- **`field_total_tokens`**: Running count of tokens used
- **`field_ai_model`**: Selected AI model for the conversation

## 🚀 Installation

1. Enable the module: `drush pm:enable ai_conversation`
2. Run database updates: `drush updatedb`
3. Configure AWS credentials for Bedrock access
4. Configure module settings
5. Clear cache: `drush cr`

## 📋 Requirements

- Drupal 9, 10, or 11
- Node module
- AWS SDK for PHP
- AWS Bedrock access with Claude model permissions

## 🔑 Configuration

### AWS Bedrock Credentials

The module supports multiple ways to configure AWS credentials (in order of precedence):

1. **Admin Interface** (Recommended)
   - Navigate to `/admin/config/ai-conversation/settings`
   - Enter your AWS credentials through the web form
   
2. **Environment Variables** (Automatic fallback)
   - Set `AWS_ACCESS_KEY_ID`
   - Set `AWS_SECRET_ACCESS_KEY` 
   - Set `AWS_DEFAULT_REGION` (optional, defaults to us-west-2)
   
   The module will automatically use environment variables if configuration values are empty.

3. **AWS Default Credential Chain** (For AWS infrastructure)
   - IAM roles, instance profiles, ECS task roles, etc.
   - No configuration needed when running on AWS

4. **Drush Commands** (For automated deployments)
   ```bash
   ./vendor/bin/drush config:set ai_conversation.settings aws_access_key_id "YOUR_ACCESS_KEY"
   ./vendor/bin/drush config:set ai_conversation.settings aws_secret_access_key "YOUR_SECRET_KEY"
   ```

### Required AWS Permissions
Your credentials need:
- `bedrock:InvokeModel` for Claude models
- Access to specific model ARNs in your region

### Module Configuration
Navigate to **Admin → Configuration → AI Conversation Settings** to configure:

#### Memory Management
- **Max Recent Messages**: Number of recent messages to keep (default: 10)
- **Token Threshold**: Maximum tokens before triggering summary (default: 6000)
- **Summary Frequency**: Update summary every N messages (default: 20)

#### Response Settings
- **Max Tokens**: Maximum tokens for AI responses (default: 4000)
- **Model Selection**: Choose AI model (supports fallback to default)

#### Debug Options
- **Debug Mode**: Enable detailed logging
- **Statistics Display**: Show token usage and conversation stats

## 📊 Usage

### Starting a Conversation
1. **Create Conversation Node**: Add new AI Conversation content
2. **Configure Settings**: Select AI model and parameters
3. **Begin Chat**: Use the chat interface to interact with AI

### Chat Interface Features
- **Real-time Responses**: Immediate AI responses
- **Message History**: Full conversation history with timestamps
- **Context Awareness**: AI maintains conversation context
- **Auto-scrolling**: Interface automatically scrolls to new messages

### Conversation Management
- **Automatic Summarization**: System manages context automatically
- **Token Tracking**: Monitor usage in conversation details
- **Context Optimization**: Recent messages + summary for best performance

## 🎨 Advanced Features

### Rolling Summary System
The module implements a sophisticated memory management system:

1. **Monitors Conversation Length**: Tracks both message count and token usage
2. **Triggers Summarization**: When thresholds are exceeded
3. **Generates Summary**: AI creates concise summary of older messages
4. **Prunes History**: Removes older messages while keeping summary
5. **Maintains Context**: Recent messages + summary for continuity

### Token Optimization
- **Input Token Estimation**: Calculates tokens before sending to AI
- **Output Token Tracking**: Monitors response token usage
- **Total Token Management**: Maintains running totals per conversation
- **Cost Optimization**: Reduces API costs through efficient context management

## 🔍 Logging and Monitoring

The module provides comprehensive logging:

- **Conversation Events**: Message sending and receiving
- **Summary Operations**: When summaries are created/updated
- **Token Usage**: Detailed token consumption tracking
- **Error Conditions**: API errors and recovery attempts
- **Performance Metrics**: Response times and optimization events

Access logs: **Reports > Recent log messages > ai_conversation**

## 🛠️ Troubleshooting

### Common Issues

**AI Not Responding**
- Check AWS Bedrock permissions and connectivity
- Verify Claude model access
- Review error logs for API issues
- Test with simple messages first

**Token Limit Exceeded**
- Adjust max tokens setting
- Check summary frequency configuration
- Review conversation length and complexity

**Summary Not Working**
- Verify token threshold settings
- Check summary frequency configuration
- Review conversation message count

**Model Errors**
- Confirm model ID is correct
- Check for model availability in region
- Verify AWS service status

### Debug Steps
1. Enable debug mode in module configuration
2. Check recent log messages for detailed information
3. Test AWS connectivity with simple requests
4. Verify conversation node configuration
5. Monitor token usage in conversation details

## 🔄 Customization

### Prompt Engineering
Modify the system prompts in `AIApiService.php` to:
- Adjust AI personality and behavior
- Add specialized knowledge domains
- Customize response formatting
- Implement role-based responses

### Model Configuration
- Change AI model by updating configuration
- Support for multiple models per conversation
- Fallback model configuration for reliability

### Summary Behavior
Customize summarization by modifying:
- Summary prompt templates
- Trigger thresholds
- Context window size
- Message retention policies

## 🚀 Future Enhancements

Potential improvements:
- Multi-model conversations
- Conversation templates
- Export/import functionality
- API integration
- Mobile-optimized interface
- Voice interaction support

## 📞 Support

For issues or questions:
1. Enable debug mode for detailed error information
2. Check AWS Bedrock service status and permissions
3. Review conversation configuration settings
4. Test with minimal conversation complexity
5. Monitor token usage and adjust thresholds accordingly
2. Start chatting - the system will automatically handle summarization
3. Monitor the conversation statistics panel
4. Watch as older messages get summarized after reaching thresholds

## 🚀 How It Works

### Context Building Process
1. **System prompt** (from node context field)
2. **Conversation summary** (if exists)
3. **Recent messages** (last N messages)
4. **Current user message**

### Summary Generation Logic
```
IF (message_count > max_recent_messages) {
  IF (message_count % summary_frequency == 0) OR (tokens > max_tokens_before_summary) {
    GENERATE_SUMMARY()
    PRUNE_OLD_MESSAGES()
  }
}
```

### Summary Content
- **Existing summary** (if updating)
- **Key topics and decisions** from older messages
- **Important context** for conversation continuity
- **Concise but comprehensive** overview

## 📊 User Experience

### Chat Interface Features
- **Statistics panel** showing:
  - Total messages vs. recent messages
  - Summary status (Yes/No)
  - Estimated token usage
  - Last summary update time

- **Visual indicators**:
  - Summary indicator when conversation is summarized
  - Loading spinner during AI responses
  - Error handling for failed requests

- **Manual controls**:
  - Trigger summary update button (appears after 20+ messages)
  - Clear input button
  - Enter to send, Shift+Enter for new line

### Performance Monitoring
- **Real-time statistics** update after each message
- **Token estimation** to predict API costs
- **Summary effectiveness** tracking

## 🛠️ API Endpoints

### New Endpoints
- **`/ai-conversation/stats`** - Get conversation statistics
- **`/node/{node}/trigger-summary`** - Manually trigger summary update
- **`/admin/config/ai-conversation`** - Configuration form

### Enhanced Endpoints
- **`/ai-conversation/send-message`** - Now returns updated statistics
- **`/node/{node}/chat`** - Includes statistics in interface

## 🔍 Debugging & Monitoring

### Debug Mode Features
- **Detailed logging** of summary generation
- **Token usage tracking**
- **Message pruning logs**
- **API call monitoring**

### Statistics Available
```php
$stats = [
  'total_messages' => 45,
  'recent_messages' => 10,
  'has_summary' => true,
  'estimated_tokens' => 2847,
  'summary_updated' => 1704067200
];
```

## 📈 Performance Benefits

### Before Implementation
- **Growing context** sent to API with every message
- **Token usage** increases linearly with conversation length
- **API costs** escalate with longer conversations
- **Response time** degrades with large contexts

### After Implementation
- **Constant context size** (summary + recent messages)
- **Predictable token usage** regardless of conversation length
- **Optimized API costs** through smart context management
- **Consistent response times** with bounded context

## 🎛️ Configuration Options

### Essential Settings
| Setting | Default | Description |
|---------|---------|-------------|
| `max_recent_messages` | 10 | Recent messages to keep in full |
| `max_tokens_before_summary` | 6000 | Token threshold for summary trigger |
| `summary_frequency` | 20 | Messages between summary updates |
| `enable_auto_summary` | TRUE | Enable automatic summarization |

### Advanced Settings
| Setting | Default | Description |
|---------|---------|-------------|
| `max_tokens` | 4000 | Max tokens for AI responses |
| `debug_mode` | FALSE | Enable detailed logging |
| `show_stats` | TRUE | Show statistics in chat interface |

## 🔄 Deployment with GitHub Actions

Your existing deployment pipeline will automatically handle:
- Database updates (`drush updatedb`)
- Cache clearing (`drush cache:rebuild`)
- Code deployment (via Git or rsync)

## 🧪 Testing Strategy

### Manual Testing
1. **Create long conversation** (30+ messages)
2. **Verify summary generation** at configured intervals
3. **Check context optimization** in API calls
4. **Monitor token usage** through statistics
5. **Test manual summary trigger**

### Automated Testing
```bash
# Test API connection
drush ai-conversation:test

# Check configuration
drush config:get ai_conversation.settings

# Verify database schema
drush sql:query "DESCRIBE node__field_conversation_summary"
```

## 🚨 Troubleshooting

### Common Issues

**Summary not generating**
- Check `enable_auto_summary` setting
- Verify message count threshold
- Review debug logs

**High token usage**
- Reduce `max_recent_messages`
- Lower `max_tokens_before_summary`
- Check summary quality

**API errors**
- Verify AWS credentials
- Check Bedrock model availability
- Review error logs

### Debug Commands
```bash
# Check module status
drush pm:list | grep ai_conversation

# View recent logs
drush watchdog:show --type=ai_conversation

# Test configuration
drush config:get ai_conversation.settings
```

## 🎯 Next Steps

### Immediate Actions
1. **Deploy the updated module** using your GitHub Actions pipeline
2. **Configure settings** through the admin interface
3. **Test with existing conversations** to verify functionality
4. **Monitor performance** through the statistics panel

### Future Enhancements
- **Advanced summarization** with topic extraction
- **Conversation branching** for different discussion threads
- **Export/import** of conversation summaries
- **Integration with other AI models** for specialized summarization

## 📝 File Structure

```
ai_conversation/
├── ai_conversation.install          # Database schema + updates
├── ai_conversation.module           # Hooks and theme definitions
├── ai_conversation.routing.yml      # Route definitions
├── ai_conversation.services.yml     # Service definitions
├── ai_conversation.libraries.yml    # Asset libraries
├── src/
│   ├── Service/
│   │   └── AIApiService.php        # Enhanced AI service
│   ├── Controller/
│   │   └── ChatController.php      # Enhanced chat controller
│   └── Form/
│       └── SettingsForm.php        # Configuration form
├── templates/
│   └── ai-conversation-chat.html.twig  # Chat interface template
├── css/
│   └── chat-interface.css          # Styles
└── js/
    └── chat-interface.js           # JavaScript functionality
```

## 🎉 Success Metrics

Your rolling summary system is working correctly when:
- ✅ **Conversation statistics** update in real-time
- ✅ **Summary indicator** appears after threshold reached
- ✅ **Token usage** remains stable regardless of conversation length
- ✅ **API response times** stay consistent
- ✅ **Context quality** maintained through summarization

**Ready to revolutionize your AI conversations with intelligent context management!** 🚀
