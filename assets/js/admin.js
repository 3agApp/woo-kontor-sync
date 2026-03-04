/**
 * Woo Kontor Sync Admin JavaScript
 */

(function ($) {
    'use strict';

    const WKS = {

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function (text) {
            if (text === null || text === undefined) {
                return '';
            }
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        },

        /**
         * Initialize
         */
        init: function () {
            this.bindEvents();
            this.initChart();
            this.initLocalTimes();
        },

        /**
         * Convert timestamps to local timezone and relative time
         */
        initLocalTimes: function () {
            $('.wssc-local-time').each(function () {
                const $el = $(this);
                const timestamp = parseInt($el.data('timestamp'), 10);

                if (!timestamp || isNaN(timestamp)) {
                    return;
                }

                const date = new Date(timestamp * 1000);
                const now = new Date();
                const diffMs = now - date;

                if (diffMs < 0) {
                    $el.text('just now');
                    $el.attr('title', date.toLocaleString());
                    return;
                }

                const diffSec = Math.floor(diffMs / 1000);
                const diffMin = Math.floor(diffSec / 60);
                const diffHour = Math.floor(diffMin / 60);
                const diffDay = Math.floor(diffHour / 24);

                let relativeTime;
                if (diffSec < 60) {
                    relativeTime = diffSec <= 5 ? 'just now' : diffSec + ' sec ago';
                } else if (diffMin < 60) {
                    relativeTime = diffMin + ' min ago';
                } else if (diffHour < 24) {
                    relativeTime = diffHour + ' hour' + (diffHour !== 1 ? 's' : '') + ' ago';
                } else if (diffDay < 30) {
                    relativeTime = diffDay + ' day' + (diffDay !== 1 ? 's' : '') + ' ago';
                } else {
                    const months = Math.floor(diffDay / 30);
                    relativeTime = months + ' month' + (months !== 1 ? 's' : '') + ' ago';
                }

                $el.text(relativeTime);
                $el.attr('title', date.toLocaleString());
            });
        },

        /**
         * Bind events
         */
        bindEvents: function () {
            // Dashboard
            $('#wssc-run-sync').on('click', this.runSync.bind(this));
            $('#wssc-toggle-sync').on('change', this.toggleSync.bind(this));

            // Logs
            $('.wssc-view-log').on('click', this.viewLog.bind(this));
            $('#wssc-clear-logs').on('click', this.clearLogs.bind(this));

            // Settings
            $('#wssc-settings-form').on('submit', this.saveSettings.bind(this));
            $('#wssc-test-connection').on('click', this.testConnection.bind(this));

            // Manufacturer tags
            this.initManufacturerTags();
            $('#wssc-fetch-manufacturers').on('click', this.fetchManufacturers.bind(this));

            // License
            $('#wssc-license-form').on('submit', this.activateLicense.bind(this));
            $('#wssc-deactivate-license').on('click', this.deactivateLicense.bind(this));
            $('#wssc-check-license').on('click', this.checkLicense.bind(this));
            $('#wssc-activate-domain').on('click', this.activateDomain.bind(this));

            // Updates
            $('#wssc-check-update').on('click', this.checkUpdate.bind(this));
            $('#wssc-install-update').on('click', this.installUpdate.bind(this));

            // Modal
            $('.wssc-modal-close').on('click', this.closeModal.bind(this));
            $('.wssc-modal').on('click', function (e) {
                if ($(e.target).hasClass('wssc-modal')) {
                    WKS.closeModal();
                }
            });

            $(document).on('keyup', function (e) {
                if (e.key === 'Escape') {
                    WKS.closeModal();
                }
            });
        },

        /**
         * Run manual sync
         */
        runSync: function (e) {
            e.preventDefault();

            if (!confirm(wks_admin.strings.confirm_sync)) {
                return;
            }

            const $btn = $('#wssc-run-sync');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wks_admin.strings.sync_running);

            this.ajax('wks_run_sync', {})
                .done(function (response) {
                    if (response.success) {
                        WKS.toast(response.data.message, 'success');
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    } else {
                        WKS.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                })
                .fail(function () {
                    WKS.toast(wks_admin.strings.sync_error, 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Toggle sync enabled/disabled
         */
        toggleSync: function (e) {
            const enabled = $(e.target).is(':checked');

            this.ajax('wks_toggle_sync', { enabled: enabled })
                .done(function (response) {
                    if (response.success) {
                        WKS.toast(response.data.message, 'success');
                    } else {
                        WKS.toast(response.data.message, 'error');
                        $(e.target).prop('checked', !enabled);
                    }
                })
                .fail(function () {
                    WKS.toast('An error occurred', 'error');
                    $(e.target).prop('checked', !enabled);
                });
        },

        /**
         * View log details
         */
        viewLog: function (e) {
            e.preventDefault();

            const $btn = $(e.currentTarget);
            const logId = $btn.data('log-id');

            const $icon = $btn.find('.dashicons');
            const originalClass = $icon.attr('class');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-update wssc-spin');
            $btn.css('pointer-events', 'none');

            this.ajax('wks_get_log_details', { log_id: logId })
                .done(function (response) {
                    if (response.success) {
                        WKS.showLogModal(response.data.log);
                    } else {
                        WKS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WKS.toast('Failed to load log details', 'error');
                })
                .always(function () {
                    $icon.attr('class', originalClass);
                    $btn.css('pointer-events', '');
                });
        },

        /**
         * Show log modal
         */
        showLogModal: function (log) {
            const esc = this.escapeHtml.bind(this);
            let html = '<div class="wssc-log-detail">';

            // Status
            html += '<div class="wssc-log-detail-row">';
            html += '<span class="wssc-log-detail-label">Status</span>';
            html += '<span class="wssc-log-detail-value">';
            html += '<span class="wssc-status-badge wssc-status-' + esc(log.status) + '">';
            if (log.status === 'success') {
                html += '<span class="dashicons dashicons-yes-alt"></span>';
            } else if (log.status === 'error') {
                html += '<span class="dashicons dashicons-dismiss"></span>';
            } else {
                html += '<span class="dashicons dashicons-warning"></span>';
            }
            html += '</span> ' + esc(log.status.charAt(0).toUpperCase() + log.status.slice(1));
            html += '</span></div>';

            // Type
            html += '<div class="wssc-log-detail-row">';
            html += '<span class="wssc-log-detail-label">Type</span>';
            html += '<span class="wssc-log-detail-value">' + esc(log.type.charAt(0).toUpperCase() + log.type.slice(1)) + '</span>';
            html += '</div>';

            // Trigger
            if (log.trigger_type) {
                html += '<div class="wssc-log-detail-row">';
                html += '<span class="wssc-log-detail-label">Trigger</span>';
                html += '<span class="wssc-log-detail-value">' + esc(log.trigger_type.charAt(0).toUpperCase() + log.trigger_type.slice(1)) + '</span>';
                html += '</div>';
            }

            // Message
            html += '<div class="wssc-log-detail-row">';
            html += '<span class="wssc-log-detail-label">Message</span>';
            html += '<span class="wssc-log-detail-value">' + esc(log.message) + '</span>';
            html += '</div>';

            // Duration
            if (log.duration && parseFloat(log.duration) > 0) {
                html += '<div class="wssc-log-detail-row">';
                html += '<span class="wssc-log-detail-label">Duration</span>';
                html += '<span class="wssc-log-detail-value">' + parseFloat(log.duration).toFixed(2) + ' seconds</span>';
                html += '</div>';
            }

            // Date
            html += '<div class="wssc-log-detail-row">';
            html += '<span class="wssc-log-detail-label">Date</span>';
            const logDate = new Date(log.created_at.replace(' ', 'T') + 'Z');
            html += '<span class="wssc-log-detail-value">' + esc(logDate.toLocaleString()) + '</span>';
            html += '</div>';

            // Stats
            if (log.stats && typeof log.stats === 'object' && Object.keys(log.stats).length > 0) {
                html += '<div class="wssc-log-detail-row">';
                html += '<span class="wssc-log-detail-label">Statistics</span>';
                html += '<div class="wssc-log-detail-value">';
                html += '<div class="wssc-log-stats-grid">';

                if (log.stats.total_api !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.total_api + '</strong><span>API Products</span></div>';
                }
                if (log.stats.created !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.created + '</strong><span>Created</span></div>';
                }
                if (log.stats.updated !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.updated + '</strong><span>Updated</span></div>';
                }
                if (log.stats.skipped !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.skipped + '</strong><span>Skipped</span></div>';
                }
                if (log.stats.errors !== undefined && log.stats.errors > 0) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.errors + '</strong><span>Errors</span></div>';
                }
                if (log.stats.images_set !== undefined && log.stats.images_set > 0) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.images_set + '</strong><span>Images Set</span></div>';
                }
                if (log.stats.pages_fetched !== undefined) {
                    html += '<div class="wssc-log-stat-item"><strong>' + log.stats.pages_fetched + '</strong><span>Pages Fetched</span></div>';
                }

                html += '</div></div></div>';
            }

            // Errors list
            if (log.errors && Array.isArray(log.errors) && log.errors.length > 0) {
                html += '<div class="wssc-log-detail-row">';
                html += '<span class="wssc-log-detail-label">Error Details</span>';
                html += '<div class="wssc-log-detail-value">';
                html += '<div class="wssc-log-errors">';
                html += '<strong>Errors:</strong><ul>';
                log.errors.forEach(function (err) {
                    html += '<li>' + esc(err) + '</li>';
                });
                html += '</ul></div></div></div>';
            }

            html += '</div>';

            $('#wssc-log-modal-body').html(html);
            $('#wssc-log-modal').addClass('wssc-modal-open');
        },

        /**
         * Clear logs
         */
        clearLogs: function (e) {
            e.preventDefault();

            if (!confirm(wks_admin.strings.confirm_clear_logs)) {
                return;
            }

            const $btn = $('#wssc-clear-logs');
            $btn.prop('disabled', true);

            this.ajax('wks_clear_logs', {})
                .done(function (response) {
                    if (response.success) {
                        WKS.toast(response.data.message, 'success');
                        location.reload();
                    } else {
                        WKS.toast(response.data.message, 'error');
                    }
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        /**
         * Initialize manufacturer tag input
         */
        initManufacturerTags: function () {
            var self = this;
            var $wrapper = $('#wks-manufacturer-tags-wrapper');
            var $input = $('#wks-manufacturer-input');
            var $hidden = $('#wks-manufacturer-filter');

            if (!$wrapper.length) return;

            // Focus input when clicking wrapper
            $wrapper.on('click', function () {
                $input.focus();
            });

            // Add tag on Enter or comma
            $input.on('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    var val = $.trim($input.val().replace(/,/g, ''));
                    if (val) {
                        self.addManufacturerTag(val);
                        $input.val('');
                    }
                }
                // Remove last tag on Backspace if input is empty
                if (e.key === 'Backspace' && $input.val() === '') {
                    $wrapper.find('.wssc-tag:last .wssc-tag-remove').trigger('click');
                }
            });

            // Also add on blur
            $input.on('blur', function () {
                var val = $.trim($input.val().replace(/,/g, ''));
                if (val) {
                    self.addManufacturerTag(val);
                    $input.val('');
                }
            });

            // Remove tag
            $wrapper.on('click', '.wssc-tag-remove', function (e) {
                e.stopPropagation();
                $(this).closest('.wssc-tag').remove();
                self.updateManufacturerHidden();
            });
        },

        /**
         * Add a manufacturer tag
         */
        addManufacturerTag: function (value) {
            var $wrapper = $('#wks-manufacturer-tags-wrapper');
            var $input = $('#wks-manufacturer-input');

            // Check for duplicates (case-insensitive)
            var exists = false;
            $wrapper.find('.wssc-tag-remove').each(function () {
                if ($(this).data('value').toString().toLowerCase() === value.toLowerCase()) {
                    exists = true;
                }
            });
            if (exists) return;

            var $tag = $('<span class="wssc-tag"></span>')
                .text(value)
                .append(
                    $('<button type="button" class="wssc-tag-remove"></button>')
                        .attr('data-value', value)
                        .html('&times;')
                );

            $tag.insertBefore($input);
            this.updateManufacturerHidden();
        },

        /**
         * Update hidden manufacturer field from tags
         */
        updateManufacturerHidden: function () {
            var values = [];
            $('#wks-manufacturer-tags-wrapper .wssc-tag-remove').each(function () {
                values.push($(this).data('value'));
            });
            $('#wks-manufacturer-filter').val(values.join(','));
        },

        /**
         * Fetch available manufacturers from API
         */
        fetchManufacturers: function (e) {
            e.preventDefault();
            var self = this;

            var $btn = $('#wssc-fetch-manufacturers');
            var $list = $('#wssc-manufacturers-list');
            var originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> Fetching manufacturers…');
            $list.hide().empty();

            this.ajax('wks_fetch_manufacturers', {})
                .done(function (response) {
                    if (response.success && response.data.manufacturers) {
                        var manufacturers = response.data.manufacturers;
                        if (manufacturers.length === 0) {
                            $list.html('<p class="wssc-mfr-empty">No manufacturers found in API.</p>').show();
                            return;
                        }

                        var html = '<p class="wssc-mfr-count">' + manufacturers.length + ' manufacturer(s) found. Click to add:</p>';
                        html += '<div class="wssc-mfr-chips">';
                        manufacturers.forEach(function (mfr) {
                            html += '<button type="button" class="wssc-mfr-chip" data-value="' + $('<span>').text(mfr).html() + '">' + $('<span>').text(mfr).html() + '</button>';
                        });
                        html += '</div>';
                        $list.html(html).show();

                        // Bind click on chips
                        $list.find('.wssc-mfr-chip').on('click', function () {
                            var val = $(this).data('value');
                            self.addManufacturerTag(val);
                            $(this).addClass('wssc-mfr-chip-added').prop('disabled', true);
                        });

                        // Mark already-added manufacturers
                        var current = $('#wks-manufacturer-filter').val().toLowerCase().split(',').map(function (v) { return $.trim(v); });
                        $list.find('.wssc-mfr-chip').each(function () {
                            if (current.indexOf($(this).data('value').toString().toLowerCase()) !== -1) {
                                $(this).addClass('wssc-mfr-chip-added').prop('disabled', true);
                            }
                        });
                    } else {
                        WKS.toast(response.data.message || 'Failed to fetch manufacturers', 'error');
                    }
                })
                .fail(function () {
                    WKS.toast('Failed to fetch manufacturers from API', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Save settings
         */
        saveSettings: function (e) {
            e.preventDefault();

            const $form = $('#wssc-settings-form');
            const $btn = $form.find('button[type="submit"]');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wks_admin.strings.saving);

            const data = {
                api_host: $('#wks-api-host').val(),
                api_key: $('#wks-api-key').val(),
                image_prefix_url: $('#wks-image-prefix-url').val(),
                schedule_interval: $('#wks-schedule-interval').val(),
                enabled: $('#wks-enabled').is(':checked'),
                manufacturer_filter: $('#wks-manufacturer-filter').val()
            };

            this.ajax('wks_save_settings', data)
                .done(function (response) {
                    if (response.success) {
                        WKS.toast(response.data.message, 'success');
                    } else {
                        WKS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WKS.toast('Failed to save settings', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Test Kontor API connection
         */
        testConnection: function (e) {
            e.preventDefault();

            var apiHost = $('#wks-api-host').val();
            var apiKey = $('#wks-api-key').val();

            if (!apiHost || !apiKey) {
                WKS.toast('Please enter both API Host and API Key', 'error');
                return;
            }

            const $btn = $('#wssc-test-connection');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wks_admin.strings.testing);

            this.ajax('wks_test_connection', { api_host: apiHost, api_key: apiKey })
                .done(function (response) {
                    if (response.success) {
                        WKS.toast(response.data.message, 'success');
                    } else {
                        WKS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WKS.toast('Connection test failed', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Activate license
         */
        activateLicense: function (e) {
            e.preventDefault();

            const licenseKey = $('#wssc-license-key').val().trim();
            if (!licenseKey) {
                WKS.toast('Please enter a license key', 'error');
                return;
            }

            const $form = $('#wssc-license-form');
            const $btn = $form.find('button[type="submit"]');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wks_admin.strings.activating);

            this.ajax('wks_activate_license', { license_key: licenseKey })
                .done(function (response) {
                    if (response.success) {
                        WKS.toast(response.data.message, 'success');
                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    } else {
                        WKS.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                })
                .fail(function () {
                    WKS.toast('Failed to activate license', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Activate domain for existing license
         */
        activateDomain: function (e) {
            e.preventDefault();

            const $btn = $('#wssc-activate-domain');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wks_admin.strings.activating);

            this.ajax('wks_activate_domain', {})
                .done(function (response) {
                    if (response.success) {
                        WKS.toast(response.data.message, 'success');
                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    } else {
                        WKS.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                })
                .fail(function () {
                    WKS.toast('Failed to activate domain', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Deactivate license
         */
        deactivateLicense: function (e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to deactivate this license?')) {
                return;
            }

            const $btn = $('#wssc-deactivate-license');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> ' + wks_admin.strings.deactivating);

            this.ajax('wks_deactivate_license', {})
                .done(function (response) {
                    WKS.toast(response.data.message, 'success');
                    setTimeout(function () {
                        location.reload();
                    }, 1000);
                })
                .fail(function () {
                    WKS.toast('Failed to deactivate license', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Check license
         */
        checkLicense: function (e) {
            e.preventDefault();

            const $btn = $('#wssc-check-license');
            $btn.prop('disabled', true);

            this.ajax('wks_check_license', {})
                .done(function (response) {
                    if (response.success) {
                        if (response.data.valid && response.data.activated) {
                            WKS.toast('License is valid and active', 'success');
                        } else if (response.data.valid && !response.data.activated) {
                            WKS.toast('License is valid but not activated on this domain', 'warning');
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        } else {
                            const status = response.data.data && response.data.data.status ? response.data.data.status : 'invalid';
                            WKS.toast('License is ' + status, 'error');
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        WKS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WKS.toast('Failed to check license', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        },

        /**
         * Check for plugin updates
         */
        checkUpdate: function (e) {
            e.preventDefault();

            const $btn = $('#wssc-check-update');
            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> Checking...');

            this.ajax('wks_check_update', {})
                .done(function (response) {
                    if (response.success) {
                        WKS.toast(response.data.message, response.data.has_update ? 'info' : 'success');
                        if (response.data.has_update) {
                            setTimeout(function () {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        WKS.toast(response.data.message, 'error');
                    }
                })
                .fail(function () {
                    WKS.toast('Failed to check for updates', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html(originalHtml);
                });
        },

        /**
         * Install plugin update
         */
        installUpdate: function (e) {
            e.preventDefault();

            const $btn = $('#wssc-install-update');
            const version = $btn.data('version');

            if (!confirm('Are you sure you want to update to version ' + version + '?')) {
                return;
            }

            const originalHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="wssc-spinner"></span> Updating...');

            $('#wssc-check-update').prop('disabled', true);

            this.ajax('wks_install_update', {})
                .done(function (response) {
                    if (response.success) {
                        WKS.toast(response.data.message, 'success');
                        if (response.data.reload) {
                            setTimeout(function () {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        WKS.toast(response.data.message, 'error');
                        $btn.prop('disabled', false).html(originalHtml);
                        $('#wssc-check-update').prop('disabled', false);
                    }
                })
                .fail(function () {
                    WKS.toast('Update failed. Please try again.', 'error');
                    $btn.prop('disabled', false).html(originalHtml);
                    $('#wssc-check-update').prop('disabled', false);
                });
        },

        /**
         * Close modal
         */
        closeModal: function () {
            $('.wssc-modal').removeClass('wssc-modal-open');
        },

        /**
         * AJAX helper
         */
        ajax: function (action, data) {
            data = data || {};
            data.action = action;
            data.nonce = wks_admin.nonce;

            return $.ajax({
                url: wks_admin.ajax_url,
                type: 'POST',
                data: data,
                dataType: 'json'
            });
        },

        /**
         * Toast notification
         */
        toast: function (message, type) {
            type = type || 'success';

            $('.wssc-toast').remove();

            const $toast = $('<div class="wssc-toast wssc-toast-' + type + '">' + message + '</div>');
            $('body').append($toast);

            setTimeout(function () {
                $toast.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 4000);
        },

        /**
         * Initialize chart
         */
        initChart: function () {
            const canvas = document.getElementById('wssc-activity-chart');
            if (!canvas || typeof Chart === 'undefined') {
                if (canvas && typeof wsscChartData !== 'undefined') {
                    this.loadChartJS(function () {
                        WKS.renderChart();
                    });
                }
                return;
            }

            this.renderChart();
        },

        /**
         * Load Chart.js
         */
        loadChartJS: function (callback) {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
            script.onload = callback;
            document.head.appendChild(script);
        },

        /**
         * Render chart
         */
        renderChart: function () {
            const canvas = document.getElementById('wssc-activity-chart');
            if (!canvas || typeof Chart === 'undefined' || typeof wsscChartData === 'undefined') {
                return;
            }

            const labels = wsscChartData.map(function (item) {
                const date = new Date(item.date);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            });

            const successData = wsscChartData.map(function (item) {
                return item.success;
            });

            const errorData = wsscChartData.map(function (item) {
                return item.error;
            });

            const createdData = wsscChartData.map(function (item) {
                return item.created || 0;
            });

            const updatedData = wsscChartData.map(function (item) {
                return item.updated || 0;
            });

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Successful Syncs',
                            data: successData,
                            backgroundColor: 'rgba(16, 185, 129, 0.8)',
                            borderColor: 'rgb(16, 185, 129)',
                            borderWidth: 1,
                            borderRadius: 4,
                        },
                        {
                            label: 'Failed Syncs',
                            data: errorData,
                            backgroundColor: 'rgba(239, 68, 68, 0.8)',
                            borderColor: 'rgb(239, 68, 68)',
                            borderWidth: 1,
                            borderRadius: 4,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        }
    };

    $(document).ready(function () {
        WKS.init();
    });

})(jQuery);
