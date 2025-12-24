/**
 * Simple Flow Columns for WooCommerce React Admin
 * Minimal implementation with maximum error handling
 */

(function() {
    'use strict';

    console.log('🚀 Flow Simple: Starting initialization');

    // Simple retry mechanism
    let attempts = 0;
    const maxAttempts = 20;

    function addFlowColumns() {
        attempts++;
        console.log(`🔄 Flow Simple: Attempt ${attempts}/${maxAttempts}`);

        // Check if wp.hooks is available
        if (typeof wp === 'undefined' || !wp.hooks || !wp.hooks.addFilter) {
            console.log('❌ Flow Simple: wp.hooks not available yet');

            if (attempts < maxAttempts) {
                setTimeout(addFlowColumns, 1000);
            }
            return;
        }

        console.log('✅ Flow Simple: wp.hooks available, adding filters');

        try {
            // Add the main filter for customer columns
            wp.hooks.addFilter(
                'woocommerce_admin_customers_report_columns',
                'flow-simple/customers-columns',
                function(columns) {
                    console.log('🎯 Flow Simple: Filter triggered with columns:', columns);

                    // Add Flow columns if not already present
                    const hasFlowColumns = Array.isArray(columns) &&
                        columns.some(col => col.key && col.key.startsWith('flow_'));

                    if (!hasFlowColumns) {
                        const flowColumns = [
                            {
                                key: 'flow_subscription_status',
                                label: 'Flow Status',
                                isLeftAligned: true,
                                required: false,
                                isSortable: false
                            },
                            {
                                key: 'flow_subscription_id',
                                label: 'Flow Subscription ID',
                                isLeftAligned: true,
                                required: false,
                                isSortable: false
                            },
                            {
                                key: 'flow_subscription_url',
                                label: 'Flow Dashboard',
                                isLeftAligned: true,
                                required: false,
                                isSortable: false
                            }
                        ];

                        if (Array.isArray(columns)) {
                            columns.push(...flowColumns);
                        } else {
                            // Object format fallback
                            columns = columns || {};
                            columns.flow_subscription_status = 'Flow Status';
                            columns.flow_subscription_id = 'Flow Subscription ID';
                            columns.flow_subscription_url = 'Flow Dashboard';
                        }

                        console.log('✅ Flow Simple: Added columns successfully', columns);
                    } else {
                        console.log('ℹ️ Flow Simple: Flow columns already present');
                    }

                    return columns;
                }
            );

            // Add data filter
            wp.hooks.addFilter(
                'woocommerce_admin_customers_report_column_data',
                'flow-simple/customers-data',
                function(value, column, item) {
                    try {
                        if (column === 'flow_subscription_status') {
                            return item.flow_subscription_status || 'Sin suscripción';
                        }
                        if (column === 'flow_subscription_id') {
                            return item.flow_subscription_id || '';
                        }
                        if (column === 'flow_subscription_url') {
                            const subscriptionId = item.flow_subscription_id;
                            if (subscriptionId) {
                                const baseUrl = 'https://dashboard.sandbox.flow.cl';
                                return baseUrl + '/private/suscripciones/subscriptions/details/?sus_id=' +
                                       encodeURIComponent(subscriptionId);
                            }
                            return '';
                        }
                    } catch (error) {
                        console.error('❌ Flow Simple: Error in data filter:', error);
                    }
                    return value;
                }
            );

            console.log('🎉 Flow Simple: All filters added successfully!');

        } catch (error) {
            console.error('❌ Flow Simple: Error adding filters:', error);

            // Retry on error
            if (attempts < maxAttempts) {
                setTimeout(addFlowColumns, 2000);
            }
        }
    }

    // Start immediately
    addFlowColumns();

    // Also try when page loads
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addFlowColumns);
    } else {
        setTimeout(addFlowColumns, 500);
    }

    console.log('✅ Flow Simple: Initialization complete');

})();