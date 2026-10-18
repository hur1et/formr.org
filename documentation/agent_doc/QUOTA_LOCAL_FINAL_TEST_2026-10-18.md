# Local final test, 18 October 2026

Application revision: `77ed0006`, branch `feature/quotas`. The working tree was clean
before and after execution; no application changes were needed during this test pass.
Runtime: Windows, PHP 8.2.34, MariaDB 12.1.2, existing locked frontend dependencies.
The production VM and real providers were not contacted.

## Results

| Check | Result |
| --- | --- |
| Complete standard SQLite unit lane | 314 tests, 815 assertions; pass |
| Complete quota MariaDB lane | 148 tests, 606 assertions; pass |
| Quota editor, overview and completion countdown | 25 tests, zero failures |
| All tracked PHP files | 337 syntax checks; zero errors |
| Production frontend build | Pass; three existing performance/size warnings |
| Additional security and refusal fixtures | All four scripts pass |
| Native completion persistence and Run transfer | Pass |

Total test-runner cases: **487**, plus syntax/build and the standalone checks.
The standard PHP lane reports 11 existing PHPUnit deprecations. Expected OAuth
validation messages and deliberately simulated Markdown crashes appear in its log;
they do not represent failed test cases. No full test lane was skipped after a failure.

## Additional checks executed

The existing audit runner executed `astra_security.php`, `maintenance_regression.php`,
`astra_api_refusal.php` and `astra_config_growth.php` in separate PHP processes with
random disposable databases. The old deletion diagnostic was not used as a regression.
Checks cover ownership refusals; immutable, bounded, run-scoped provider IDs; exact
encoding; sticky rejection; source failure/recovery; field/test separation; completion
retry without duplicate booking; missing-ID recovery; screen-out/cron behaviour; wrong
or expired current units; monitor output and revocation; preservation on refused
maintenance; API 409 on refused replacement; and rejection before expanding 4,096 groups.
No actual provider request was sent by these service fixtures.

The native local model/SQL check used the existing synthetic completion fixture on
port 13369. Totals remained field=2 and test=1 after the earlier browser retries.
An active provider mutation without reopening was refused. A fresh native Run import
preserved feedback, return message, link label and delay, stayed inactive, excluded
the provider profile and refused activation until that missing profile is configured.
This was not a new browser interaction or a new production-data migration rehearsal.

Full logs are retained in the external project output directory:
`outputs/Gesamttest-77ed0006-{unit,db,js,build,syntax,security,native}.log`.

## Scope remaining for release

This is the standard general unit lane plus the complete dedicated quota database
lane; the general integration lane that needs its own schema/bootstrap and external
services was not claimed as executed. OpenCPU, real provider-approved callbacks,
the production-copy migration and VM HTTP/load behaviour remain separate release gates.
This reruns existing security fixtures; it is not an independent new Astra review.
No new load or capacity measurement was made, and no production switch was approved.

Next: fresh VM inventory/backup and an isolated migration rehearsal, then the concrete
switch/rollback procedure, VM load tests and HiWi/provider study acceptance.
