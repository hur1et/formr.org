<?= $prepend ?>
<p class="text-warning"><small>Entwicklungsversion. Vor dem Feldeinsatz den vollständigen Studienablauf prüfen.</small></p>
<?php if ($active): ?>
    <p class="text-success"><strong>Quotierung aktiviert.</strong> Kategorien und Provider-Einstellungen sind festgelegt.
        Den Editor neu laden, um den Abschlussbaustein zu aktualisieren.</p>
<?php endif; ?>
<p>Variablen innerhalb einer Tabelle werden gekreuzt. Alle Tabellen müssen erfüllt sein.
    Gezählt werden nur Teilnahmen, die den Abschlussbaustein erreichen.</p>
<div class="quota-editor" data-readonly="<?= $active ? '1' : '0' ?>" data-sources="<?= h(json_encode($sources, JSON_THROW_ON_ERROR)) ?>">
    <input type="hidden" name="quota_tables" value="<?= h(json_encode((object) $tables, JSON_THROW_ON_ERROR)) ?>">
    <div class="quota-editor-error alert alert-danger" role="alert" hidden></div>
    <div class="quota-editor-tables"></div>
    <button type="button" class="btn btn-default btn-sm quota-add-table">Quotentabelle hinzufügen</button>
    <p class="help-block">Fehlende oder nicht zugeordnete Antworten führen zum Ausschluss über „Quote voll“.
        Eine Zulassung reserviert keinen Platz.</p>
</div>
<hr>
<p class="btn-group">
    <a class="btn btn-default unit_save" href="ajax_save_run_unit?type=Quota"><?= $active ? 'Beschreibung speichern' : 'Entwurf speichern' ?></a>
    <?php if (!$active): ?><button type="button" class="btn btn-primary quota-activate">Speichern und aktivieren</button><?php endif; ?>
</p>
<?php if (!$active): ?><p class="help-block">Vor der Aktivierung die Einstellungen im Abschlussbaustein speichern.
    Mit der Aktivierung werden die Kategorien festgelegt und die Prüfung an dieser Run-Position eingeschaltet.</p><?php endif; ?>
