# AI Response Troubleshooting Guide

## Overview

This document describes the comprehensive troubleshooting system for GenAI operations in the Dungeoncrawler application. All AI API calls are now tracked, logged, and queryable for debugging.

## Architecture

All GenAI operations flow through **AIApiService** (`ai_conversation.api_service`), which:
1. Invokes AWS Bedrock Claude 3.5 Sonnet
2. Tracks every call to `ai_conversation_api_usage` table
3. Logs both successes and failures with full context
4. Captures error messages, **complete prompts**, and **complete responses** (not truncated)

## Database Tracking

Every AI API call is recorded in `ai_conversation_api_usage` with:

### Core Fields
- **id**: Unique call identifier
- **timestamp**: When the call was made
- **uid**: User who triggered the call
- **module**: Source module (e.g., `job_hunter`, `ai_conversation`)
- **operation**: Type of operation (e.g., `resume_tailoring`, `job_posting_parsing`)
- **model_id**: AWS Bedrock model used

### Performance Metrics
- **input_tokens**: Estimated input tokens
- **output_tokens**: Estimated output tokens
- **duration_ms**: Call duration in milliseconds
- **estimated_cost**: Calculated cost based on Claude 3.5 Sonnet pricing
- **stop_reason**: Why AI stopped (e.g., `end_turn`, `max_tokens`, `error`)

### Debugging Fields (New in update_8006)
- **success**: 1 = success, 0 = failure
- **error_message**: Error message if call failed
- **prompt_preview**: **Full prompt** sent to AI (stored as MEDIUMTEXT, up to 16MB)
- **response_preview**: **Full response** from AI (stored as MEDIUMTEXT, up to 16MB)

### Context Data
- **context_data**: JSON field with operation-specific data:
  - Resume tailoring: `{uid, job_id, queue}`
  - Resume parsing: `{uid, filename, chunk}`
  - Job posting parsing: `{job_id, chunk}`
  - Chat messages: `{conversation_id, conversation_title}`

## Drush Commands

Three new commands make troubleshooting straightforward:

### 1. List Recent Failures

```bash
# Show failures from last 24 hours
drush ai:failures

# Show failures from last 4 hours for job_hunter module
drush ai:failures --hours=4 --module=job_hunter

# Show resume tailoring failures with full details
drush ai:failures --operation=resume_tailoring --verbose

# Show last 50 failures
drush ai:failures --limit=50
```

**Output example:**
```
Found 3 AI failures:

[2024-03-15 14:23:45] job_hunter/resume_tailoring
  Error: JSON parsing failed: unexpected token at line 42

[2024-03-15 13:15:22] job_hunter/job_posting_parsing
  Error: AWS Bedrock throttling: Too many requests
```

### 2. Show Usage Statistics

```bash
# Show stats from last 24 hours
drush ai:stats

# Show job_hunter stats from last week
drush ai:stats --hours=168 --module=job_hunter

# Show specific operation stats
drush ai:stats --operation=resume_tailoring
```

**Output example:**
```
AI API Usage Statistics

Total Calls: 247
  Success: 239 (96.8%)
  Failed: 8 (3.2%)

Token Usage:
  Input: 1,245,678
  Output: 456,789
  Total: 1,702,467

Cost: $12.3456
Avg Duration: 2847ms

By Operation:
  resume_tailoring: 145 calls, 4 failures (2.8%), $7.2345
  job_posting_parsing: 78 calls, 2 failures (2.6%), $3.4567
  chat_message: 24 calls, 2 failures (8.3%), $1.6544
```

### 3. Inspect Specific Call

```bash
# Inspect API call #42
drush ai:inspect 42
```

**Output example:**
```
AI API Call #42

Status: FAILED
Timestamp: 2024-03-15 14:23:45
User ID: 5
Module: job_hunter
Operation: resume_tailoring

Performance:
  Duration: 3245ms
  Stop Reason: error

Token Usage:
  Input: 8,456
  Output: 0
  Cost: $0.0254

Context Data:
{
  "uid": 5,
  "job_id": 123,
  "queue": "job_hunter_resume_tailoring"
}

Error Message:
JSON parsing failed: unexpected token at line 42

Full Prompt (8456 chars):
You are an expert resume writer...
[complete prompt shown]

Full Response (0 chars):
[no response due to error]
```

## Common Troubleshooting Scenarios

### 1. Queue Failures

**Problem**: Resume tailoring queue items repeatedly fail

**Diagnosis**:
```bash
drush ai:failures --operation=resume_tailoring --verbose
```

**Look for**:
- `max_tokens` stop_reason → Prompt too large or response truncated
- JSON parsing errors → AI returned invalid JSON (check prompt instructions)
- Throttling errors → Too many concurrent requests
- Timeout errors → Slow API response (check duration_ms)

### 2. JSON Parsing Errors

**Problem**: "Failed to parse AI response as JSON"

**Diagnosis**:
```bash
# Find the failed call ID from queue logs
drush ai:inspect [ID]
```

**Check**:
- `response_preview`: Full AI response - does it contain valid JSON?
- Look for literal newlines instead of `\n`
- Look for unescaped quotes
- Verify prompt instructions require "RFC 8259 compliant JSON"
- Use the **complete prompt and response** to reproduce the issue locally

### 3. Token Limit Issues

**Problem**: Responses get cut off

**Diagnosis**:
```bash
drush ai:stats --operation=resume_tailoring
```

**Check**:
- High output token counts near 8000 limit
- `stop_reason` = `max_tokens`
- Review `prompt_preview` for excessive context

### 4. Cost Monitoring

**Problem**: Unexpected API costs

**Diagnosis**:
```bash
# Check costs by operation
drush ai:stats --hours=168

# Find expensive calls
SELECT * FROM ai_conversation_api_usage 
WHERE estimated_cost > 0.10 
ORDER BY estimated_cost DESC 
LIMIT 20;
```

### 5. Performance Issues

**Problem**: Slow AI responses

**Diagnosis**:
```bash
# Check average duration
drush ai:stats

# Find slow calls
SELECT id, operation, duration_ms, input_tokens, stop_reason
FROM ai_conversation_api_usage 
WHERE duration_ms > 5000 
ORDER BY duration_ms DESC 
LIMIT 20;
```

## Direct SQL Queries

### Recent Failures by Module
```sql
SELECT timestamp, module, operation, error_message, context_data
FROM ai_conversation_api_usage
WHERE success = 0 
  AND timestamp > UNIX_TIMESTAMP() - 86400
ORDER BY timestamp DESC;
```

### Failure Rate by Operation
```sql
SELECT 
  operation,
  COUNT(*) as total_calls,
  SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failures,
  ROUND(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as failure_pct
FROM ai_conversation_api_usage
WHERE timestamp > UNIX_TIMESTAMP() - 86400
GROUP BY operation
ORDER BY failure_pct DESC;
```

### High Token Usage Calls
```sql
SELECT id, operation, input_tokens, output_tokens, 
       (input_tokens + output_tokens) as total_tokens,
       stop_reason
FROM ai_conversation_api_usage
WHERE timestamp > UNIX_TIMESTAMP() - 86400
  AND (input_tokens + output_tokens) > 10000
ORDER BY total_tokens DESC;
```

### Cost by User
```sql
SELECT 
  uid,
  COUNT(*) as calls,
  SUM(estimated_cost) as total_cost,
  AVG(estimated_cost) as avg_cost
FROM ai_conversation_api_usage
WHERE timestamp > UNIX_TIMESTAMP() - 604800  -- Last 7 days
GROUP BY uid
ORDER BY total_cost DESC;
```

## Watchdog Integration

All AI operations also log to Drupal's watchdog:

### Success Logs
```
📊 API usage tracked: job_hunter/resume_tailoring - 4567 in + 1234 out = $0.0234
```

### Failure Logs
```
❌ API call failed and tracked: job_hunter/resume_tailoring - JSON parsing error
```

### Query Watchdog
```bash
drush watchdog:show --severity=Error --filter=ai_conversation

drush watchdog:show --severity=Error --filter=job_hunter
```

## Updating the Database Schema

To add the new debugging fields to an existing installation:

```bash
# Run the update hook
drush updatedb

# Verify fields were added
drush sqlq "DESCRIBE ai_conversation_api_usage"

# Should see: success, error_message, prompt_preview, response_preview
```

## Best Practices

1. **Monitor failure rates**: Run `drush ai:stats` daily
2. **Check recent failures**: Run `drush ai:failures` when queue items fail
3. **Inspect specific issues**: Use `drush ai:inspect [ID]` to see **complete prompt and response**
4. **Query by operation**: Filter stats/failures by specific operations
5. **Review prompts**: Check full `prompt_preview` field to ensure prompts are well-formed
6. **Watch stop_reason**: Track max_tokens issues, errors, timeouts
7. **Monitor costs**: Review `estimated_cost` trends weekly
8. **Save full context**: Complete prompts/responses stored for reproduction and debugging

## Module Integration

All job_hunter queue workers now use AIApiService:

### Workers Updated
- `ResumeTailoringWorker`: Tracks uid, job_id, queue
- `CoverLetterTailoringWorker`: Tracks uid, job_id, queue  
- `ResumeGenAiParsingWorker`: Tracks uid, filename, chunk
- `JobPostingParsingWorker`: Tracks job_id, chunk

### Automatic Tracking
Each worker call to `$this->aiApiService->invokeModelDirect()` automatically:
1. Invokes AWS Bedrock
2. Measures duration
3. Captures success/failure status
4. Logs error messages if failed
5. Stores prompt and response previews
6. Writes to tracking table
7. Logs to watchdog

## Related Files

### Core Services
- `AIApiService.php`: Main GenAI service with tracking
- `PromptManager.php`: System prompt management

### Database
- `ai_conversation.install`: Schema definition + update_8006

### Commands
- `AiDebugCommands.php`: Drush troubleshooting commands

### Queue Workers
- `ResumeTailoringWorker.php`
- `CoverLetterTailoringWorker.php`
- `ResumeGenAiParsingWorker.php`
- `JobPostingParsingWorker.php`

## Support

For issues or questions:
1. Run `drush ai:failures --verbose` to see recent errors
2. Use `drush ai:inspect [ID]` for specific call details
3. Check watchdog: `drush watchdog:show --severity=Error`
4. Review prompt previews for formatting issues
5. Monitor token usage and costs with `drush ai:stats`
