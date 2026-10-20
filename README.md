# FrontAccounting Module: sgw_import

[![CI](https://github.com/saygoweb/frontaccounting-module-sgw_import/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/saygoweb/frontaccounting-module-sgw_import/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/saygoweb/frontaccounting-module-sgw_import/branch/master/graph/badge.svg)](https://codecov.io/gh/saygoweb/frontaccounting-module-sgw_import)

A module for Front Accounting that provides automated import of transactions from csv files exported from your bank.

- Match lines in a bank account file to a customer, supplier or a quick entry.
- Automatically generate a simple direct supplier invoice.
- Automatically allocate deposits to customer invoices, if there is a reference in the line that can be used to match to the invoice number.

---

> **Note on this copy.** Everything below the line *"Installation in this
> installation"* is a local addition, written while installing the module in
> this FrontAccounting (2.4.18, PHP 8.4). The upstream project is
> <https://github.com/saygoweb/frontaccounting-module-sgw_import>; re-cloning
> it replaces the whole directory and loses these notes.

## Installation in this installation

The module is in `core/modules/sgw_import`. It was installed with:

```sh
git clone https://github.com/saygoweb/frontaccounting-module-sgw_import.git \
    core/modules/sgw_import
rm -rf core/modules/sgw_import/.git
docker compose exec -T php sh -c \
    'cd /var/www/html/modules/sgw_import && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev'
```

`--no-dev` skips PHPUnit, PHPStan and phpcs, which are not needed at runtime.
The module loads `vendor/autoload.php` from its `hooks.php`, so without
`composer install` every page of it fails.

### Prerequisites

| Requirement | Why |
| --- | --- |
| `pdo_mysql` | the module's ORM (Anorm) talks to the database over PDO. The base image only ships `pdo_sqlite`, without it the module dies with *"could not find driver"* |
| `composer install` | see above |
| the module's three tables | activation does **not** create them |
| `uploads/` writable by `www-data` | the uploaded csv files are stored there |

`pdo_mysql` is built in `docker/php/Dockerfile`, so a rebuild of the image
keeps it. For a running container:

```sh
docker compose exec -T php docker-php-ext-install -j"$(nproc)" pdo_mysql
docker compose restart php
docker compose exec -T php php -r 'print_r(PDO::getAvailableDrivers());'   # mysql must be there
```

### The tables

The module's tables are **per company** — the prefix is the company's own
table prefix, not a fixed one. Take `data/0.1.0.sql` and substitute the
prefix for every company that should use the module:

```sh
for p in 0 4 6; do
    sed "s/\`0_/\`${p}_/g" core/modules/sgw_import/data/0.1.0.sql \
        | docker compose exec -T db mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME"
done
```

That creates `<prefix>_import_file`, `<prefix>_import_file_type` and
`<prefix>_import_line`. The `id` columns need `AUTO_INCREMENT`, and
`<prefix>_import_file` needs a unique key on `file_name` — the sql file has
them, but if the tables already existed as empty stubs they have to be
recreated.

### The upload directory

```sh
docker compose exec -T php sh -c \
    'chown -R www-data:www-data /var/www/html/modules/sgw_import/uploads && chmod 775 /var/www/html/modules/sgw_import/uploads'
```

### Registering and activating it

**Only the site admin company (company 0) may install or activate modules**:
`SA_CREATEMODULES` belongs to the site admin section, and
`current_user::can_access()` denies it for every other company. So the
module has to be added to a company by hand.

Two files are involved:

* `core/installed_extensions.php` — the **global** list of available
  extensions. Every extension here gets its `hooks.php` included on every
  request, so an extension that is not listed here never loads.
* `core/company/<n>/installed_extensions.php` — the per company list, with
  the `active` flag.

```php
// core/installed_extensions.php
$next_extension_id = 18;                     // must be higher than every id in use

$installed_extensions = array (
  ...
  17 =>
  array (
    'package' => 'sgw_import',
    'name' => 'sgw_import',
    'version' => '-',
    'available' => '',
    'type' => 'extension',
    'path' => 'modules/sgw_import',
    'active' => false,
  ),
);
```

```php
// core/company/2/installed_extensions.php
  17 =>
  array (
    'package' => 'sgw_import',
    'name' => 'sgw_import',
    'version' => '-',
    'available' => '',
    'type' => 'extension',
    'path' => 'modules/sgw_import',
    'active' => 1,
  ),
```

The extension id matters beyond the label: it is the base of the security
area code (see below). Pick one and use it consistently.

### The security area

`hooks_sgw_import::install_access()` defines one new area
(`SA_SGW_IMPORT_FILE`) in one new section (`SS_SGW_IMPORT`). FrontAccounting
assigns the codes from the extension id:

```
area    = (extension id << 16) | 25600 | 100      ->  for id 17: 1139812
section = (extension id << 16) | 25600            ->  for id 17: 1139712
```

Both have to be in the role's `areas` / `sections` columns of
`<prefix>_security_roles`, otherwise every page of the module answers *"The
security settings on your account do not permit you to access this function"*.

```sql
UPDATE 4_security_roles
SET areas    = CONCAT(areas, ';1139812'),
    sections = CONCAT(sections, ';1139712')
WHERE id IN (2, 12)                     -- System Administrator, Boss
  AND FIND_IN_SET('1139812', REPLACE(areas, ';', ',')) = 0;
```

The user has to log in again afterwards: the role set is built once per
session and cached in it.

### What the extension editor needs

If a module's `hooks.php` defines no class, activating *or deactivating* it
reports *"hook class hooks_<name> not found"*. A module that only overrides
reports still needs an empty class:

```php
class hooks_rep_aus_invoice extends hooks
{
    // no hooks required as overriding default reports (rep*)
}
```

---

## The configuration model

Three tables, three levels. The upper two are configured **per bank
account**, not per file.

### `<prefix>_import_file` — one row per uploaded file

`id, bank_id, dt_import, file_name`. The csv itself lives in
`modules/sgw_import/uploads/`. Nothing to configure here.

### `<prefix>_import_file_type` — the column layout, one per bank account

| Field | Meaning |
| --- | --- |
| `columns` | the header row of the csv, comma separated |
| `hide` | comma separated column **names** to hide in the grid |
| `date_field` | the **name** of the column that holds the date |
| `amount_field` | the **name** of the column that holds the amount |
| `date_format` | `yyyymmdd` or `ddmmyy` |

`columns` is filled **automatically from the first upload** for that bank
account. A later upload with a different header does *not* update it — the
configuration has to be edited by hand.

Only two date formats exist:

| Value | Meaning |
| --- | --- |
| `yyyymmdd` | the cell already is `YYYY-MM-DD`, it is passed through |
| `ddmmyy` | `DD/MM/YY` with a **two digit** year, parsed with `DateTime::createFromFormat('d/m/y')` |

Anything else aborts the import with *"Unsupported date format"*.

### `<prefix>_import_line` — the matching rules, many per bank account

| Field | Meaning |
| --- | --- |
| `party_field` | the column that carries the party, a column **name** |
| `party_match` | the value to recognise, e.g. `CPF` |
| `party_type` | `customer`, `supplier`, `quick`, `quick_deposit`, `transfer` |
| `party_id` | the FrontAccounting id (debtor_no / supplier_id / quick entry id) |
| `party_code` | the party's FA code (debtor_ref / supp_ref), filled in automatically |
| `doc_field` | the column that carries a document reference |
| `doc_match` | a **regular expression** matched against that column |
| `doc_type` | `none`, `supplier_invoice`, `customer_invoice` |
| `doc_code` | the item code used for the generated supplier invoice line |

The party match is an **exact, case-insensitive match of the whole cell** —
not a substring. Only `doc_match` is a regular expression, and its match is
the document reference, so `/INV-\d+/` finds an invoice number inside a
longer description.

`party_type` picks the importer
(`Importer::fromPartyType()`):

| Type | Importer | Creates |
| --- | --- | --- |
| `supplier` | `SupplierImporter` | direct supplier invoice + goods received + supplier payment + allocation |
| `customer` | `CustomerImporter` | customer payment / receipt |
| `quick` | `QuickPaymentImporter` | a quick entry payment |
| `quick_deposit` | `QuickDepositImporter` | a quick entry deposit |
| `transfer` | `TransferImporter` | a transfer between bank accounts |

### How a row is matched

`Lines::matchingLine()` indexes all the rules of the bank account by
lowercased `party_match`, and collects the columns that carry a party. For
every row it lowercases the cell in each of those columns and looks it up:

* **hit** — the rule applies, the row shows the party type and code and
  gets a checkbox
* **miss** — the row shows an *Add Line* button

The date and amount columns are resolved by **name** (`columnKey()`), so the
importer reads the right cells whatever their position is.

---

## A walkthrough

The test file is [`samples/bank_supplier.csv`](samples/bank_supplier.csv) in
this repository (the same file is kept as `test-data/sgw_import/` in the
application repository):

```
Date,Description,Reference,Amount
2026-06-01,CPF contribution,CPF,-1200.00
2026-06-02,Exabytes hosting,Exabytes.SG,-340.25
```

The rows match the supplier **CPF** (`supplier_id` 3, `supp_ref` CPF) and
**Exabytes.SG** (`supplier_id` 4). The amounts are **negative**, see
[the sign](#the-sign-of-the-amount).

| Sample | Purpose |
| --- | --- |
| `samples/bank_supplier.csv` | the working file: dates as `YYYY-MM-DD`, **negative** amounts (money out to a supplier) |
| `samples/bank_supplier_positive.csv` | the same rows with positive amounts, to show the sign convention of the module |
| `samples/bank_supplier_ddmmyyyy.csv` | the same rows with `DD/MM/YYYY` dates, to show the two date formats the module accepts |


### 1. Upload

*Banking and General Ledger → Import Transactions*, choose the bank account
(**OCBC Checking**, `bank_id` 3) and upload `bank_supplier.csv`.

This creates the `import_file` row and, the first time for that bank
account, the `import_file_type` row with the column list.

### 2. Configure the columns

*Banking and General Ledger → Configure Import Transactions*, same bank
account:

| Setting | Value |
| --- | --- |
| Date field | `Date` |
| Date format | `YYYY-MM-DD` |
| Amount field | `Amount` |

### 3. Match the rows to a supplier

Open the uploaded file and press **Add Line** on the first row. Then fill in
the rule:

| Setting | Value |
| --- | --- |
| Party type | Supplier |
| Party id | 3 (CPF) |
| Party code | CPF |
| Field name | `Reference` — the column the party is matched on |
| Matching | `CPF` |
| Document type | Supplier Invoice |
| Document code | `201` — an item that is not flagged `no_purchase` |

The second row matches only after a second rule for `Exabytes.SG` is added.

### 4. Import

Tick the row and press **Import Data**. Leave *Dry Run* unchecked to write
the documents; with it checked the module only reports what it would do.

---

## What it creates

For one imported row the module creates

* a **direct supplier invoice** (`ST_SUPPINVOICE`) with one line for the
  document code, at the amount of the bank line,
* the **purchase order** and the **goods received** behind that invoice,
* a **supplier payment** (`ST_SUPPAYMENT`) on the bank account,
* the **allocation** of the payment to the invoice,
* the gl postings and the audit trail entries that go with them.

### The sign of the amount

The module negates the amount of the bank line for the invoice and for the
payment. A **positive** amount in the csv therefore produces an invoice of
`-1200.00`, a **negative** amount the usual `1200.00` invoice. Use negative
amounts for money paid out to a supplier.

| csv amount | invoice | payment |
| --- | --- | --- |
| `+1200.00` | `-1200.00` | `+1200.00` |
| `-1200.00` | `+1200.00` | `-1200.00` |

---

## Cleaning up

The test leaves a supplier invoice, a purchase order, a goods receipt, a
supplier payment, its allocation and the gl postings behind them. Delete
them from the application (*Purchases → Supplier Invoices*, void the invoice
and the payment) or with sql — the ids are the ones the import reported:

```sql
START TRANSACTION;
DELETE FROM 4_audit_trail WHERE (type=20 AND trans_no=<invoice>) OR (type=22 AND trans_no=<payment>)
                           OR (type=18 AND trans_no=<po>)      OR (type=25 AND trans_no=<po>);
DELETE FROM 4_gl_trans            WHERE (type=20 AND type_no=<invoice>) OR (type=22 AND type_no=<payment>);
DELETE FROM 4_supp_invoice_items  WHERE supp_trans_no=<invoice>;
DELETE FROM 4_supp_trans          WHERE trans_no=<invoice>;
DELETE FROM 4_bank_trans          WHERE type=22 AND trans_no=<payment>;
DELETE FROM 4_grn_items           WHERE id=<grn item id>;
DELETE FROM 4_grn_batch           WHERE id=<grn batch id>;
DELETE FROM 4_purch_order_details WHERE order_no=<po>;
DELETE FROM 4_purch_orders        WHERE order_no=<po>;
DELETE FROM 4_refs                WHERE type=20 AND id=<invoice>;
COMMIT;
```

The module's own configuration is separate:

```sql
DELETE FROM 4_import_file      WHERE id=<file id>;   -- and the file in uploads/
DELETE FROM 4_import_line      WHERE id=<rule id>;
DELETE FROM 4_import_file_type WHERE id=<type id>;
```

To inspect the current state at any time:

```sql
SELECT * FROM 4_import_file;
SELECT * FROM 4_import_file_type;
SELECT * FROM 4_import_line;
```

---

## Troubleshooting

### *"could not find driver"*

`pdo_mysql` is missing — see [prerequisites](#prerequisites).

### *"The security settings on your account do not permit you to access this function"*

The role is missing the module's area, or the session is older than the
grant. The code is `(extension id << 16) | 25600 | 100`; see
[the security area](#the-security-area). Log out and in again.

### *"Column 'Payee' not found"*

The *Add Line* button hard-codes `party_field = 'Payee'` and takes the
match value from **column index 2**. It only produces a usable rule if the
party is in the third column. Until the party field is pointed at a real
column the page cannot be loaded — set *Field name* on the rule.

### *"Unsupported date format '…'"*

Only `yyyymmdd` and `ddmmyy` are supported, and `ddmmyy` needs a two digit
year. Reformat the csv, or add a format to
`includes/Import/Importers/Importer.php::sqlDate()`.

### *"Column '…' not found"*

The column list in `import_file_type.columns` is a `varchar(255)` and gets
truncated when the header is longer. A 20 column bank export with long
names does not fit. Widen the column to `text` in `data/0.1.0.sql` (and in
the database), then re-enter the column list.

### The rows do not match

The party match is an exact, case-insensitive match of the whole cell
against `party_match`. A row whose party cell reads *"Payment to CPF Ltd"*
does not match the rule `CPF`. The cell has to contain the code and
nothing else, or the rule has to be created for the longer string.

### The import does nothing

The row has to be **ticked** — the checkbox only appears on rows that
matched a rule. A matched row with a matched rule but no tick is skipped
with *"No lines selected for import."*.

### The module is listed twice in the extension editor

It was added to the global list twice, by hand and by the editor. The
global list is `core/installed_extensions.php`, the per company list
`core/company/<n>/installed_extensions.php`; keep one entry with one id in
each, and make sure `$next_extension_id` is higher than every id in use.

---

## Upstream documentation

Everything below this line is the upstream project's own readme.

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
