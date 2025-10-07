/**
 * Flow Payment Method Block Integration
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement } = window.wp.element;
const { __ } = window.wp.i18n;
const { getSetting } = window.wc.wcSettings;

// Get payment method data
const settings = getSetting('flow_data', {});

// Define the payment method
const FlowPaymentMethod = {
    name: 'flow',
    label: settings.title || __('Flow Suscripciones', 'flow-suscripciones'),
    content: createElement('div', {
        style: { padding: '10px' }
    }, settings.description || __('Paga de forma segura con Flow', 'flow-suscripciones')),
    edit: createElement('div', {
        style: { padding: '10px' }
    }, settings.description || __('Paga de forma segura con Flow', 'flow-suscripciones')),
    canMakePayment: () => true,
    ariaLabel: settings.title || __('Flow Suscripciones', 'flow-suscripciones'),
    supports: {
        features: settings.supports || ['products']
    }
};

// Register the payment method
registerPaymentMethod(FlowPaymentMethod);