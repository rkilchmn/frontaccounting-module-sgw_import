# FrontAccounting Module: sgw_import

[![CI](https://github.com/saygoweb/frontaccounting-module-sgw_import/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/saygoweb/frontaccounting-module-sgw_import/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/saygoweb/frontaccounting-module-sgw_import/branch/master/graph/badge.svg)](https://codecov.io/gh/saygoweb/frontaccounting-module-sgw_import)

A module for Front Accounting that provides automated import of transactions from csv files exported from your bank.

- Match lines in a bank account file to a customer, supplier or a quick entry.
- Automatically generate a simple direct supplier invoice.
- Automatically allocate deposits to customer invoices, if there is a reference in the line that can be used to match to the invoice number.

## Installation
The module needs to be unpacked and exist in the folder
`./modules/sgw_import`.

As with all modules you will need to set permissions for any user, including the system admin, in the setup menu. You will need to logout and login again after doing this.

## Getting Started
TLDR ...
The menu items `Import Transactions` and `Configure Import Transactions` have been added under the `Banking and General Ledger` menu.


1. `Import Transactions`, upload a csv file setting the correct bank account.
2. `Configure Import Transactions`, set the date field, date format, and amount field. Optionally hide some columns you don't need to see in the import transactions screens.
3. Back to `Import Transactions`. Click the filename or number. Add line definitions to those pesky recurring transactions. It's ok to leave some that are one off, or better done manually.

## Tests

CI runs `tools/ci.sh` in the FrontAccounting CI image
([cambell-prince/frontaccounting `docker/ci`](https://github.com/cambell-prince/frontaccounting/tree/master-cp/docker/ci)),
on FrontAccounting's demo company, with graphql and sgw_sales activated
alongside, as they are deployed, for FrontAccounting cp and upstream on PHP 7.4
and 8.3. It runs:

- `composer run quality`: `php -l`, PSR-12 over `tests/` (phpcs), and PHPStan
  level 5 (existing findings in `includes/` are in `phpstan-baseline.neon`).
- A check that every model loads with the autoloaders in production's order
  (graphql's last, so its Anorm wins).
- The PHPUnit suites (`phpunit.xml`):
  - `unit`: no FrontAccounting, no database.
  - `integration`: FrontAccounting loaded in-process by the GraphQL module's
    Bootstrap. It covers the models, each importer writing a bank line and
    finding it again on a later import, and the controllers, with their views
    mocked.
  - `http`: the pages in Apache. A file is uploaded, configured, given lines,
    imported, imported again and deleted.

Tests remove every row they write. `tools/init.sh` creates sgw_import's
tables, which activation does not, and makes `uploads/` writable by Apache.
To run CI locally, with that repository checked out beside this one:

    ../frontaccounting/docker/ci/plugin-test.sh --dataset demo \
      --setup 'composer install --no-interaction --no-progress' \
      --with graphql=https://github.com/saygoweb/frontaccounting-module-graphql.git@main \
      --with sgw_sales=https://github.com/saygoweb/frontaccounting-module-sgw_sales.git@master \
      . -- sh tools/ci.sh

`tools/coverage.sh` in place of `tools/ci.sh` measures the unit and
integration suites into `build/coverage.xml`; CI sends that to Codecov.
