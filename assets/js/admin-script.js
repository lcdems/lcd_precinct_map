/**
 * LCD County Map Admin Scripts
 */
jQuery(document).ready(function($) {
    'use strict';

    // PCO Management functionality
    var PCOManager = {
        
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Edit button click
            $(document).on('click', '.pco-edit-btn', this.handleEditClick);
            
            // Cancel button click
            $(document).on('click', '.pco-cancel-btn', this.handleCancelClick);
            
            // Delete button click
            $(document).on('click', '.pco-delete-btn', this.handleDeleteClick);
            
            // Form submission with validation
            $(document).on('submit', '.pco-edit-form form', this.handleFormSubmit);
            
            // Auto-save functionality (optional)
            $(document).on('blur', '.pco-edit-form input[type="text"], .pco-edit-form input[type="email"]', this.handleFieldBlur);
        },

        handleEditClick: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var precinct = $button.data('precinct');
            
            // Hide all other edit forms
            $('.pco-edit-form').slideUp(200);
            $('.pco-edit-btn').text(function() {
                var $this = $(this);
                var precinctNum = $this.data('precinct');
                var $item = $this.closest('.pco-item');
                var hasData = $item.find('.pco-display p:not(.no-pco)').length > 0;
                return hasData ? 'Edit' : 'Add PCO';
            });
            
            // Show this edit form
            var $form = $('#pco-form-' + precinct);
            var $display = $('#pco-display-' + precinct);
            
            $display.slideUp(200, function() {
                $form.slideDown(200, function() {
                    // Focus on first input
                    $form.find('input[type="text"]').first().focus();
                });
            });
            
            $button.text('Cancel');
        },

        handleCancelClick: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var precinct = $button.data('precinct');
            
            PCOManager.hideEditForm(precinct);
        },

        handleDeleteClick: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var precinct = $button.data('precinct');
            
            if (!confirm('Are you sure you want to clear the PCO information for this precinct?')) {
                return;
            }
            
            // Create a hidden form to submit the delete request
            var $form = $('<form>', {
                method: 'post',
                action: $button.closest('form').attr('action')
            });
            
            $form.append($('<input>', {
                type: 'hidden',
                name: 'action',
                value: 'lcd_pco_delete'
            }));
            
            $form.append($('<input>', {
                type: 'hidden',
                name: 'precinct_number',
                value: precinct
            }));
            
            // Add nonce
            var nonce = $button.closest('form').find('input[name="lcd_pco_nonce"]').val();
            $form.append($('<input>', {
                type: 'hidden',
                name: 'lcd_pco_nonce',
                value: nonce
            }));
            
            $form.appendTo('body').submit();
        },

        handleFormSubmit: function(e) {
            var $form = $(this);
            var $item = $form.closest('.pco-item');
            
            // Basic validation
            var name = $form.find('input[name="pco_name"]').val().trim();
            var email = $form.find('input[name="pco_email"]').val().trim();
            
            // Allow either name or email to be empty, but show warning if both are empty
            if (!name && !email) {
                if (!confirm('Both name and email are empty. This will clear the PCO information. Continue?')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // Email validation if provided
            if (email && !PCOManager.isValidEmail(email)) {
                alert('Please enter a valid email address.');
                $form.find('input[name="pco_email"]').focus();
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            $item.addClass('loading');
            $form.find('button').prop('disabled', true);
            
            return true;
        },

        handleFieldBlur: function() {
            var $field = $(this);
            var $form = $field.closest('form');
            
            // Real-time validation
            if ($field.attr('type') === 'email' && $field.val()) {
                if (!PCOManager.isValidEmail($field.val())) {
                    $field.addClass('invalid');
                    if (!$field.next('.error-message').length) {
                        $field.after('<span class="error-message" style="color: #d63638; font-size: 12px; display: block; margin-top: 5px;">Please enter a valid email address</span>');
                    }
                } else {
                    $field.removeClass('invalid');
                    $field.next('.error-message').remove();
                }
            }
        },

        hideEditForm: function(precinct) {
            var $form = $('#pco-form-' + precinct);
            var $display = $('#pco-display-' + precinct);
            var $button = $('.pco-edit-btn[data-precinct="' + precinct + '"]');
            
            $form.slideUp(200, function() {
                $display.slideDown(200);
            });
            
            // Reset button text
            var $item = $button.closest('.pco-item');
            var hasData = $item.find('.pco-display p:not(.no-pco)').length > 0;
            $button.text(hasData ? 'Edit' : 'Add PCO');
            
            // Clear any validation messages
            $form.find('.error-message').remove();
            $form.find('.invalid').removeClass('invalid');
        },

        isValidEmail: function(email) {
            var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },

        // Method to refresh precinct data via AJAX (for future use)
        refreshPrecincts: function() {
            $.ajax({
                url: lcdAdminData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'lcd_get_precincts',
                    nonce: lcdAdminData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        console.log('Precincts refreshed:', response.data);
                        // Could implement dynamic refresh of the precinct list here
                    }
                },
                error: function() {
                    console.error('Failed to refresh precincts');
                }
            });
        }
    };

    // Enhanced file upload with progress indication
    var FileUploadManager = {
        
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // File input change
            $(document).on('change', 'input[type="file"]', this.handleFileChange);
            
            // Form submission
            $(document).on('submit', 'form[enctype="multipart/form-data"]', this.handleFormSubmit);
        },

        handleFileChange: function() {
            var $input = $(this);
            var files = this.files;
            
            if (files.length > 0) {
                var file = files[0];
                var $feedback = $input.siblings('.file-feedback');
                
                if ($feedback.length === 0) {
                    $feedback = $('<div class="file-feedback" style="margin-top: 5px; font-size: 13px;"></div>');
                    $input.after($feedback);
                }
                
                // Validate file
                if (file.type !== 'application/zip' && !file.name.toLowerCase().endsWith('.zip')) {
                    $feedback.html('<span style="color: #d63638;">⚠ Warning: File should be a ZIP archive</span>');
                } else if (file.size > 50 * 1024 * 1024) { // 50MB limit
                    $feedback.html('<span style="color: #d63638;">⚠ Warning: File is very large (' + FileUploadManager.formatFileSize(file.size) + ')</span>');
                } else {
                    $feedback.html('<span style="color: #00a32a;">✓ Selected: ' + file.name + ' (' + FileUploadManager.formatFileSize(file.size) + ')</span>');
                }
            }
        },

        handleFormSubmit: function() {
            var $form = $(this);
            var $button = $form.find('input[type="submit"]');
            var hasFiles = false;
            
            // Check if any files are selected
            $form.find('input[type="file"]').each(function() {
                if (this.files.length > 0) {
                    hasFiles = true;
                    return false;
                }
            });
            
            if (!hasFiles) {
                alert('Please select at least one file to upload.');
                return false;
            }
            
            // Show loading state
            $button.prop('disabled', true);
            $button.val('Uploading...');
            
            // Create progress indicator
            var $progress = $('<div class="upload-progress" style="margin-top: 15px; padding: 10px; background: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px;"><div style="margin-bottom: 5px;">Uploading files...</div><div class="progress-bar" style="background: #ddd; height: 20px; border-radius: 10px; overflow: hidden;"><div class="progress-fill" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease;"></div></div></div>');
            $form.after($progress);
            
            // Simulate progress (since we can't get real upload progress easily)
            var progress = 0;
            var interval = setInterval(function() {
                progress += Math.random() * 20;
                if (progress >= 90) {
                    progress = 90;
                    clearInterval(interval);
                }
                $progress.find('.progress-fill').css('width', progress + '%');
            }, 500);
            
            return true;
        },

        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    };

    // Tab management
    var TabManager = {
        
        init: function() {
            this.bindEvents();
            this.initializeActiveTab();
        },

        bindEvents: function() {
            $(document).on('click', '.nav-tab', this.handleTabClick);
        },

        handleTabClick: function(e) {
            var $tab = $(this);
            if ($tab.hasClass('nav-tab-active')) {
                return;
            }
            
            // Update URL without page reload
            var url = $tab.attr('href');
            if (history.pushState) {
                history.pushState(null, null, url);
            }
        },

        initializeActiveTab: function() {
            // Handle browser back/forward
            $(window).on('popstate', function() {
                location.reload();
            });
        }
    };

    // Initialize all managers
    PCOManager.init();
    FileUploadManager.init();
    TabManager.init();

    // Global error handling
    $(document).ajaxError(function(event, xhr, settings) {
        if (xhr.status === 403) {
            alert('Session expired. Please refresh the page and try again.');
        }
    });

    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Escape key to cancel edit forms
        if (e.key === 'Escape') {
            $('.pco-cancel-btn:visible').click();
        }
        
        // Ctrl+S to save (prevent default and submit form if in edit mode)
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            var $visibleForm = $('.pco-edit-form:visible form');
            if ($visibleForm.length) {
                $visibleForm.submit();
            }
        }
    });

    // Auto-hide notices after 5 seconds
    setTimeout(function() {
        $('.notice.is-dismissible').fadeOut();
    }, 5000);

    console.log('LCD County Map Admin scripts loaded');
});