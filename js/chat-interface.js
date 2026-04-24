(function($, Drupal) {
  'use strict';

  Drupal.behaviors.aiConversationChat = {
    attach: function(context, settings) {
      console.log('🔧 AI Conversation Chat - Behavior attaching');
      console.log('📍 Context:', context);
      console.log('📍 Settings:', settings);
      
      const $chatContainer = $('.ai-conversation-chat', context);
      console.log('📦 Chat container found:', $chatContainer.length > 0);
      
      if ($chatContainer.length === 0) {
        console.warn('❌ No chat container found, exiting behavior');
        return;
      }

      const chatSettings = settings.aiConversation || {};
      console.log('⚙️ AI Conversation settings loaded:', chatSettings);
      
      // Log all the required elements
      const $messageInput = $('#message-input', $chatContainer);
      const $sendButton = $('#send-button', $chatContainer);
      const $clearButton = $('#clear-input', $chatContainer);
      const $triggerSummaryButton = $('#trigger-summary', $chatContainer);
      const $toggleMetricsButton = $('#toggle-metrics', $chatContainer);
      const $metricsContainer = $('.ai-conversation-metrics', $chatContainer);
      const $messagesContainer = $('#chat-messages', $chatContainer);
      const $loadingIndicator = $('#loading-indicator', $chatContainer);

      console.log('📋 UI Elements check:');
      console.log('  💬 Message input:', $messageInput.length > 0);
      console.log('  📤 Send button:', $sendButton.length > 0);
      console.log('  🧹 Clear button:', $clearButton.length > 0);
      console.log('  📊 Metrics container:', $metricsContainer.length > 0);
      console.log('  💭 Messages container:', $messagesContainer.length > 0);
      console.log('  ⏳ Loading indicator:', $loadingIndicator.length > 0);

      // Validate critical settings
      if (!chatSettings.sendMessageUrl) {
        console.error('❌ CRITICAL: sendMessageUrl not configured');
      } else {
        console.log('✅ Send message URL configured:', chatSettings.sendMessageUrl);
      }
      
      if (!chatSettings.csrfToken) {
        console.error('❌ CRITICAL: CSRF token not configured');
      } else {
        console.log('✅ CSRF token configured');
      }

      // Metrics toggle functionality
      let metricsVisible = true;
      $toggleMetricsButton.on('click', function() {
        metricsVisible = !metricsVisible;
        
        if (metricsVisible) {
          $metricsContainer.removeClass('collapsed').addClass('expanded');
          $toggleMetricsButton.text(Drupal.t('Hide Metrics'));
        } else {
          $metricsContainer.removeClass('expanded').addClass('collapsed');
          $toggleMetricsButton.text(Drupal.t('Show Metrics'));
        }
      });

      // Auto-update metrics periodically
      let metricsUpdateInterval;
      function startMetricsUpdates() {
        metricsUpdateInterval = setInterval(function() {
          updateMetrics();
        }, 30000); // Update every 30 seconds
      }

      function stopMetricsUpdates() {
        if (metricsUpdateInterval) {
          clearInterval(metricsUpdateInterval);
        }
      }

      // Update metrics from server
      function updateMetrics() {
        $.ajax({
          url: chatSettings.statsUrl,
          type: 'GET',
          data: {
            node_id: chatSettings.nodeId
          },
          success: function(response) {
            if (response.stats) {
              updateMetricsDisplay(response.stats);
            }
          },
          error: function(xhr, status, error) {
            console.log('Failed to update metrics:', error);
          }
        });
      }

      // Update metrics display with animation
      function updateMetricsDisplay(stats) {
        // Update each metric with animation
        $('.metric-value').each(function() {
          const $this = $(this);
          const $item = $this.closest('.metric-item');
          const label = $item.find('.metric-label').text().toLowerCase();
          
          let newValue = '';
          
          // Map labels to stats values
          if (label.includes('total messages')) {
            newValue = stats.total_messages || 0;
          } else if (label.includes('recent messages')) {
            newValue = stats.recent_messages || 0;
          } else if (label.includes('user messages')) {
            newValue = stats.user_message_count || 0;
          } else if (label.includes('ai messages')) {
            newValue = stats.ai_message_count || 0;
          } else if (label.includes('total tokens')) {
            newValue = Number(stats.total_tokens_used || 0).toLocaleString();
          } else if (label.includes('current context')) {
            newValue = Number(stats.estimated_tokens || 0).toLocaleString();
          } else if (label.includes('tokens saved')) {
            newValue = Number(stats.tokens_saved_by_summary || 0).toLocaleString();
          } else if (label.includes('estimated cost')) {
            newValue = Number(stats.estimated_cost || 0).toFixed(4);
          } else if (label.includes('cost saved')) {
            newValue = Number(stats.cost_saved_by_summary || 0).toFixed(4);
          } else if (label.includes('health score')) {
            newValue = Number(stats.conversation_health || 100).toFixed(1) + '/100';
          }
          
          // Update if value changed
          if (newValue && $this.text() !== newValue) {
            $this.addClass('updated').text(newValue);
            setTimeout(function() {
              $this.removeClass('updated');
            }, 1000);
          }
        });

        // Update health score bar
        const healthScore = stats.conversation_health || 100;
        $('.health-score-fill').css('width', healthScore + '%');
        $('.health-score-value').text(healthScore.toFixed(1) + '/100');

        // Update success rate
        const successRate = stats.api_calls_total > 0 ? 
          ((stats.api_calls_total - (stats.api_calls_failed || 0)) / stats.api_calls_total * 100) : 100;
        $('.metric-item').each(function() {
          const $this = $(this);
          if ($this.find('.metric-label').text().toLowerCase().includes('success rate')) {
            const $value = $this.find('.metric-value');
            $value.text(successRate.toFixed(1) + '%');
            
            // Update status class
            $value.removeClass('status-good status-warning status-error');
            if (successRate >= 95) {
              $value.addClass('status-good');
            } else if (successRate >= 80) {
              $value.addClass('status-warning');
            } else {
              $value.addClass('status-error');
            }
          }
        });
      }

      // Send message handler
      function sendMessage() {
        const message = $messageInput.val().trim();
        
        console.log('🚀 AI Conversation - Starting sendMessage');
        console.log('📝 Message content:', message);
        console.log('⚙️ Chat settings:', chatSettings);
        
        if (!message) {
          console.warn('❌ Empty message, aborting send');
          return;
        }

        // Show loading indicator
        $loadingIndicator.show();
        $sendButton.prop('disabled', true);

        // Add user message to chat immediately
        addMessageToChat('user', message);

        // Clear input
        $messageInput.val('');

        console.log('📡 Sending AJAX request to:', chatSettings.sendMessageUrl);
        console.log('📦 Request data:', {
          node_id: chatSettings.nodeId,
          message: message,
          csrf_token: chatSettings.csrfToken ? 'present' : 'MISSING'
        });

        // Send to server
        $.ajax({
          url: chatSettings.sendMessageUrl,
          type: 'POST',
          data: {
            node_id: chatSettings.nodeId,
            message: message,
            csrf_token: chatSettings.csrfToken
          },
          beforeSend: function(xhr, settings) {
            console.log('🔄 AJAX beforeSend - Request starting');
            console.log('🌐 XHR settings:', {
              url: settings.url,
              type: settings.type,
              data: settings.data
            });
          },
          success: function(response) {
            console.log('✅ AJAX Success - Server response received');
            console.log('📥 Response data:', response);
            
            if (response.success) {
              console.log('🎉 Response indicates success');
              // Add AI response to chat
              addMessageToChat('assistant', response.response);
              
              // Update statistics if provided
              if (response.stats) {
                console.log('📊 Updating metrics with stats:', response.stats);
                updateMetricsDisplay(response.stats);
                chatSettings.stats = response.stats;
              }
              
              // Show/hide summary button based on message count
              if (response.stats && response.stats.total_messages > 20) {
                console.log('🔘 Showing summary button (>20 messages)');
                $triggerSummaryButton.show();
              }
            } else {
              console.error('❌ Server returned error in success response:', response.error);
              showError(response.error || 'Unknown error occurred');
            }
          },
          error: function(xhr, status, error) {
            console.error('❌ AJAX Error occurred');
            console.error('📊 XHR status:', xhr.status);
            console.error('📊 XHR readyState:', xhr.readyState);
            console.error('📊 Status text:', status);
            console.error('📊 Error message:', error);
            console.error('📊 Response text:', xhr.responseText);
            
            let errorMessage = 'Failed to send message';
            if (xhr.responseJSON && xhr.responseJSON.error) {
              console.error('📊 Parsed JSON error:', xhr.responseJSON.error);
              errorMessage = xhr.responseJSON.error;
            } else if (xhr.responseText) {
              try {
                const parsed = JSON.parse(xhr.responseText);
                console.error('📊 Manual JSON parse:', parsed);
                if (parsed.error) {
                  errorMessage = parsed.error;
                }
              } catch (parseError) {
                console.error('📊 JSON parse failed:', parseError);
                console.error('📊 Raw response:', xhr.responseText);
              }
            }
            
            console.error('📊 Final error message shown to user:', errorMessage);
            showError(errorMessage);
          },
          complete: function() {
            console.log('🏁 AJAX Complete - Request finished');
            $loadingIndicator.hide();
            $sendButton.prop('disabled', false);
            $messageInput.focus();
          }
        });
      }

      // Add message to chat UI
      function addMessageToChat(role, content) {
        const timestamp = new Date().toLocaleString();
        const $message = $('<div class="message message--' + role + '">');
        const $content = $('<div class="message-content">').html(content.replace(/\n/g, '<br>'));
        const $timestamp = $('<div class="message-timestamp">').text(timestamp);
        
        $message.append($content).append($timestamp);
        $messagesContainer.append($message);
        
        // Scroll to bottom with animation
        $messagesContainer.animate({
          scrollTop: $messagesContainer[0].scrollHeight
        }, 300);
      }

      // Show error message
      function showError(message) {
        const $error = $('<div class="message message--error">');
        const $content = $('<div class="message-content">').html('<strong>Error:</strong> ' + message);
        
        $error.append($content);
        $messagesContainer.append($error);
        $messagesContainer.animate({
          scrollTop: $messagesContainer[0].scrollHeight
        }, 300);
      }

      // Trigger summary update
      function triggerSummaryUpdate() {
        if (!confirm(Drupal.t('Are you sure you want to update the conversation summary? This will condense older messages.'))) {
          return;
        }

        $triggerSummaryButton.prop('disabled', true).text(Drupal.t('Updating...'));
        
        $.ajax({
          url: '/node/' + chatSettings.nodeId + '/trigger-summary',
          type: 'GET',
          success: function(response) {
            if (response.success) {
              // Show success message
              const $successMsg = $('<div class="alert alert-success">').text(Drupal.t('Summary updated successfully!'));
              $messagesContainer.before($successMsg);
              
              // Update metrics
              if (response.stats) {
                updateMetricsDisplay(response.stats);
              }
              
              // Remove success message after 3 seconds
              setTimeout(function() {
                $successMsg.fadeOut(300, function() {
                  $(this).remove();
                });
              }, 3000);
              
              // Add summary indicator if not present
              if ($('.summary-indicator').length === 0) {
                $messagesContainer.prepend(
                  '<div class="summary-indicator"><em>' + 
                  Drupal.t('This conversation has been summarized. Showing recent messages.') +
                  '</em></div>'
                );
              }
            } else {
              alert(Drupal.t('Error updating summary: ') + (response.error || 'Unknown error'));
            }
          },
          error: function(xhr, status, error) {
            alert(Drupal.t('Failed to update summary: ') + error);
          },
          complete: function() {
            $triggerSummaryButton.prop('disabled', false).text(Drupal.t('Update Summary'));
          }
        });
      }

      // Event handlers
      $sendButton.on('click', sendMessage);
      $clearButton.on('click', function() {
        $messageInput.val('');
        $messageInput.focus();
      });
      $triggerSummaryButton.on('click', triggerSummaryUpdate);

      // Enter key to send (with Shift+Enter for new line)
      $messageInput.on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
        }
      });

      // Auto-resize textarea
      $messageInput.on('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
      });

      // Focus on message input when page loads
      $messageInput.focus();

      // Auto-scroll to bottom of messages
      $messagesContainer.scrollTop($messagesContainer[0].scrollHeight);

      // Start metrics updates
      startMetricsUpdates();

      // Stop metrics updates when leaving page
      $(window).on('beforeunload', function() {
        stopMetricsUpdates();
      });

      // Keyboard shortcuts
      $(document).on('keydown', function(e) {
        // Ctrl/Cmd + M to toggle metrics
        if ((e.ctrlKey || e.metaKey) && e.key === 'm') {
          e.preventDefault();
          $toggleMetricsButton.click();
        }
        
        // Ctrl/Cmd + K to clear input
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
          e.preventDefault();
          $clearButton.click();
        }
      });

      // Add keyboard shortcuts help
      const $helpText = $('<div class="keyboard-shortcuts">').html(
        '<small>' + 
        Drupal.t('Shortcuts: ') + 
        '<kbd>Ctrl+M</kbd> ' + Drupal.t('Toggle metrics') + ', ' +
        '<kbd>Ctrl+K</kbd> ' + Drupal.t('Clear input') + ', ' +
        '<kbd>Shift+Enter</kbd> ' + Drupal.t('New line') +
        '</small>'
      );
      $('.chat-controls').append($helpText);
    }
  };

  /**
   * Injects a search filter above the term selector and filters options in real time.
   */
  function addTermSelectorSearch(selector) {
    // Create the search input
    const filterInput = document.createElement('input');
    filterInput.type = 'text';
    filterInput.className = 'term-selector-filter form-text';
    filterInput.placeholder = 'Search terms...';
    filterInput.setAttribute('aria-label', 'Filter terms');

    // Insert the filter input above the selector
    selector.parentNode.insertBefore(filterInput, selector);

    // Filtering logic
    filterInput.addEventListener('input', function () {
      const filter = filterInput.value.trim().toLowerCase();
      Array.from(selector.options).forEach(option => {
        const text = option.textContent.toLowerCase();
        option.style.display = filter === '' || text.includes(filter) ? '' : 'none';
      });
    });
  }

  // Attach the filter when the behavior runs
  (function (Drupal, once) {
    Drupal.behaviors.newsMotivationMetricsChartFilter = {
      attach: function (context) {
        const selector = context.querySelector('.term-selector');
        if (selector && !selector.hasAttribute('data-filter-enabled')) {
          addTermSelectorSearch(selector);
          selector.setAttribute('data-filter-enabled', 'true');
        }
      }
    };
  })(Drupal, once);

})(jQuery, Drupal);