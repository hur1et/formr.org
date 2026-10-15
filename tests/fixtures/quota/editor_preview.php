<?php
// Local fixture with formr's actual module wrapper and styles. No application database.
// Run: php -S 127.0.0.1:8096 tests/fixtures/quota/editor_preview.php
$root = dirname(__DIR__, 3);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$assets = [
    '/quota-editor.js'=>['webroot/assets/common/js/quota-editor.js','text/javascript'],
    '/bootstrap.css'=>['node_modules/bootstrap/dist/css/bootstrap.min.css','text/css'],
    '/adminlte.css'=>['webroot/assets/admin/css/AdminLTE.css','text/css'],
    '/admin.css'=>['webroot/assets/admin/css/style.css','text/css'],
    '/roboto-font.css'=>['webroot/assets/common/css/roboto-font.css','text/css'],
    '/font-awesome.css'=>['node_modules/font-awesome/css/font-awesome.min.css','text/css'],
    '/fonts/fontawesome-webfont.woff2'=>['node_modules/font-awesome/fonts/fontawesome-webfont.woff2','font/woff2'],
];
if (preg_match('~\A/fonts/(KFO[A-Za-z0-9_-]+\.woff2)\z~', $path, $font)) {
    $assets[$path] = ['webroot/assets/common/fonts/' . $font[1], 'font/woff2'];
}
if (isset($assets[$path]) && is_file($root . '/' . $assets[$path][0])) {
    header('Content-Type: ' . $assets[$path][1]);
    readfile($root . '/' . $assets[$path][0]); return;
}
if ($path !== '/') { http_response_code(404); return; }
function h($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$module = in_array($_GET['modul'] ?? '', ['abschluss','endseite'], true) ? $_GET['modul'] : 'quotierung';
$prepend = '';
$tables = [];
$active = false;
$unitId = 203;
$provider = ['mode'=>''];
$body = '';
$providerExit = '';
$sources = [];
foreach (['age'=>'Age in years', 'sex'=>'Gender', 'education'=>'Education'] as $name=>$label) {
    $source = 'survey_201.' . $name;
    $sources[$source] = ['source'=>$source,'run_unit_id'=>201,'item_name'=>$name,'label'=>$label,
        'survey_label'=>'Demography','type'=>$name === 'age' ? 'integer' : 'category',
        'choices'=>$name === 'sex' ? [['value'=>'1','label'=>'Male'],['value'=>'2','label'=>'Female'],['value'=>'-9','label'=>'No response']]
            : ($name === 'education' ? [['value'=>'uni','label'=>'University degree'],['value'=>'other','label'=>'Other qualification']] : []),
        'supported'=>true];
}
$sources['survey_201.notes'] = ['source'=>'survey_201.notes','run_unit_id'=>201,'item_name'=>'notes',
    'label'=>'Free text','survey_label'=>'Demography','type'=>null,'choices'=>[],'supported'=>false,
    'warning'=>'Free text cannot be used for quotas.'];
$unit = new class {
    public $type='Quota', $description='Quota', $icon='fa-users', $position=20, $id=30, $run_unit_id=202, $special='';
    public function displayUnitSessionsCount() { return ''; }
};
$template = 'quota';
if ($module === 'abschluss') {
    $unit->type='Complete'; $unit->description='Complete'; $unit->icon='fa-check-circle';
    $unit->position=40; $unit->id=40; $unit->run_unit_id=203; $template='complete';
} elseif ($module === 'endseite') {
    $unit->type='Endpage'; $unit->description='Screen-out / stop page'; $unit->icon='fa-stop';
    $unit->position=30; $unit->id=50; $unit->run_unit_id=204; $template='endpage';
}
ob_start(); include $root . '/templates/admin/run/units/' . $template . '.php'; $dialog = ob_get_clean();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>formr – Quota: local preview</title>
<link rel="stylesheet" href="/bootstrap.css"><link rel="stylesheet" href="/roboto-font.css">
<link rel="stylesheet" href="/font-awesome.css"><link rel="stylesheet" href="/adminlte.css"><link rel="stylesheet" href="/admin.css">
<style>body{padding:20px 12px;max-width:1050px;margin:auto;background:#ecf0f5}.quota-preview-note{margin:10px 0}.quota-preview-technical{overflow-wrap:anywhere}.quota-preview-technical pre{max-height:220px;overflow:auto}main .box-body{padding:15px}main .run_unit_dialog{padding-bottom:10px}</style>
</head><body>
<p class="text-muted quota-preview-note">Local preview with example data. Saving affects this browser only, not a study.</p>
<nav class="btn-group" aria-label="Preview modules">
    <a class="btn btn-default" href="/">Quota</a>
    <a class="btn btn-default" href="/?modul=abschluss">Complete</a>
    <a class="btn btn-default" href="/?modul=endseite">Stop page</a>
</nav>
<main class="box box-primary" style="margin-top:15px">
    <div class="box-header with-border"><h1 class="box-title">Edit Run</h1></div>
    <div class="box-body"><form class="form-horizontal form-inline edit_run">
        <?php include $root . '/templates/admin/run/units/unit.php'; ?>
    </form></div>
</main>
<p id="fixture-status" role="status"></p>
<details class="quota-preview-technical"><summary>Saved definition (technical)</summary><pre id="fixture-saved"></pre></details>
<script type="module">
import { initQuotaEditor } from '/quota-editor.js';
document.querySelector('.position').readOnly=true;
document.querySelector('.remove_unit_from_run').disabled=true;
const hidden = document.querySelector('[name=quota_tables]');
if (hidden) hidden.value = sessionStorage.getItem('quota-editor-fixture') || hidden.value;
const editor = initQuotaEditor(document.querySelector('main'));
if (!editor) {
    const saved=JSON.parse(sessionStorage.getItem('quota-preview-<?= $module ?>') || '{}');
    for (const [name,value] of Object.entries(saved)) {
        const field=document.querySelector('form').elements.namedItem(name);
        if (field && 'value' in field) field.value=value;
    }
}
const activate = document.querySelector('.quota-activate');
if (activate) { activate.disabled=true; activate.title='Activation is only available in the full authenticated application.'; }
document.querySelector('form').addEventListener('submit', (event)=>event.preventDefault());
const testButton = document.querySelector('.unit_test');
if (testButton) testButton.addEventListener('click', (event)=>{event.preventDefault();document.querySelector('#fixture-status').textContent='Participant testing is only available in the full application.';});
document.querySelector('.unit_save').addEventListener('click', (event) => {
    event.preventDefault();
    if (editor) {
        if (!editor.sync()) return;
        sessionStorage.setItem('quota-editor-fixture', hidden.value);
        document.querySelector('#fixture-saved').textContent=hidden.value;
    } else {
        const values=Object.fromEntries(new FormData(document.querySelector('form')));
        sessionStorage.setItem('quota-preview-<?= $module ?>',JSON.stringify(values));
    }
    document.querySelector('#fixture-status').textContent='Draft saved in the local preview.';
});
</script></body></html>
