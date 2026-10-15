<?php
// Synthetic, local-only browser fixture. Uses the actual editor template and module.
// Run with: php -S 127.0.0.1:8096 tests/fixtures/quota/editor_preview.php
$root = dirname(__DIR__, 3);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/quota-editor.js') {
    header('Content-Type: text/javascript');
    readfile($root . '/webroot/assets/common/js/quota-editor.js');
    return;
}
if ($path === '/bootstrap.css') {
    header('Content-Type: text/css');
    readfile($root . '/node_modules/bootstrap/dist/css/bootstrap.min.css');
    return;
}
if ($path !== '/') { http_response_code(404); return; }
function h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$prepend = '';
$tables = [];
$active = false;
$sources = [];
foreach (['age' => 'Age in years', 'sex' => 'Gender', 'education' => 'Education'] as $name => $label) {
    $source = 'survey_201.' . $name;
    $sources[$source] = ['source' => $source, 'run_unit_id' => 201, 'item_name' => $name, 'label' => $label,
        'survey_label' => 'Demography', 'type' => $name === 'age' ? 'integer' : 'category',
        'choices' => $name === 'sex' ? [['value'=>'1','label'=>'Male'],['value'=>'2','label'=>'Female'],['value'=>'-9','label'=>'No response']]
            : ($name === 'education' ? [['value'=>'uni','label'=>'University'],['value'=>'other','label'=>'Other']] : []),
        'supported' => true];
}
$sources['survey_201.notes'] = ['source'=>'survey_201.notes','run_unit_id'=>201,'item_name'=>'notes',
    'label'=>'Free text','survey_label'=>'Demography','type'=>null,'choices'=>[],'supported'=>false,'warning'=>'Free text is unsupported.'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quota editor — synthetic browser fixture</title><link rel="stylesheet" href="/bootstrap.css">
<style>body{padding:24px;max-width:1150px;margin:auto}label{margin:5px}fieldset{margin-top:12px}.quota-groups{overflow:auto}</style></head>
<body><h1>Quota editor</h1><p>Synthetic fixture. Saving here only updates this browser's test draft.</p>
<main><?php include $root . '/templates/admin/run/units/quota.php'; ?></main>
<p id="fixture-status" role="status"></p><details><summary>Saved definition</summary><pre id="fixture-saved"></pre></details>
<script type="module">
import { initQuotaEditor } from '/quota-editor.js';
const hidden = document.querySelector('[name=quota_tables]');
hidden.value = sessionStorage.getItem('quota-editor-fixture') || hidden.value;
const editor = initQuotaEditor(document.querySelector('main'));
const activate = document.querySelector('.quota-activate');
if (activate) { activate.disabled=true; activate.title='Activation requires the full authenticated application.'; }
document.querySelector('.unit_save').addEventListener('click', (event) => {
  event.preventDefault();
  if (!editor.sync()) return;
  sessionStorage.setItem('quota-editor-fixture', hidden.value);
  document.querySelector('#fixture-status').textContent = 'Draft saved in browser fixture.';
  document.querySelector('#fixture-saved').textContent = hidden.value;
});
</script></body></html>
