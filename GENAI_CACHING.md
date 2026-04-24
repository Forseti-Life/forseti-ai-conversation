# GenAI Response Caching System

## Overview

The GenAI response caching system prevents redundant API calls to AWS Bedrock by automatically storing and reusing successful responses. This significantly reduces costs and speeds up operations when queue items are retried or reprocessed.

## How It Works

### Automatic Caching

All calls through `AIApiService::invokeModelDirect()` automatically:

1. **Check for cached responses** before making API calls
2. **Match on context** (uid, job_id, filename, chunk, etc.)
3. **Reuse successful responses** if found
4. **Store new responses** in `ai_conversation_api_usage` table

### Cache Mapping

Queue items are mapped to cached responses using `item_key` in context_data:

| Queue | Item Key Format | Matching Fields |
|-------|----------------|-----------------|
| `job_hunter_resume_tailoring` | `resume_tailoring_{uid}_{job_id}` | uid, job_id |
| `job_hunter_cover_letter_tailoring` | `cover_letter_{uid}_{job_id}` | uid, job_id |
| `job_hunter_genai_parsing` | `resume_parsing_{uid}_{filename}_{chunk}` | uid, filename, chunk |
| `job_hunter_job_posting_parsing` | `job_posting_{job_id}_{chunk}` | job_id, chunk |

### Suspended Items

When GenAI succeeds but downstream processing fails (e.g., JSON parsing error), the queue worker throws `SuspendQueueException` instead of `Exception`. This:

- **Prevents infinite retries** consuming API calls
- **Preserves the GenAI response** in the database
- **Allows manual review** before retry
- **Lets admins clear cache** if needed

## Queue Management Interface

### Viewing Cached Status

The queue management page at `/jobhunter/queue-management` shows:
- All pending queue items
- "Clear Cache" button for GenAI-based queues
- Item metadata for identifying which cache entries apply

### Clearing Cache

**When to clear cache:**
- Prompt instructions were updated
- Input data changed (resume, job posting)
- Previous GenAI response was wrong/malformed
- Want to force fresh API call for testing

**How to clear:**
1. Navigate to `/jobhunter/queue-management`
2. Find the suspended/queued item
3. Click **"🗑️ Clear Cache"** button
4. Confirm the action
5. Retry the queue item

**Effect:**
- Deletes cached response from `ai_conversation_api_usage`
- Next processing will make fresh AWS Bedrock call
- New response will be cached

## API Endpoints

### Clear GenAI Cache
```
POST /jobhunter/queue/clear-genai-cache
```

**Request:**
```json
{
  "queue_name": "job_hunter_resume_tailoring",
  "item_data": {
    "uid": 5,
    "job_id": 123
  }
}
```

**Response:**
```json
{
  "success": true,
  "cleared": 2,
  "message": "Cleared 2 cached GenAI response(s). Next run will call AI again."
}
```

## Programmatic Usage

### Clear Cache from Code

```php
$ai_service = \Drupal::service('ai_conversation.ai_api_service');

// Clear resume tailoring cache
$cleared = $ai_service->clearCachedResponse(
  'job_hunter',
  'resume_tailoring',
  ['uid' => 5, 'job_id' => 123]
);

// Clear resume parsing cache for specific chunk
$cleared = $ai_service->clearCachedResponse(
  'job_hunter',
  'resume_parsing',
  ['uid' => 5, 'filename' => 'resume.pdf', 'chunk' => 'core_profile']
);
```

### Skip Cache for One Call

```php
$result = $this->aiApiService->invokeModelDirect(
  $prompt,
  'job_hunter',
  'resume_tailoring',
  ['uid' => 5, 'job_id' => 123],
  ['skip_cache' => TRUE]  // Force fresh API call
);
```

### Check if Response Was Cached

```php
$result = $this->aiApiService->invokeModelDirect($prompt, ...);

if ($result['cached']) {
  \Drupal::logger('job_hunter')->info('Used cached response');
} else {
  \Drupal::logger('job_hunter')->info('Made fresh API call');
}
```

## Database Schema

### Cache Storage

Cached responses are stored in `ai_conversation_api_usage`:

```sql
SELECT 
  id,
  timestamp,
  module,
  operation,
  success,
  response_preview,  -- Full GenAI response
  prompt_preview,    -- Full prompt sent
  input_tokens,
  output_tokens,
  stop_reason,
  estimated_cost,
  context_data       -- JSON with uid, job_id, etc.
FROM ai_conversation_api_usage
WHERE module = 'job_hunter'
  AND operation = 'resume_tailoring'
  AND success = 1
  AND JSON_EXTRACT(context_data, '$.uid') = 5
  AND JSON_EXTRACT(context_data, '$.job_id') = 123
ORDER BY timestamp DESC
LIMIT 1;
```

### Query Cached Responses

```sql
-- Find cached responses for a user
SELECT 
  operation,
  JSON_EXTRACT(context_data, '$.job_id') as job_id,
  COUNT(*) as cached_responses,
  SUM(estimated_cost) as saved_cost
FROM ai_conversation_api_usage
WHERE module = 'job_hunter'
  AND success = 1
  AND JSON_EXTRACT(context_data, '$.uid') = 5
GROUP BY operation, job_id;
```

### Delete Specific Cache

```sql
-- Clear cache for resume tailoring
DELETE FROM ai_conversation_api_usage
WHERE module = 'job_hunter'
  AND operation = 'resume_tailoring'
  AND JSON_EXTRACT(context_data, '$.uid') = 5
  AND JSON_EXTRACT(context_data, '$.job_id') = 123;
```

## Cache Invalidation Strategy

### Automatic Invalidation

Cache is **automatically reused** for:
- ✅ Retry after transient errors (network, timeout)
- ✅ Retry after JSON parsing errors (GenAI succeeded)
- ✅ Re-queueing suspended items (same input)

### Manual Invalidation Required

Clear cache when:
- ❌ Prompt instructions changed
- ❌ Input data updated (different resume version)
- ❌ GenAI response was incorrect
- ❌ Testing with production data

## Troubleshooting

### Cache Not Being Used

If fresh API calls are made when you expect caching:

1. **Check context_data** - Must match exactly
   ```bash
   drush ai:inspect [ID]
   ```

2. **Verify item_key** - Check queue worker sets unique key
   ```php
   'item_key' => "resume_tailoring_{$uid}_{$job_id}"
   ```

3. **Check success flag** - Only success=1 responses are cached
   ```sql
   SELECT success FROM ai_conversation_api_usage WHERE id = ?;
   ```

### Cache Stale After Prompt Change

After updating prompt instructions:

1. Use Drush to clear all cache for operation:
   ```bash
   drush sqlq "DELETE FROM ai_conversation_api_usage 
               WHERE operation = 'resume_tailoring' 
               AND timestamp < UNIX_TIMESTAMP()"
   ```

2. Or clear via UI for specific items

### Queue Still Suspended After Cache Clear

1. Check logs to see current error
2. Cache clearing doesn't auto-retry
3. Must manually retry from `/jobhunter/queue-management`
4. If still failing, check `ai:inspect` for actual error

## Cost Savings Example

**Without caching:**
- Queue item fails due to JSON parsing
- Retries 3 times = 3 API calls
- Cost: $0.03 × 3 = $0.09

**With caching:**
- First call succeeds, response cached
- JSON parsing fails (downstream)
- Queue suspended (not retried)
- Admin fixes issue, retries
- Uses cached response (free)
- Cost: $0.03 × 1 = $0.03 (67% savings)

**Typical savings:**
- Resume tailoring retries: ~70% cost reduction
- Job posting parsing retries: ~60% cost reduction
- Resume parsing retries: ~50% cost reduction (multi-chunk)

## Related Documentation

- [AI_TROUBLESHOOTING.md](AI_TROUBLESHOOTING.md) - Debugging GenAI failures
- [Queue Management](../../job_hunter/README.md#queue-management) - Queue system overview
- [AIApiService.php](src/Service/AIApiService.php) - Implementation details
