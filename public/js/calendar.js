/**
 * SFAF Calendar Frontend JS
 */
(function($) {
    'use strict';

    var modal = null;
    var currentEventId = null;

    $(document).ready(function() {
        initFilters();
        initSearch();
        initRSVP();
        initReminders();
        initAddToCalendar();
        initFAQ();
        initPagination();
    });

    /**
     * Pagination: Load More button + Infinite scroll.
     * (Next/Previous is plain links and needs no JS.)
     */
    function initPagination() {
        // Load More
        $(document).on('click', '.uc-load-more', function() {
            var $container = $(this).closest('[data-render]');
            loadMoreEvents($container, $(this));
        });

        // Infinite scroll
        if ('IntersectionObserver' in window) {
            document.querySelectorAll('.uc-infinite-sentinel').forEach(function(sentinel) {
                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            var $container = $(sentinel).closest('[data-render]');
                            loadMoreEvents($container, $(sentinel));
                        }
                    });
                }, { rootMargin: '300px' });
                observer.observe(sentinel);
                sentinel._ucObserver = observer;
            });
        }
    }

    function loadMoreEvents($container, $trigger) {
        if (!$container.length || $container.data('uc-loading')) {
            return;
        }
        var page = parseInt($container.attr('data-page') || '1', 10);
        var maxPages = parseInt($container.attr('data-max-pages') || '1', 10);
        var next = page + 1;

        if (next > maxPages) {
            finishPagination($container, $trigger);
            return;
        }

        $container.data('uc-loading', true);
        var isButton = $trigger.is('button');
        if (isButton) {
            $trigger.prop('disabled', true).text('Loading…');
        } else {
            $trigger.closest('.uc-pagination-infinite').addClass('is-loading');
        }

        $.ajax({
            url: ucData.ajaxUrl,
            method: 'POST',
            data: {
                action:    'uc_load_events',
                nonce:     ucData.nonce,
                page:      next,
                per_page:  $container.attr('data-per-page'),
                category:  $container.attr('data-filter-category') || '',
                organizer: $container.attr('data-filter-organizer') || '',
                render:    $container.attr('data-render') || 'card'
            },
            success: function(resp) {
                if (resp && resp.html) {
                    $container.find('.uc-event-list, .uc-upcoming-list').first().append(resp.html);
                    $container.attr('data-page', next);
                }
                if (!resp || !resp.has_more || next >= maxPages) {
                    finishPagination($container, $trigger);
                } else if (isButton) {
                    $trigger.prop('disabled', false).text('Load More');
                } else {
                    $trigger.closest('.uc-pagination-infinite').removeClass('is-loading');
                }
            },
            error: function() {
                if (isButton) {
                    $trigger.prop('disabled', false).text('Load More');
                } else {
                    $trigger.closest('.uc-pagination-infinite').removeClass('is-loading');
                }
            },
            complete: function() {
                $container.data('uc-loading', false);
            }
        });
    }

    function finishPagination($container, $trigger) {
        var $pagination = $trigger.closest('.uc-pagination');
        if ($trigger.is('button')) {
            $pagination.remove();
        } else {
            if ($trigger[0] && $trigger[0]._ucObserver) {
                $trigger[0]._ucObserver.disconnect();
            }
            $pagination.remove();
        }
    }

    /**
     * FAQ accordion (single event page).
     */
    function initFAQ() {
        $(document).on('click', '.uc-faq-q', function() {
            var $item = $(this).closest('.uc-faq-item');
            var open  = $item.toggleClass('open').hasClass('open');
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            $(this).find('.uc-faq-toggle').text(open ? '–' : '+');
        });
    }

    /**
     * Category filter buttons
     */
    function initFilters() {
        $('.uc-filter-btn').on('click', function() {
            var cat = $(this).data('category');
            
            $('.uc-filter-btn').removeClass('active');
            $(this).addClass('active');

            if (cat === 'all') {
                $('.uc-event-card').show();
            } else {
                $('.uc-event-card').each(function() {
                    var cardCat = $(this).data('category');
                    $(this).toggle(cardCat === cat);
                });
            }

            updateCount();
        });

        $('.uc-organizer-select').on('change', function() {
            var org = $(this).val();
            // For now, this is a client-side hint. 
            // Full AJAX filtering can be added in Phase 2.
        });
    }

    /**
     * Search
     */
    function initSearch() {
        var searchTimer;
        $('.uc-search').on('input', function() {
            clearTimeout(searchTimer);
            var query = $(this).val().toLowerCase();
            
            searchTimer = setTimeout(function() {
                if (query.length === 0) {
                    $('.uc-event-card').show();
                } else {
                    $('.uc-event-card').each(function() {
                        var title = $(this).find('.uc-card-title').text().toLowerCase();
                        var excerpt = $(this).find('.uc-card-excerpt').text().toLowerCase();
                        var meta = $(this).find('.uc-card-meta').text().toLowerCase();
                        var match = title.indexOf(query) > -1 || 
                                    excerpt.indexOf(query) > -1 || 
                                    meta.indexOf(query) > -1;
                        $(this).toggle(match);
                    });
                }
                updateCount();
            }, 200);
        });
    }

    /**
     * Update visible event count
     */
    function updateCount() {
        var visible = $('.uc-event-card:visible').length;
        $('.uc-count-number').text(visible);
    }

    /**
     * RSVP system
     */
    function initRSVP() {
        // Build the modal (inject into body once)
        var modalHTML = '<div class="uc-rsvp-modal-overlay" id="uc-rsvp-modal">' +
            '<div class="uc-rsvp-modal">' +
                '<div class="uc-rsvp-form-view">' +
                    '<h3>Register for this Event</h3>' +
                    '<p class="uc-modal-subtitle" id="uc-rsvp-event-title"></p>' +
                    '<div class="uc-rsvp-form">' +
                        '<div><label for="uc-rsvp-name">Full Name *</label>' +
                            '<input type="text" id="uc-rsvp-name" placeholder="Your name" /></div>' +
                        '<div><label for="uc-rsvp-email">Email *</label>' +
                            '<input type="email" id="uc-rsvp-email" placeholder="your@email.com" /></div>' +
                        '<div><label for="uc-rsvp-phone">Phone (optional)</label>' +
                            '<input type="tel" id="uc-rsvp-phone" placeholder="(555) 000-0000" /></div>' +
                        '<div class="uc-rsvp-error" id="uc-rsvp-error" style="display:none;"></div>' +
                        '<button class="uc-rsvp-submit" id="uc-rsvp-submit-btn">Register Now</button>' +
                        '<button class="uc-rsvp-cancel" id="uc-rsvp-cancel-btn">Cancel</button>' +
                    '</div>' +
                '</div>' +
                '<div class="uc-rsvp-success" id="uc-rsvp-success" style="display:none;">' +
                    '<div class="uc-check">&#10003;</div>' +
                    '<p>You are registered!</p>' +
                    '<p class="uc-modal-subtitle">We look forward to seeing you.</p>' +
                    '<button class="uc-rsvp-cancel" id="uc-rsvp-close-btn" style="margin-top: 16px;">Close</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        $('body').append(modalHTML);
        modal = $('#uc-rsvp-modal');

        // Open modal
        $(document).on('click', '.uc-rsvp-btn', function(e) {
            e.preventDefault();
            currentEventId = $(this).data('event-id');
            
            // Get event title from card
            var card = $(this).closest('.uc-event-card');
            var title = card.find('.uc-card-title').text().trim();
            $('#uc-rsvp-event-title').text(title);

            // Reset form
            $('#uc-rsvp-name, #uc-rsvp-email, #uc-rsvp-phone').val('');
            $('#uc-rsvp-error').hide();
            $('#uc-rsvp-submit-btn').prop('disabled', false).text('Register Now');
            $('.uc-rsvp-form-view').show();
            $('#uc-rsvp-success').hide();

            modal.addClass('active');
        });

        // Close modal
        modal.on('click', function(e) {
            if (e.target === this) closeModal();
        });
        $(document).on('click', '#uc-rsvp-cancel-btn, #uc-rsvp-close-btn', function() {
            closeModal();
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        // Submit RSVP
        $(document).on('click', '#uc-rsvp-submit-btn', function() {
            submitRSVP();
        });

        // Submit on Enter
        modal.on('keydown', 'input', function(e) {
            if (e.key === 'Enter') submitRSVP();
        });
    }

    function closeModal() {
        modal.removeClass('active');
        currentEventId = null;
    }

    function submitRSVP() {
        var name  = $('#uc-rsvp-name').val().trim();
        var email = $('#uc-rsvp-email').val().trim();
        var phone = $('#uc-rsvp-phone').val().trim();

        // Validation
        if (!name || !email) {
            $('#uc-rsvp-error').text('Please fill in your name and email.').show();
            return;
        }
        if (email.indexOf('@') === -1 || email.indexOf('.') === -1) {
            $('#uc-rsvp-error').text('Please enter a valid email address.').show();
            return;
        }

        var btn = $('#uc-rsvp-submit-btn');
        btn.prop('disabled', true).text('Submitting...');
        $('#uc-rsvp-error').hide();

        $.ajax({
            url: ucData.ajaxUrl,
            method: 'POST',
            data: {
                action:   'uc_submit_rsvp',
                nonce:    ucData.nonce,
                event_id: currentEventId,
                name:     name,
                email:    email,
                phone:    phone
            },
            success: function(response) {
                if (response.success) {
                    // Show success
                    $('.uc-rsvp-form-view').hide();
                    $('#uc-rsvp-success').show();

                    // Update the capacity bar and count on the card
                    if (response.count !== undefined) {
                        var card = $('.uc-rsvp-btn[data-event-id="' + currentEventId + '"]').closest('.uc-event-card');
                        var capacityText = card.find('.uc-capacity-text');
                        var capacityFill = card.find('.uc-capacity-fill');
                        
                        if (capacityText.length) {
                            var text = capacityText.text();
                            // Update count in "X/Y spots filled" or "X registered"
                            if (text.indexOf('/') > -1) {
                                var parts = text.split('/');
                                var total = parseInt(parts[1]);
                                capacityText.text(response.count + '/' + total + ' spots filled');
                                capacityFill.css('width', Math.min((response.count / total) * 100, 100) + '%');
                            } else {
                                capacityText.text(response.count + ' registered');
                            }
                        }
                    }
                } else {
                    $('#uc-rsvp-error').text(response.message || 'Something went wrong.').show();
                    btn.prop('disabled', false).text('Register Now');
                }
            },
            error: function() {
                $('#uc-rsvp-error').text('Network error. Please try again.').show();
                btn.prop('disabled', false).text('Register Now');
            }
        });
    }

    /**
     * "Get Reminders" modal — captures an email and saves it with
     * status "subscribed" (separate from RSVP "confirmed").
     */
    function initReminders() {
        var modalHTML = '<div class="uc-rsvp-modal-overlay" id="uc-reminder-modal">' +
            '<div class="uc-rsvp-modal">' +
                '<div class="uc-reminder-form-view">' +
                    '<h3>Get Event Reminders</h3>' +
                    '<p class="uc-modal-subtitle" id="uc-reminder-event-title"></p>' +
                    '<div class="uc-rsvp-form">' +
                        '<div><label for="uc-reminder-email">Email *</label>' +
                            '<input type="email" id="uc-reminder-email" placeholder="your@email.com" /></div>' +
                        '<div class="uc-rsvp-error" id="uc-reminder-error" style="display:none;"></div>' +
                        '<button class="uc-rsvp-submit" id="uc-reminder-submit-btn">Notify Me</button>' +
                        '<button class="uc-rsvp-cancel" id="uc-reminder-cancel-btn">Cancel</button>' +
                    '</div>' +
                '</div>' +
                '<div class="uc-rsvp-success" id="uc-reminder-success" style="display:none;">' +
                    '<div class="uc-check">&#128276;</div>' +
                    '<p id="uc-reminder-success-msg">You are on the list!</p>' +
                    '<button class="uc-rsvp-cancel" id="uc-reminder-close-btn" style="margin-top: 16px;">Close</button>' +
                '</div>' +
            '</div>' +
        '</div>';

        $('body').append(modalHTML);
        var rmodal = $('#uc-reminder-modal');
        var reminderEventId = null;

        $(document).on('click', '.uc-reminder-btn', function(e) {
            e.preventDefault();
            reminderEventId = $(this).data('event-id');
            $('#uc-reminder-event-title').text($(this).data('event-title') || '');
            $('#uc-reminder-email').val('');
            $('#uc-reminder-error').hide();
            $('#uc-reminder-submit-btn').prop('disabled', false).text('Notify Me');
            $('.uc-reminder-form-view').show();
            $('#uc-reminder-success').hide();
            rmodal.addClass('active');
        });

        rmodal.on('click', function(e) {
            if (e.target === this) rmodal.removeClass('active');
        });
        $(document).on('click', '#uc-reminder-cancel-btn, #uc-reminder-close-btn', function() {
            rmodal.removeClass('active');
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') rmodal.removeClass('active');
        });

        function submitReminder() {
            var email = $('#uc-reminder-email').val().trim();
            if (!email || email.indexOf('@') === -1 || email.indexOf('.') === -1) {
                $('#uc-reminder-error').text('Please enter a valid email address.').show();
                return;
            }

            var btn = $('#uc-reminder-submit-btn');
            btn.prop('disabled', true).text('Submitting...');
            $('#uc-reminder-error').hide();

            $.ajax({
                url: ucData.ajaxUrl,
                method: 'POST',
                data: {
                    action:   'uc_subscribe_reminder',
                    nonce:    ucData.nonce,
                    event_id: reminderEventId,
                    email:    email
                },
                success: function(response) {
                    if (response.success) {
                        $('.uc-reminder-form-view').hide();
                        $('#uc-reminder-success-msg').text(response.message || 'You are on the list!');
                        $('#uc-reminder-success').show();
                    } else {
                        $('#uc-reminder-error').text(response.message || 'Something went wrong.').show();
                        btn.prop('disabled', false).text('Notify Me');
                    }
                },
                error: function() {
                    $('#uc-reminder-error').text('Network error. Please try again.').show();
                    btn.prop('disabled', false).text('Notify Me');
                }
            });
        }

        $(document).on('click', '#uc-reminder-submit-btn', submitReminder);
        rmodal.on('keydown', 'input', function(e) {
            if (e.key === 'Enter') submitReminder();
        });
    }

    /**
     * "Add to Calendar" dropdown toggle.
     */
    function initAddToCalendar() {
        $(document).on('click', '.uc-addcal-toggle', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var parent = $(this).closest('.uc-addcal');
            $('.uc-addcal').not(parent).removeClass('open');
            parent.toggleClass('open');
        });
        $(document).on('click', '.uc-addcal-menu', function(e) {
            e.stopPropagation();
        });
        $(document).on('click', function() {
            $('.uc-addcal').removeClass('open');
        });
    }

})(jQuery);
