/* Keep quota rows in one POST field, independent of PHP's max_input_vars setting. */
export function initQuotaOverviewForm(form) {
  const payload = form.querySelector('[name=rows_json]');
  form.addEventListener('submit', () => {
    const tables = Object.create(null);
    form.querySelectorAll('[data-quota-group]').forEach((row) => {
      const table = row.dataset.quotaTable;
      const group = row.dataset.quotaGroup;
      tables[table] ??= Object.create(null);
      const values = Object.create(null);
      row.querySelectorAll('[data-quota-field]').forEach((input) => {
        // Keep decimal-looking/large input as text; the server validates exact integers.
        values[input.dataset.quotaField] = input.value;
      });
      tables[table][group] = values;
    });
    payload.value = JSON.stringify(tables);
  });
  form.querySelector('button[type=submit]').disabled = false;
}
