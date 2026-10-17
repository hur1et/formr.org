# Portable Quota and Complete run definitions

Run JSON export now includes Quota/Complete through both the browser exporter and
`Run::exportStructure()`. The server reloads the owner's saved configuration for these
two modules; posted form fields cannot inject an activation or a provider profile.

## Transfer contract

- Quota: versioned `quota_definition` containing table codes, dimensions, category codes,
  inclusive integer ranges and current limits. Active and draft definitions use the same
  portable format. Active counts and corrections are not exported.
- Source references use the source Survey's **exported position plus actual item name**.
  No source database IDs are exported. The importer maps only Survey units successfully
  imported by the same call, using their new run-unit IDs. Appending to an existing run
  adjusts positions without changing that mapping. Equal item names in different Survey
  occurrences remain distinct.
- Complete: only explicit `provider_mode: "none"` is retained. All panel-provider settings
  (incoming parameter, placeholder, three study-specific URLs) are excluded. This is
  product decision A, confirmed 17 October 2026. A provider run requires fresh Complete
  configuration before activation. Import also discards provider fields in edited JSON.
- No admission decisions, assignments, completions, adjustments, participant/provider IDs,
  activation identity or public statistics keys are transferred. Imported quotas are drafts.
- Page's `screen_out` choice remains portable; its URL is resolved from the newly configured
  provider profile, never an exported callback.

## Partial or invalid imports

An empty Quota module is portable. A partial export without its source survey, a Survey
without item details, or an unsupported/missing item leaves a visible, editable draft.
It never falls back to another existing survey, an old database ID or a matching display
label. Saving/activation requires supported preceding variables; missing references block
activation, not opening the editor. The existing editor presents unavailable variables.

Unknown format versions, duplicate/overlapping positions and multiple Quota/Complete units in one
bundle fail preflight before unit creation. Importing a second Quota/Complete of the same
type into the target run is refused. API structure replacement refuses runs with existing
quota definitions/drafts so immutable counting references are not removed. Normal legacy
run imports keep their existing transaction behavior: a full import involving survey DDL
is **not** a new all-or-nothing transaction. Imported quotas remain inactive on failure.
Quota draft and no-provider inserts use owner/type-scoped atomic statements and never
commit an API caller's outer transaction.

Audit follow-up (2026-10-17): StructureResource now checks the actual replacement result
before reading the run's unit count. A refused replacement returns HTTP 409, not a false
success based on surviving old units. Successful imports must match both the returned
imported-unit count and the actual run count; a mismatch returns an error. This does not
change the inherited transaction/DDL limitation above. For the initial migration, use a
reviewed import into a new inactive run rather than a destructive API replacement.

## Verification

- `QuotaStructureTest`: generic income × party definitions, crossed group limits,
  distinct source occurrences, leading-zero answer codes, missing source references,
  provider/activation/identity field stripping, invalid positions/version, empty quota.
- `QuotaStoreTest`: inactive round-trip, explicit activation with zero counts, current
  active limits, no counting/provider history, missing source/profile activation failures,
  ownership, existing target protection and outer transaction rollback.
- Full local application: synthetic Survey → Survey → Quota → Page → Complete; repeated
  item names; append after an existing Page; source IDs remapped; missing provider config
  rejected; explicit no-provider activation starts at zero. Partial import preserves limits;
  unsupported format version leaves the new target empty.
- Native browser Run Export downloaded real JSON with the quota definition and no provider
  profile; native file import created all five modules, editable Quota and blank Complete
  settings. Browser console had no errors/warnings.
- A Page regression test covers empty `provider_exit` strings normalized to null by Model.
  Page now stores the normal stop-page choice as an empty string after property assignment.

Final standard lane: 272 tests / 697 assertions, with 11 pre-existing PHPUnit deprecations.
Disposable MariaDB lane: 137 tests / 534 assertions. Changed PHP syntax and diff checks pass.

Survey item imports retain upstream's existing same-owner survey-name reuse behavior;
portable source-occurrence remapping does not create an independent copy of those surveys.

No new schema patch, dependency or production change is required for this milestone.
