/**
 * Agregar columnas Flow a WooCommerce → Clientes
 * Archivo: flow-customers-columns.js
 */

// Verificar que las dependencias estén disponibles
if ( typeof wp === 'undefined' || typeof wp.hooks === 'undefined' ) {
    console.error( '❌ Flow: wp.hooks no está disponible' );
} else {
    console.log( '✓ Flow: wp.hooks está disponible' );
}

( function( wp ) {
    // Verificar que addFilter existe
    if ( !wp.hooks || !wp.hooks.addFilter ) {
        console.error( '❌ Flow: addFilter no está disponible' );
        return;
    }

    const { addFilter } = wp.hooks;
    const { __ } = wp.i18n;

    /**
     * Agregar columnas Flow a la tabla de clientes
     */
    console.log( '→ Flow: Intentando registrar columnas...' );

    const columnsAdded = addFilter(
        'woocommerce_admin_customers_report_columns',
        'flow-plugin/custom-columns',
        function( columns ) {
            console.log( '→ Flow: Filtro de columnas ejecutado', columns );

            const newColumns = [
                ...columns,
                {
                    key: 'flow_subscription_id',
                    label: __( 'Flow Subscription ID', 'tu-plugin' ),
                    isLeftAligned: true,
                    required: false,
                    isSortable: false,
                },
                {
                    key: 'flow_subscription_status',
                    label: __( 'Flow Status', 'tu-plugin' ),
                    isLeftAligned: true,
                    required: false,
                    isSortable: false,
                },
                {
                    key: 'flow_subscription_url',
                    label: __( 'Flow Dashboard', 'tu-plugin' ),
                    isLeftAligned: true,
                    required: false,
                    isSortable: false,
                }
            ];

            console.log( '✓ Flow: Columnas agregadas', newColumns );
            return newColumns;
        }
    );

    console.log( '✓ Flow: addFilter registrado', columnsAdded );

    /**
     * Formatear los datos de las columnas Flow
     */
    addFilter(
        'woocommerce_admin_report_table',
        'flow-plugin/custom-columns',
        function( reportTableData ) {
            console.log( '→ Flow: Procesando datos de tabla', reportTableData );

            // Solo aplicar en la página de clientes
            if ( reportTableData.endpoint !== 'customers' ) {
                return reportTableData;
            }

            // Verificar que tenemos datos
            if ( !reportTableData.rows || !Array.isArray( reportTableData.rows ) ) {
                console.log( '❌ Flow: No hay datos de filas', reportTableData );
                return reportTableData;
            }

            const newRows = reportTableData.rows.map( function( row ) {
                console.log( '→ Flow: Procesando fila', row );

                // row es un array de objetos con estructura { display: valor, value: valor }
                return row.map( function( cell, cellIndex ) {

                    // Buscar las columnas de Flow por índice o por contenido
                    const headers = reportTableData.headers || [];
                    const currentHeader = headers[ cellIndex ];

                    if ( currentHeader ) {
                        console.log( '→ Flow: Procesando celda', currentHeader.key, cell );

                        // Formatear Flow Subscription ID
                        if ( currentHeader.key === 'flow_subscription_id' ) {
                            const flowId = cell.value || cell.display;
                            console.log( '→ Flow: Formateando Subscription ID', flowId );

                            return {
                                ...cell,
                                display: flowId ? `<code>${flowId}</code>` : '<span style="color: #999;">—</span>',
                                value: flowId || ''
                            };
                        }

                        // Formatear Flow Subscription Status con colores
                        if ( currentHeader.key === 'flow_subscription_status' ) {
                            const status = cell.value || cell.display;
                            console.log( '→ Flow: Formateando Status', status );

                            if ( !status || status === '' ) {
                                return {
                                    ...cell,
                                    display: '<span style="color: #999;">Sin suscripción</span>',
                                    value: ''
                                };
                            }

                            // Definir colores según el estado
                            let badgeColor = '#72aee6'; // azul por defecto
                            let textColor = '#fff';
                            let displayText = status;
                            let emoji = '';

                            const statusLower = status.toLowerCase();

                            if ( statusLower === 'active' || statusLower === 'activa' ) {
                                badgeColor = '#46b450'; // verde
                                displayText = 'Activa';
                                emoji = '✓ ';
                            } else if ( statusLower === 'inactive' || statusLower === 'inactiva' ) {
                                badgeColor = '#dc3232'; // rojo
                                displayText = 'Inactiva';
                                emoji = '✗ ';
                            } else if ( statusLower === 'pending' || statusLower === 'pendiente' ) {
                                badgeColor = '#ffb900'; // amarillo
                                textColor = '#2c3338';
                                displayText = 'Pendiente';
                                emoji = '⏳ ';
                            } else if ( statusLower === 'cancelled' || statusLower === 'cancelada' ) {
                                badgeColor = '#dc3232'; // rojo
                                displayText = 'Cancelada';
                                emoji = '✗ ';
                            }

                            // Crear badge HTML
                            const badge = `<span style="
                                display: inline-block;
                                padding: 4px 8px;
                                border-radius: 4px;
                                font-size: 11px;
                                font-weight: bold;
                                background: ${badgeColor};
                                color: ${textColor};
                                white-space: nowrap;
                            ">${emoji}${displayText}</span>`;

                            return {
                                ...cell,
                                display: badge,
                                value: status
                            };
                        }

                        // Formatear Flow Subscription URL
                        if ( currentHeader.key === 'flow_subscription_url' ) {
                            const subscriptionId = getSubscriptionIdFromRow( row, headers );
                            console.log( '→ Flow: Formateando URL para subscription ID', subscriptionId );

                            if ( !subscriptionId || subscriptionId === '' ) {
                                return {
                                    ...cell,
                                    display: '<span style="color: #999;">—</span>',
                                    value: ''
                                };
                            }

                            const dashboardUrl = generateFlowDashboardUrl( subscriptionId );
                            const linkHtml = `<a href="${dashboardUrl}" target="_blank" style="
                                color: #0073aa;
                                text-decoration: none;
                                display: inline-flex;
                                align-items: center;
                                gap: 4px;
                            ">
                                <span class="dashicons dashicons-external" style="font-size: 16px;"></span>
                                Ver Dashboard
                            </a>`;

                            return {
                                ...cell,
                                display: linkHtml,
                                value: dashboardUrl
                            };
                        }
                    }

                    return cell;
                } );
            } );

            console.log( '✓ Flow: Datos procesados', newRows );

            return {
                ...reportTableData,
                rows: newRows
            };
        }
    );

    /**
     * También intentar con el filtro de datos de clientes
     */
    addFilter(
        'woocommerce_admin_customers_report_table',
        'flow-plugin/customers-data',
        function( tableData ) {
            console.log( '→ Flow: Procesando tabla de clientes específica', tableData );

            if ( tableData && tableData.rows ) {
                tableData.rows = tableData.rows.map( function( row ) {
                    // Buscar y formatear datos de Flow en cada fila
                    return row.map( function( cell, index ) {
                        if ( tableData.headers && tableData.headers[ index ] ) {
                            const header = tableData.headers[ index ];

                            if ( header.key === 'flow_subscription_status' ) {
                                const status = cell.value || cell.display;
                                if ( status ) {
                                    const formattedStatus = formatFlowStatus( status );
                                    return {
                                        ...cell,
                                        display: formattedStatus,
                                        value: status
                                    };
                                }
                            }

                            if ( header.key === 'flow_subscription_id' ) {
                                const id = cell.value || cell.display;
                                if ( id ) {
                                    return {
                                        ...cell,
                                        display: `<code>${id}</code>`,
                                        value: id
                                    };
                                }
                            }
                        }

                        return cell;
                    } );
                } );
            }

            return tableData;
        }
    );

    /**
     * Helper function to get subscription ID from a row
     */
    function getSubscriptionIdFromRow( row, headers ) {
        if ( !row || !headers ) return null;

        // Find the subscription ID column index
        const subscriptionIdIndex = headers.findIndex( header =>
            header && header.key === 'flow_subscription_id'
        );

        if ( subscriptionIdIndex === -1 || !row[ subscriptionIdIndex ] ) return null;

        const subscriptionCell = row[ subscriptionIdIndex ];
        return subscriptionCell.value || subscriptionCell.display || null;
    }

    /**
     * Helper function to generate Flow dashboard URL
     */
    function generateFlowDashboardUrl( subscriptionId ) {
        if ( !subscriptionId ) return null;

        // Determine environment - check if production constant is defined
        const isProduction = window.flowCustomerData && window.flowCustomerData.isProduction;
        const baseUrl = isProduction ?
            'https://dashboard.flow.cl' :
            'https://dashboard.sandbox.flow.cl';

        return `${baseUrl}/private/suscripciones/subscriptions/details/?sus_id=${encodeURIComponent(subscriptionId)}`;
    }

    /**
     * Función auxiliar para formatear el estado de Flow
     */
    function formatFlowStatus( status ) {
        if ( !status ) return '<span style="color: #999;">—</span>';

        const statusLower = status.toLowerCase();
        let color = '#999';
        let text = status;
        let emoji = '';

        if ( statusLower === 'active' || statusLower === 'activa' ) {
            color = '#46b450';
            text = 'Activa';
            emoji = '✓ ';
        } else if ( statusLower === 'inactive' || statusLower === 'inactiva' ) {
            color = '#dc3232';
            text = 'Inactiva';
            emoji = '✗ ';
        } else if ( statusLower === 'pending' || statusLower === 'pendiente' ) {
            color = '#ffb900';
            text = 'Pendiente';
            emoji = '⏳ ';
        }

        return `<span style="color: ${color}; font-weight: bold;">${emoji}${text}</span>`;
    }

    /**
     * Fallback: Intento directo de modificación del DOM
     */
    function tryDOMModification() {
        console.log( '→ Flow: Intentando modificación directa del DOM' );

        // Buscar tablas existentes
        const tables = document.querySelectorAll( 'table, .woocommerce-table, [class*="table"]' );

        tables.forEach( function( table ) {
            // Verificar si parece ser la tabla de clientes
            const headerText = table.textContent || '';
            if ( headerText.includes( 'Customer' ) || headerText.includes( 'Email' ) || headerText.includes( 'Orders' ) ) {
                console.log( '→ Flow: Tabla de clientes encontrada', table );

                // Verificar si ya tiene columnas Flow
                if ( !table.querySelector( '.flow-column-added' ) ) {
                    addFlowColumnsToDOM( table );
                }
            }
        } );
    }

    function addFlowColumnsToDOM( table ) {
        console.log( '→ Flow: Agregando columnas al DOM', table );

        // Buscar la fila de encabezados
        const headerRow = table.querySelector( 'thead tr, tr:first-child' );
        if ( headerRow && !headerRow.querySelector( '.flow-status-header' ) ) {
            // Agregar encabezado de estado
            const statusHeader = document.createElement( 'th' );
            statusHeader.className = 'flow-status-header flow-column-added';
            statusHeader.textContent = 'Flow Status';
            statusHeader.style.cssText = 'background: #f0f0f0; font-weight: bold; padding: 8px; border: 1px solid #ddd;';

            // Agregar encabezado de ID
            const idHeader = document.createElement( 'th' );
            idHeader.className = 'flow-id-header flow-column-added';
            idHeader.textContent = 'Flow Subscription';
            idHeader.style.cssText = 'background: #f0f0f0; font-weight: bold; padding: 8px; border: 1px solid #ddd;';

            // Agregar encabezado de URL
            const urlHeader = document.createElement( 'th' );
            urlHeader.className = 'flow-url-header flow-column-added';
            urlHeader.textContent = 'Flow Dashboard';
            urlHeader.style.cssText = 'background: #f0f0f0; font-weight: bold; padding: 8px; border: 1px solid #ddd;';

            headerRow.appendChild( statusHeader );
            headerRow.appendChild( idHeader );
            headerRow.appendChild( urlHeader );

            console.log( '✓ Flow: Encabezados agregados al DOM' );
        }

        // Agregar celdas de datos
        const dataRows = table.querySelectorAll( 'tbody tr, tr:not(:first-child)' );
        dataRows.forEach( function( row, index ) {
            if ( !row.querySelector( '.flow-status-cell' ) && index !== 0 ) {
                const statusCell = document.createElement( 'td' );
                statusCell.className = 'flow-status-cell flow-column-added';
                statusCell.innerHTML = '<span style="color: #999;">Cargando...</span>';

                const idCell = document.createElement( 'td' );
                idCell.className = 'flow-id-cell flow-column-added';
                idCell.innerHTML = '<span style="color: #999;">Cargando...</span>';

                const urlCell = document.createElement( 'td' );
                urlCell.className = 'flow-url-cell flow-column-added';
                urlCell.innerHTML = '<span style="color: #999;">Cargando...</span>';

                row.appendChild( statusCell );
                row.appendChild( idCell );
                row.appendChild( urlCell );

                // Intentar obtener ID del cliente
                const links = row.querySelectorAll( 'a[href*="user-edit.php"]' );
                if ( links.length > 0 ) {
                    const href = links[0].getAttribute( 'href' );
                    const userIdMatch = href.match( /user_id=(\d+)/ );
                    if ( userIdMatch ) {
                        const customerId = userIdMatch[1];
                        fetchFlowDataForCells( customerId, statusCell, idCell, urlCell );
                    }
                }
            }
        } );
    }

    function fetchFlowDataForCells( customerId, statusCell, idCell, urlCell ) {
        console.log( '→ Flow: Fetching data for customer ID:', customerId );

        if ( !window.flowCustomerData ) {
            console.error( '❌ Flow: flowCustomerData not available' );
            statusCell.innerHTML = '<span style="color: #999;">Config Error</span>';
            idCell.innerHTML = '<span style="color: #999;">Config Error</span>';
            if ( urlCell ) urlCell.innerHTML = '<span style="color: #999;">Config Error</span>';
            return;
        }

        console.log( '→ Flow: AJAX URL:', window.flowCustomerData.ajaxUrl );
        console.log( '→ Flow: Nonce available:', !!window.flowCustomerData.nonce );

        const formData = new FormData();
        formData.append( 'action', 'get_flow_customer_data' );
        formData.append( 'customer_id', customerId );
        formData.append( 'nonce', window.flowCustomerData.nonce );

        // Log the request data
        console.log( '→ Flow: Sending AJAX request with data:', {
            action: 'get_flow_customer_data',
            customer_id: customerId,
            nonce: window.flowCustomerData.nonce ? 'present' : 'missing'
        } );

        fetch( window.flowCustomerData.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        } )
        .then( response => {
            console.log( '→ Flow: Response status:', response.status );
            console.log( '→ Flow: Response headers:', response.headers );

            if ( !response.ok ) {
                throw new Error( `HTTP ${response.status}: ${response.statusText}` );
            }

            return response.text().then( text => {
                console.log( '→ Flow: Raw response:', text );
                try {
                    return JSON.parse( text );
                } catch ( e ) {
                    throw new Error( 'Invalid JSON response: ' + text );
                }
            } );
        } )
        .then( data => {
            console.log( '→ Flow: Parsed response:', data );

            if ( data.success ) {
                console.log( '✓ Flow: Success response:', data.data );
                statusCell.innerHTML = data.data.status_html || formatFlowStatus( data.data.status );
                idCell.innerHTML = data.data.flow_subscription_id ?
                    `<code>${data.data.flow_subscription_id}</code>` :
                    '<span style="color: #999;">—</span>';

                // Handle URL cell
                if ( urlCell ) {
                    if ( data.data.flow_subscription_id ) {
                        const dashboardUrl = generateFlowDashboardUrl( data.data.flow_subscription_id );
                        urlCell.innerHTML = `<a href="${dashboardUrl}" target="_blank" style="
                            color: #0073aa;
                            text-decoration: none;
                            display: inline-flex;
                            align-items: center;
                            gap: 4px;
                        ">
                            <span class="dashicons dashicons-external" style="font-size: 16px;"></span>
                            Ver Dashboard
                        </a>`;
                    } else {
                        urlCell.innerHTML = '<span style="color: #999;">—</span>';
                    }
                }
            } else {
                console.error( '❌ Flow: Error response:', data );
                statusCell.innerHTML = '<span style="color: #dc3232;">Error: ' + ( data.data || 'Unknown' ) + '</span>';
                idCell.innerHTML = '<span style="color: #dc3232;">Error</span>';
                if ( urlCell ) urlCell.innerHTML = '<span style="color: #dc3232;">Error</span>';
            }
        } )
        .catch( error => {
            console.error( '❌ Flow: Fetch error:', error );
            statusCell.innerHTML = '<span style="color: #dc3232;">Network Error</span>';
            idCell.innerHTML = '<span style="color: #dc3232;">Network Error</span>';
            if ( urlCell ) urlCell.innerHTML = '<span style="color: #dc3232;">Network Error</span>';
        } );
    }

    // Monitorear cambios en el DOM
    function observeChanges() {
        const observer = new MutationObserver( function( mutations ) {
            mutations.forEach( function( mutation ) {
                mutation.addedNodes.forEach( function( node ) {
                    if ( node.nodeType === 1 && (
                        node.tagName === 'TABLE' ||
                        ( node.querySelector && node.querySelector( 'table' ) ) ||
                        ( node.className && node.className.includes && node.className.includes( 'table' ) )
                    ) ) {
                        console.log( '→ Flow: Nueva tabla detectada por observer' );
                        setTimeout( tryDOMModification, 100 );
                    }
                } );
            } );
        } );

        observer.observe( document.body, {
            childList: true,
            subtree: true
        } );

        console.log( '✓ Flow: Observer de mutaciones iniciado' );
    }

    // Inicializar todo cuando el DOM esté listo
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function() {
            setTimeout( tryDOMModification, 1000 );
            setTimeout( tryDOMModification, 3000 );
            observeChanges();
        } );
    } else {
        setTimeout( tryDOMModification, 1000 );
        setTimeout( tryDOMModification, 3000 );
        observeChanges();
    }

    // También intentar cada 5 segundos por si acaso
    setInterval( function() {
        if ( window.location.pathname.includes( 'wc-admin' ) && window.location.search.includes( 'customers' ) ) {
            tryDOMModification();
        }
    }, 5000 );

    console.log( '✓ Flow: Columnas personalizadas cargadas en WooCommerce → Clientes' );

} )( window.wp );