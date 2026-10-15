import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { initQuotaEditor, quotaGroups } from '../webroot/assets/common/js/quota-editor.js';

const age = { source:'survey_201.age',run_unit_id:201,item_name:'age',type:'integer',categories:{g1:{min:18,max:29},g2:{min:30,max:39}} };
const sex = { source:'survey_201.sex',run_unit_id:201,item_name:'sex',type:'category',categories:{c1:'1',c2:'2'} };
const education = { source:'survey_201.education',run_unit_id:201,item_name:'education',type:'category',categories:{c1:'uni'} };
const tables = () => ({ age_sex:{dimensions:[structuredClone(age),structuredClone(sex)],limits:{}}, education:{dimensions:[structuredClone(education)],limits:{c1:5}} });

function fixture(saved = tables(), changeSources = () => {}) {
  const { document, window } = parseHTML('<main><div class="quota-editor"><input name="quota_tables" type="hidden"><div class="quota-editor-error" hidden></div><div class="quota-editor-tables"></div><button class="quota-add-table">Add table</button></div></main>');
  globalThis.document = document;
  globalThis.Event = window.Event;
  const sources = Object.fromEntries([age,sex,education].map((dim) => [dim.source,{...dim,supported:true,label:dim.item_name,survey_label:'Demography',choices:dim.type==='category'
    ? Object.values(dim.categories).map((value)=>({value,label:value==='2'?'Female':value})) : []}]));
  changeSources(sources);
  const root = document.querySelector('.quota-editor');
  root.dataset.sources = JSON.stringify(sources);
  const hidden = root.querySelector('[name=quota_tables]');
  hidden.value = JSON.stringify(saved);
  const editor = initQuotaEditor(document.querySelector('main'));
  return { document,root,hidden,editor,window };
}

test('two dimensions produce four stable cross-product codes', () => {
  assert.deepEqual(quotaGroups([age,sex]).map((group)=>group.code),['g1|c1','g1|c2','g2|c1','g2|c2']);
});

test('inclusive overlapping ranges and decimals are rejected', () => {
  const changed = structuredClone(age);
  changed.categories.g2.min=29;
  assert.throws(()=>quotaGroups([changed]),/overlap/);
  changed.categories.g2.min=29.5;
  assert.throws(()=>quotaGroups([changed]),/whole numbers/);
});

test('age by sex plus independent education survive editor serialization', () => {
  const { editor,hidden,root } = fixture();
  assert.equal(editor.sync(),true);
  const saved=JSON.parse(hidden.value);
  assert.equal(saved.education.limits.c1,5);
  assert.equal(Object.keys(saved.age_sex.limits).length,4);
  assert.deepEqual(Object.values(saved.age_sex.limits),[0,0,0,0]);
  assert.match(root.textContent,/Female/);
});

test('invalid limits cannot overwrite the last serialized definition', () => {
  const { root,editor,hidden,window } = fixture();
  assert.equal(editor.sync(),true);
  const previous=hidden.value;
  const field=root.querySelector('.quota-groups input');
  field.value='-1';field.dispatchEvent(new window.Event('change'));
  assert.equal(editor.sync(),false);
  assert.equal(hidden.value,previous);
  assert.match(root.querySelector('.quota-editor-error').textContent,/nonnegative/);
});

test('category toggles keep stable codes and never overwrite another selected category', () => {
  const saved=tables();saved.age_sex.dimensions[1].categories={c1:'2'};
  const { root,editor,hidden,window }=fixture(saved);
  const male=root.querySelector('input[type=checkbox]');
  male.checked=true;male.dispatchEvent(new window.Event('change'));
  assert.equal(editor.sync(),true);
  assert.deepEqual(JSON.parse(hidden.value).age_sex.dimensions[1].categories,{c1:'2',c2:'1'});
  male.checked=false;male.dispatchEvent(new window.Event('change'));
  assert.equal(editor.sync(),true);
  assert.deepEqual(JSON.parse(hidden.value).age_sex.dimensions[1].categories,{c1:'2'});
});

test('missing or unsupported sources block save', () => {
  const { editor,root }=fixture(tables(),(sources)=>{sources[age.source].supported=false;});
  assert.equal(editor.sync(),false);
  assert.match(root.querySelector('.quota-editor-error').textContent,/unsupported/);
});

test('question and answer labels remain text instead of executable markup', () => {
  const { root }=fixture(tables(),(sources)=>{sources[sex.source].choices[1].label='<img src=x onerror=alert(1)>';});
  assert.equal(root.querySelectorAll('img').length,0);
  assert.match(root.textContent,/<img src=x onerror=alert\(1\)>/);
});

test('table codes cannot disappear through JavaScript prototype names', () => {
  const saved=JSON.parse('{"__proto__":{}}');saved.__proto__=tables().education;
  const { editor,hidden }=fixture(saved);
  assert.equal(editor.sync(),true);
  assert.equal(Object.hasOwn(JSON.parse(hidden.value),'__proto__'),true);
});

test('no categories are selected automatically for a new variable', () => {
  const { root,editor }=fixture({});
  root.querySelector('.quota-add-table').click();
  [...root.querySelectorAll('button')].find((button)=>button.textContent==='Add variable').click();
  assert.equal(root.querySelectorAll('input[type=checkbox]:checked').length,0);
  assert.equal(editor.sync(),false);
});

test('typing survives adding another range before a blur/change event', () => {
  const { root,window,editor,hidden }=fixture();
  const name=root.querySelector('input[type=text]');
  name.value='demography';name.dispatchEvent(new window.Event('input'));
  const from=root.querySelector('fieldset input[type=number]');
  from.value='19';from.dispatchEvent(new window.Event('input'));
  [...root.querySelectorAll('button')].find((button)=>button.textContent==='Add integer range').click();
  assert.equal(root.querySelector('input[type=text]').value,'demography');
  assert.equal(root.querySelector('fieldset input[type=number]').value,'19');
  assert.equal(editor.sync(),false);
});

test('changed group meaning resets its limit, while unchanged groups retain theirs', () => {
  const saved=tables();saved.age_sex.limits={'g1|c1':12,'g1|c2':13,'g2|c1':14,'g2|c2':15};
  const { root,window,editor,hidden }=fixture(saved);
  const from=root.querySelector('fieldset input[type=number]');
  from.value='19';from.dispatchEvent(new window.Event('input'));
  assert.equal(editor.sync(),true);
  assert.deepEqual(JSON.parse(hidden.value).age_sex.limits,{'g1|c1':0,'g1|c2':0,'g2|c1':14,'g2|c2':15});
});
