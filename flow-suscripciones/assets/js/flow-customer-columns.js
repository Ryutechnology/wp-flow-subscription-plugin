/**
 * Flow Customer Columns - WooCommerce Admin Integration
 * Adds Flow subscription columns to WooCommerce customers interface
 */

console.log('🚨 FLOW DEBUG: Script file is loading - Top level');

(function($) {
    'use strict';

    console.log('🎯 Flow Customer Columns: Script loaded');
    console.log('🎯 Flow Customer Columns: jQuery available:', typeof $ !== 'undefined');

    // Configuration
    const FLOW_CONFIG = {
        ajaxUrl: window.flowCustomerData?.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php',
        nonce: window.flowCustomerData?.nonce || '',
        isProduction: window.flowCustomerData?.isProduction || false,
        retryAttempts: 3,
        retryDelay: 2000
    };

    // Cache for customer data to avoid duplicate AJAX calls
    let customerDataCache = {};
    let processedTables = new Set();
    let isProcessing = false;

    /**
     * Initialize the Flow columns functionality
     */
    function init() {
        console.log('🚀 Flow Customer Columns: Initializing...');

        // Wait for DOM to be ready
        $(document).ready(function() {
            console.log('📋 Flow Customer Columns: DOM ready');

            // Try to add columns immediately
            addFlowColumnsToTables();

            // Set up observers for dynamic content
            setupMutationObserver();
            setupIntervalChecker();
            setupHashChangeListener();
        });
    }

    /**
     * Main function to scan and add Flow columns to customer tables
     */
    function addFlowColumnsToTables() {
        if (isProcessing) return;
        isProcessing = true;

        try {
            console.log('🔍 Flow Customer Columns: Scanning for customer tables...');

            let tablesProcessed = 0;

            // Find all potential customer tables
            $('table').each(function() {
                const $table = $(this);
                const tableId = $table.attr('id') || 'table-' + tablesProcessed;

                // Skip if already processed
                if (processedTables.has(tableId)) {
                    return;
                }

                // Check if this looks like a customer table
                if (isCustomerTable($table)) {
                    console.log('📊 Flow Customer Columns: Found customer table:', tableId);

                    if (addColumnsToTable($table)) {
                        processedTables.add(tableId);
                        tablesProcessed++;
                    }
                }
            });

            if (tablesProcessed > 0) {
                console.log(`✅ Flow Customer Columns: Processed ${tablesProcessed} tables`);
            } else {
                console.log('ℹ️ Flow Customer Columns: No suitable tables found');
            }

        } catch (error) {
            console.error('❌ Flow Customer Columns: Error in addFlowColumnsToTables:', error);
        } finally {
            isProcessing = false;
        }
    }

    /**
     * Check if a table looks like a customer table
     */
    function isCustomerTable($table) {
        const headerText = $table.find('thead tr:first, tr:first').text().toLowerCase();

        // Look for customer-related headers
        const customerIndicators = [
            'customer', 'cliente', 'email', 'name', 'usuario', 'user',
            'orders', 'pedidos', 'spent', 'gastado', 'location', 'ubicación'
        ];

        return customerIndicators.some(indicator => headerText.includes(indicator));
    }

    /**
     * Add Flow columns to a specific table
     */
    function addColumnsToTable($table) {
        try {
            const $headerRow = $table.find('thead tr:first, tr:first');

            if ($headerRow.length === 0) {
                return false;
            }

            // Check if Flow columns already exist
            if ($headerRow.find('[data-flow-column]').length > 0) {
                console.log('ℹ️ Flow Customer Columns: Columns already exist in this table');
                return false;
            }

            // Add headers
            addFlowHeaders($headerRow);

            // Process data rows
            const $dataRows = $table.find('tbody tr, tr:not(:first-child)');
            $dataRows.each(function() {
                addFlowDataCells($(this));
            });

            console.log('✅ Flow Customer Columns: Successfully added columns to table');
            return true;

        } catch (error) {
            console.error('❌ Flow Customer Columns: Error adding columns to table:', error);
            return false;
        }
    }

    /**
     * Add Flow column headers
     */
    function addFlowHeaders($headerRow) {
        const headers = [
            { key: 'status', title: 'Flow Status' },
            { key: 'id', title: 'Subscription ID' },
            { key: 'url', title: 'Dashboard' }
        ];

        headers.forEach(header => {
            const $th = $('<th>')
                .attr('data-flow-column', header.key)
                .text(header.title)
                .css({
                    'min-width': '120px',
                    'text-align': 'center'
                });

            $headerRow.append($th);
        });
    }

    /**
     * Add Flow data cells to a row
     */
    function addFlowDataCells($row) {
        try {
            // Extract customer information
            const customerInfo = extractCustomerInfo($row);

            if (!customerInfo.id && !customerInfo.email) {
                addEmptyCells($row);
                return;
            }

            // Add placeholder cells
            const $statusCell = $('<td>').attr('data-flow-column', 'status').html('<span style="color: #999;">Loading...</span>');
            const $idCell = $('<td>').attr('data-flow-column', 'id').html('<span style="color: #999;">Loading...</span>');
            const $urlCell = $('<td>').attr('data-flow-column', 'url').html('<span style="color: #999;">Loading...</span>');

            $row.append($statusCell, $idCell, $urlCell);

            // Fetch and populate data
            fetchCustomerFlowData(customerInfo, $statusCell, $idCell, $urlCell);

        } catch (error) {
            console.error('❌ Flow Customer Columns: Error adding data cells:', error);
            addEmptyCells($row);
        }
    }

    /**
     * Extract customer information from table row
     */
    function extractCustomerInfo($row) {
        const info = { id: null, email: null, name: null };

        $row.find('td').each(function() {
            const $cell = $(this);
            const cellText = $cell.text().trim();

            // Look for email
            if (cellText.includes('@') && !info.email) {
                info.email = cellText;
            }

            // Look for customer ID in links
            $cell.find('a').each(function() {
                const href = $(this).attr('href') || '';

                // Check for user_id parameter
                const userIdMatch = href.match(/[?&]user_id=(\d+)/);
                if (userIdMatch && !info.id) {
                    info.id = userIdMatch[1];
                }

                // Check for id parameter
                const idMatch = href.match(/[?&]id=(\d+)/);
                if (idMatch && !info.id) {
                    info.id = idMatch[1];
                }
            });

            // Look for data attributes
            const dataId = $cell.data('customer-id') || $cell.data('user-id') || $cell.data('id');
            if (dataId && !info.id) {
                info.id = dataId;
            }

            // Look for name (non-email text in reasonable length)
            if (!info.name && cellText.length > 0 && cellText.length < 50 &&
                !cellText.includes('@') && !cellText.match(/^\d+$/)) {
                info.name = cellText;
            }
        });

        return info;
    }

    /**
     * Fetch Flow data for a customer via AJAX
     */
    function fetchCustomerFlowData(customerInfo, $statusCell, $idCell, $urlCell) {
        const cacheKey = customerInfo.id || customerInfo.email;

        if (!cacheKey) {
            updateCellsWithNoData($statusCell, $idCell, $urlCell);
            return;
        }

        // Check cache first
        if (customerDataCache[cacheKey]) {
            updateCellsWithData(customerDataCache[cacheKey], $statusCell, $idCell, $urlCell);
            return;
        }

        // Prepare AJAX data
        const ajaxData = {
            action: 'flow_get_customer_data',
            customer_id: customerInfo.id || '',
            customer_email: customerInfo.email || '',
            nonce: FLOW_CONFIG.nonce
        };

        console.log('📡 Flow Customer Columns: Fetching data for:', cacheKey);

        // Make AJAX request
        $.post(FLOW_CONFIG.ajaxUrl, ajaxData)
            .done(function(response) {
                console.log('📡 Flow Customer Columns: AJAX response:', response);

                if (response.success && response.data) {
                    customerDataCache[cacheKey] = response.data;
                    updateCellsWithData(response.data, $statusCell, $idCell, $urlCell);
                } else {
                    console.warn('⚠️ Flow Customer Columns: No data returned for:', cacheKey);
                    updateCellsWithNoData($statusCell, $idCell, $urlCell);
                }
            })
            .fail(function(xhr, textStatus, errorThrown) {
                console.error('❌ Flow Customer Columns: AJAX error:', textStatus, errorThrown);
                updateCellsWithError($statusCell, $idCell, $urlCell);
            });
    }

    /**
     * Update cells with Flow data
     */
    function updateCellsWithData(data, $statusCell, $idCell, $urlCell) {
        // Status cell
        const status = data.status || 'no_subscription';
        let statusHtml = '';

        switch (status.toLowerCase()) {
            case 'active':
            case 'activa':
                statusHtml = '<span style="color: #28a745; font-weight: bold;">✓ Activa</span>';
                break;
            case 'inactive':
            case 'inactiva':
                statusHtml = '<span style="color: #dc3545; font-weight: bold;">✗ Inactiva</span>';
                break;
            case 'pending':
            case 'pendiente':
                statusHtml = '<span style="color: #ffc107; font-weight: bold;">⏳ Pendiente</span>';
                break;
            default:
                statusHtml = '<span style="color: #6c757d;">Sin suscripción</span>';
        }
        $statusCell.html(statusHtml);

        // Subscription ID cell
        if (data.flow_subscription_id) {
            $idCell.html(`<code style="font-size: 11px; background: #f8f9fa; padding: 2px 6px; border-radius: 3px;">${data.flow_subscription_id}</code>`);
        } else {
            $idCell.html('<span style="color: #6c757d;">-</span>');
        }

        // Dashboard URL cell
        if (data.flow_subscription_id) {
            const baseUrl = FLOW_CONFIG.isProduction ? 'https://dashboard.flow.cl' : 'https://dashboard.sandbox.flow.cl';
            const dashboardUrl = `${baseUrl}/private/suscripciones/subscriptions/details/?sus_id=${encodeURIComponent(data.flow_subscription_id)}`;

            $urlCell.html(`<a href="${dashboardUrl}" target="_blank" style="color: #007cba; text-decoration: none;" title="Ver en Flow Dashboard">📊 Dashboard</a>`);
        } else {
            $urlCell.html('<span style="color: #6c757d;">-</span>');
        }
    }

    /**
     * Update cells when no data is available
     */
    function updateCellsWithNoData($statusCell, $idCell, $urlCell) {
        $statusCell.html('<span style="color: #6c757d;">Sin suscripción</span>');
        $idCell.html('<span style="color: #6c757d;">-</span>');
        $urlCell.html('<span style="color: #6c757d;">-</span>');
    }

    /**
     * Update cells when there's an error
     */
    function updateCellsWithError($statusCell, $idCell, $urlCell) {
        const errorHtml = '<span style="color: #dc3545; font-size: 11px;">Error</span>';
        $statusCell.html(errorHtml);
        $idCell.html(errorHtml);
        $urlCell.html(errorHtml);
    }

    /**
     * Add empty cells when customer info cannot be extracted
     */
    function addEmptyCells($row) {
        const emptyCell = '<td data-flow-column="empty"><span style="color: #6c757d;">-</span></td>';
        $row.append(emptyCell + emptyCell + emptyCell);
    }

    /**
     * Set up mutation observer for dynamic content
     */
    function setupMutationObserver() {
        if (typeof MutationObserver === 'undefined') {
            console.log('ℹ️ Flow Customer Columns: MutationObserver not supported');
            return;
        }

        const observer = new MutationObserver(function(mutations) {
            let shouldRescan = false;

            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            if (node.tagName === 'TABLE' || $(node).find('table').length > 0) {
                                shouldRescan = true;
                            }
                        }
                    });
                }
            });

            if (shouldRescan) {
                console.log('🔄 Flow Customer Columns: DOM changes detected, rescanning...');
                setTimeout(addFlowColumnsToTables, 1000);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        console.log('✅ Flow Customer Columns: Mutation observer set up');
    }

    /**
     * Set up interval checker for periodic rescans
     */
    function setupIntervalChecker() {
        // Check every 5 seconds for new tables
        setInterval(function() {
            if ($('table').length > processedTables.size) {
                console.log('🔄 Flow Customer Columns: New tables detected via interval check');
                addFlowColumnsToTables();
            }
        }, 5000);
    }

    /**
     * Set up hash change listener for React routing
     */
    function setupHashChangeListener() {
        let lastHash = location.hash;
        let lastPathname = location.pathname;

        setInterval(function() {
            if (location.hash !== lastHash || location.pathname !== lastPathname) {
                lastHash = location.hash;
                lastPathname = location.pathname;

                if (location.href.includes('customers') || location.href.includes('customer')) {
                    console.log('🔄 Flow Customer Columns: Navigation to customers page detected');

                    // Clear cache and processed tables
                    processedTables.clear();
                    customerDataCache = {};

                    // Rescan after a delay
                    setTimeout(addFlowColumnsToTables, 2000);
                }
            }
        }, 1000);
    }

    // Initialize when script loads
    init();

    console.log('✅ Flow Customer Columns: Script initialization complete');

})(jQuery);