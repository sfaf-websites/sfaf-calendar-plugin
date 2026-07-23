/**
 * Unified Calendar Frontend JS
 */
(function($) {
    'use strict';

    var modal = null;
    var currentEventId = null;

    $(document).ready(function() {
        initFilters();
        initSearch();
        initRSVP();
    });

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

})(jQuery);
