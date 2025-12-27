const { addFilter } = wp.hooks;

addFilter(
  'woocommerce_admin_report_table',
  'flow/add-customer-columns',
  (table) => {
    if (!table || table.endpoint !== 'customers') {
      return table;
    }

    // wc-admin suele usar `headers`; en algunas versiones puede existir `columns`
    const headers = [...table.headers];

    // Evita duplicados si wc-admin re-renderiza
    if (!headers.some((h) => h.key === 'flow_subscription_id')) {
      headers.push(
        { key: 'flow_subscription_id', label: 'Flow Subscription ID' },
        { key: 'flow_customer_id', label: 'Flow Customer ID' },
        { key: 'flow_subscription_status', label: 'Flow Status' }
      );
    }

    return { ...table, headers };
  }
);

addFilter(
  'woocommerce_admin_report_cell',
  'flow/render-customer-cells',
  (value, row, column) => {
    if (!column || !column.key) return value;

    if (column.key === 'flow_subscription_id') {
      return row?.flow_subscription_id ?? '—';
    }
    if (column.key === 'flow_customer_id') {
      return row?.flow_customer_id ?? '—';
    }
    if (column.key === 'flow_subscription_status') {
      return row?.flow_subscription_status ?? '—';
    }
    return value;
  }
);
