import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { initQuotaOverviewForm } from '../webroot/assets/common/js/quota-overview.js';

function fixture(size=1, table='income_party', groupPrefix='g') {
  const {document,window}=parseHTML('<form><input name="rows_json" type="hidden"><button type="submit" disabled>Save</button><table><tbody></tbody></table></form>');
  const form=document.querySelector('form');
  const body=form.querySelector('tbody');
  for (let i=1;i<=size;i++) {
    const row=document.createElement('tr');
    row.dataset.quotaTable=table; row.dataset.quotaGroup=groupPrefix+i;
    for (const [key,value] of Object.entries({limit:'10',base_limit:'10',count:'0',base_count:'0'})) {
      const input=document.createElement('input'); input.dataset.quotaField=key; input.value=value;
      row.append(input);
    }
    body.append(row);
  }
  initQuotaOverviewForm(form);
  const submit=()=>{
    form.dispatchEvent(new window.Event('submit',{cancelable:true}));
    return JSON.parse(form.querySelector('[name=rows_json]').value);
  };
  return {form,submit};
}

test('500 displayed groups submit as one JSON field',()=>{
  const {form,submit}=fixture(500);
  assert.equal(form.querySelector('button').disabled,false);
  assert.equal(form.querySelectorAll('[name]').length,1);
  const rows=submit();
  assert.equal(Object.keys(rows.income_party).length,500);
  assert.deepEqual(rows.income_party.g500,{limit:'10',base_limit:'10',count:'0',base_count:'0'});
});

test('submission reads the latest edits and preserves large integers as text',()=>{
  const {form,submit}=fixture();
  form.querySelector('[data-quota-field=count]').value='9007199254740993';
  assert.equal(submit().income_party.g1.count,'9007199254740993');
  form.querySelector('[data-quota-field=limit]').value='1.5';
  assert.equal(submit().income_party.g1.limit,'1.5'); // Server rejects it, never silently round.
  form.querySelector('[data-quota-field=limit]').value='15';
  assert.equal(submit().income_party.g1.limit,'15');
});

test('table and crossed group codes cannot become JavaScript prototype properties',()=>{
  const {submit}=fixture(1,'__proto__','constructor|c');
  assert.equal(Object.keys(submit())[0],'__proto__');
  assert.equal(submit().__proto__['constructor|c1'].limit,'10');
  assert.equal({}.limit,undefined);
});
