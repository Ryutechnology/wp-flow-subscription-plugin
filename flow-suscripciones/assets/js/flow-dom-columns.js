/**
 * Flow Columns - Direct DOM Manipulation for WooCommerce React Interface
 * This approach directly modifies the table DOM and fetches data via AJAX
 */

(function() {
    'use strict';

    console.log('🎯 Flow DOM: Starting direct table manipulation');

    let isProcessing = false;
    let processedRows = new Set();

    // Customer data cache
    let customerDataCache = {};

    /**
     * Add Flow columns to the table header
     */
    function addFlowColumnsToHeader() {
        try {
            // Find the customer table header
            const tables = document.querySelectorAll('table');

            for (let table of tables) {
                const headerRow = table.querySelector('thead tr, tr:first-child');
                if (!headerRow) continue;

                // Check if this looks like a customer table
                const headerText = headerRow.textContent.toLowerCase();
                if (!headerText.includes('name') && !headerText.includes('email') &&
                    !headerText.includes('customer') && !headerText.includes('usuario')) {
                    continue;
                }

                // Check if Flow columns already exist
                if (headerRow.querySelector('[data-flow-column]')) {
                    console.log('ℹ️ Flow DOM: Columns already added to this table');
                    continue;
                }

                // Add Flow columns to header
                const flowStatusHeader = document.createElement('th');
                flowStatusHeader.textContent = 'Flow Status';
                flowStatusHeader.setAttribute('data-flow-column', 'status');
                flowStatusHeader.style.padding = '8px';
                flowStatusHeader.style.textAlign = 'left';

                const flowIdHeader = document.createElement('th');
                flowIdHeader.textContent = 'Flow ID';
                flowIdHeader.setAttribute('data-flow-column', 'id');
                flowIdHeader.style.padding = '8px';
                flowIdHeader.style.textAlign = 'left';

                const flowUrlHeader = document.createElement('th');
                flowUrlHeader.textContent = 'Flow Dashboard';
                flowUrlHeader.setAttribute('data-flow-column', 'url');
                flowUrlHeader.style.padding = '8px';
                flowUrlHeader.style.textAlign = 'left';

                headerRow.appendChild(flowStatusHeader);
                headerRow.appendChild(flowIdHeader);
                headerRow.appendChild(flowUrlHeader);

                console.log('✅ Flow DOM: Added columns to header');

                // Now process the data rows
                processTableRows(table);

                return true;
            }
        } catch (error) {
            console.error('❌ Flow DOM: Error adding columns to header:', error);
        }
        return false;
    }

    /**
     * Process data rows in the table
     */
    function processTableRows(table) {
        if (isProcessing) return;
        isProcessing = true;

        try {
            const rows = table.querySelectorAll('tbody tr, tr:not(:first-child)');
            console.log(`📊 Flow DOM: Processing ${rows.length} table rows`);

            rows.forEach((row, index) => {
                if (row.querySelector('[data-flow-column]')) {
                    return; // Already processed
                }

                const rowId = `row-${index}-${Date.now()}`;
                if (processedRows.has(rowId)) {
                    return;
                }
                processedRows.add(rowId);

                // Extract customer info from the row
                const customerInfo = extractCustomerInfo(row);
                if (customerInfo) {
                    addFlowColumnsToRow(row, customerInfo);
                }
            });
        } catch (error) {
            console.error('❌ Flow DOM: Error processing rows:', error);
        } finally {
            isProcessing = false;
        }
    }

    /**
     * Extract customer information from a table row
     */
    function extractCustomerInfo(row) {
        try {
            const cells = row.querySelectorAll('td');
            if (cells.length === 0) return null;

            let customerId = null;
            let customerEmail = null;
            let customerName = null;

            // Try to find customer ID in various ways
            for (let cell of cells) {
                const cellText = cell.textContent.trim();

                // Look for email
                if (cellText.includes('@') && !customerEmail) {
                    customerEmail = cellText;
                }

                // Look for customer name (usually in first or second column)
                if (!customerName && cellText.length > 0 && !cellText.includes('@') &&
                    !cellText.match(/^\d+$/) && cellText.length < 50) {
                    customerName = cellText;
                }

                // Look for customer ID (numbers)
                const links = cell.querySelectorAll('a');
                for (let link of links) {
                    const href = link.getAttribute('href');
                    if (href && href.includes('user_id=')) {
                        const match = href.match(/user_id=(\d+)/);
                        if (match) {
                            customerId = match[1];
                        }
                    }
                    if (href && href.includes('id=')) {
                        const match = href.match(/[?&]id=(\d+)/);
                        if (match) {
                            customerId = match[1];
                        }
                    }
                }

                // Look for data attributes
                const dataId = cell.getAttribute('data-customer-id') ||
                              cell.getAttribute('data-id') ||
                              cell.getAttribute('data-user-id');
                if (dataId && !customerId) {
                    customerId = dataId;
                }
            }

            console.log('📋 Flow DOM: Extracted info:', { customerId, customerEmail, customerName });

            return {
                id: customerId,
                email: customerEmail,
                name: customerName
            };
        } catch (error) {
            console.error('❌ Flow DOM: Error extracting customer info:', error);
            return null;
        }
    }

    /**
     * Add Flow columns to a data row
     */
    function addFlowColumnsToRow(row, customerInfo) {
        try {
            // Add placeholder cells first
            const statusCell = document.createElement('td');
            statusCell.setAttribute('data-flow-column', 'status');
            statusCell.style.padding = '8px';
            statusCell.innerHTML = '<span style="color: #999;">Loading...</span>';

            const idCell = document.createElement('td');
            idCell.setAttribute('data-flow-column', 'id');
            idCell.style.padding = '8px';
            idCell.innerHTML = '<span style="color: #999;">Loading...</span>';

            const urlCell = document.createElement('td');
            urlCell.setAttribute('data-flow-column', 'url');
            urlCell.style.padding = '8px';
            urlCell.innerHTML = '<span style="color: #999;">Loading...</span>';

            row.appendChild(statusCell);
            row.appendChild(idCell);
            row.appendChild(urlCell);

            // Fetch Flow data for this customer
            fetchFlowDataForCustomer(customerInfo, statusCell, idCell, urlCell);

        } catch (error) {
            console.error('❌ Flow DOM: Error adding columns to row:', error);
        }
    }

    /**
     * Fetch Flow data via AJAX
     */
    function fetchFlowDataForCustomer(customerInfo, statusCell, idCell, urlCell) {
        const cacheKey = customerInfo.id || customerInfo.email;
        if (!cacheKey) {
            updateCellsWithNoData(statusCell, idCell, urlCell);
            return;
        }

        // Check cache first
        if (customerDataCache[cacheKey]) {
            updateCells(customerDataCache[cacheKey], statusCell, idCell, urlCell);
            return;
        }

        // Make AJAX request
        const ajaxData = {
            action: 'flow_get_customer_data',
            customer_id: customerInfo.id,
            customer_email: customerInfo.email,
            nonce: window.flowCustomerData?.nonce || ''
        };

        console.log('📡 Flow DOM: Fetching data for customer:', cacheKey);

        fetch(window.flowCustomerData?.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(ajaxData)
        })
        .then(response => response.json())
        .then(data => {
            console.log('📡 Flow DOM: Received data:', data);

            if (data.success && data.data) {
                customerDataCache[cacheKey] = data.data;
                updateCells(data.data, statusCell, idCell, urlCell);
            } else {
                updateCellsWithNoData(statusCell, idCell, urlCell);
            }
        })
        .catch(error => {
            console.error('❌ Flow DOM: AJAX error:', error);
            updateCellsWithError(statusCell, idCell, urlCell);
        });
    }

    /**
     * Update cells with Flow data
     */
    function updateCells(data, statusCell, idCell, urlCell) {
        try {
            // Status cell
            const status = data.status || 'inactive';
            let statusColor = '#999';
            let statusText = 'Sin suscripción';

            switch (status.toLowerCase()) {
                case 'active':
                case 'activa':
                    statusColor = '#28a745';
                    statusText = 'Activa';
                    break;
                case 'inactive':
                case 'inactiva':
                    statusColor = '#dc3545';
                    statusText = 'Inactiva';
                    break;
                case 'pending':
                case 'pendiente':
                    statusColor = '#ffc107';
                    statusText = 'Pendiente';
                    break;
            }

            statusCell.innerHTML = `<span style="color: ${statusColor}; font-weight: bold;">${statusText}</span>`;

            // ID cell
            if (data.flow_subscription_id) {
                idCell.innerHTML = `<code style="background: #f1f1f1; padding: 2px 4px; font-size: 11px;">${data.flow_subscription_id}</code>`;
            } else {
                idCell.innerHTML = '<span style="color: #999;">-</span>';
            }

            // URL cell
            if (data.flow_subscription_id) {
                const isProduction = window.flowCustomerData?.isProduction || false;
                const baseUrl = isProduction ? 'https://dashboard.flow.cl' : 'https://dashboard.sandbox.flow.cl';
                const dashboardUrl = baseUrl + '/private/suscripciones/subscriptions/details/?sus_id=' +
                                   encodeURIComponent(data.flow_subscription_id);

                urlCell.innerHTML = `<a href="${dashboardUrl}" target="_blank" style="color: #0073aa; text-decoration: none;">
                    📊 Dashboard
                </a>`;
            } else {
                urlCell.innerHTML = '<span style="color: #999;">-</span>';
            }

        } catch (error) {
            console.error('❌ Flow DOM: Error updating cells:', error);
            updateCellsWithError(statusCell, idCell, urlCell);
        }
    }

    /**
     * Update cells when no data is available
     */
    function updateCellsWithNoData(statusCell, idCell, urlCell) {
        statusCell.innerHTML = '<span style="color: #999;">Sin suscripción</span>';
        idCell.innerHTML = '<span style="color: #999;">-</span>';
        urlCell.innerHTML = '<span style="color: #999;">-</span>';
    }

    /**
     * Update cells when there's an error
     */
    function updateCellsWithError(statusCell, idCell, urlCell) {
        statusCell.innerHTML = '<span style="color: #dc3545; font-size: 12px;">Error</span>';
        idCell.innerHTML = '<span style="color: #dc3545; font-size: 12px;">Error</span>';
        urlCell.innerHTML = '<span style="color: #dc3545; font-size: 12px;">Error</span>';
    }

    /**
     * Main function to scan and modify tables
     */
    function scanAndModifyTables() {
        try {
            console.log('🔍 Flow DOM: Scanning for customer tables...');

            if (addFlowColumnsToHeader()) {
                console.log('✅ Flow DOM: Successfully modified table');
            } else {
                console.log('ℹ️ Flow DOM: No suitable table found, will retry...');
            }
        } catch (error) {
            console.error('❌ Flow DOM: Error in scanAndModifyTables:', error);
        }
    }

    /**
     * Initialize the DOM manipulation
     */
    function init() {
        console.log('🚀 Flow DOM: Initializing...');

        // Try immediately
        scanAndModifyTables();

        // Try with delays for React loading
        setTimeout(scanAndModifyTables, 2000);
        setTimeout(scanAndModifyTables, 5000);
        setTimeout(scanAndModifyTables, 10000);

        // Set up observer for dynamic content
        try {
            const observer = new MutationObserver(function(mutations) {
                let shouldRescan = false;

                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        for (let node of mutation.addedNodes) {
                            if (node.nodeType === Node.ELEMENT_NODE) {
                                if (node.tagName === 'TABLE' ||
                                    node.querySelector && node.querySelector('table')) {
                                    shouldRescan = true;
                                    break;
                                }
                            }
                        }
                    }
                });

                if (shouldRescan) {
                    console.log('🔄 Flow DOM: Table changes detected, rescanning...');
                    setTimeout(scanAndModifyTables, 1000);
                }
            });

            if (document.body) {
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
                console.log('✅ Flow DOM: Observer started');
            }
        } catch (error) {
            console.error('❌ Flow DOM: Error setting up observer:', error);
        }

        // Monitor URL changes for React routing
        try {
            let lastUrl = location.href;
            setInterval(() => {
                const currentUrl = location.href;
                if (currentUrl !== lastUrl) {
                    lastUrl = currentUrl;
                    if (currentUrl.includes('customers')) {
                        console.log('🔄 Flow DOM: URL changed to customers, rescanning...');
                        processedRows.clear();
                        customerDataCache = {};
                        setTimeout(scanAndModifyTables, 1500);
                    }
                }
            }, 1000);
        } catch (error) {
            console.error('❌ Flow DOM: Error setting up URL monitoring:', error);
        }
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    console.log('✅ Flow DOM: Script loaded successfully');

})();