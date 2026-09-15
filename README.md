<!-- SHIELDS -->
[![Contributors][contributors-shield]][contributors-url]
[![Forks][forks-shield]][forks-url]
[![Stargazers][stars-shield]][stars-url]
[![Issues][issues-shield]][issues-url]
[![License][license-shield]][license-url]

<h3>API Sponsor Manager</h3>
<p>
  Manages looking for sponsor listnings.
  <br />
  <a href="https://github.com/helsingborg-stad/api-sponsor-manager/issues">Report Bug</a>
  ·
  <a href="https://github.com/helsingborg-stad/api-sponsor-manager/issues">Request Feature</a>
</p>

## Table of Contents
- [Table of Contents](#table-of-contents)
- [About API Sponsor Manager](#about-API Sponsor Manager)
  - [Built With](#built-with)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
- [Usage](#usage)
- [Multipart REST upload protocol (v1)](#multipart-rest-upload-protocol-v1)
- [Testing](#testing)
- [Deploy](#deploy)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)
- [Acknowledgements](#acknowledgements)

## About API Sponsor Manager

[![API Sponsor Manager Screen Shot][product-screenshot]](https://example.com)

Here's a blank template to get started:

### Built With

* PHP
* NPM
* Webpack
* Modularity

## Getting Started

To get a local copy up and running follow these simple steps.

### Prerequisites

This is an example of how to list things you need to use the software and how to install them (mac os).
* composer
```sh
brew install composer
```
* npm
```sh
brew install node
```
### Installation

1. Clone the repo
```sh
git clone https://github.com/helsingborg-stad/api-sponsor-manager.git
```
2. Install and build NPM packages
```sh
npm install && npm run build
```
3. Install composer packages
```sh
composer install
```

## Usage

Use this space to show useful examples of how a project can be used. Additional screenshots, code examples and demos work well in this space. You may also link to more resources.

_For more examples, please refer to the [Documentation](https://example.com)_

## Multipart REST upload protocol (v1)

Sponsor submissions can carry images, files and galleries in a single
multipart request to the native WordPress endpoints
`POST /wp-json/wp/v2/sponsor-assignments` and
`POST /wp-json/wp/v2/sponsor-offerings`. The receiver extends the native REST
dispatch; ordinary clients are unaffected.

### Supported methods and requests

- Only `POST` is supported. Native PHP parses multipart file parts for POST
  bodies only, so `PUT`/`PATCH` multipart bodies cannot be received.
- Requests without the `X-ACF-Rest-Upload-Version` header (regular JSON
  payloads) are never touched and keep their standard WordPress behavior.
- The sender must additionally hold the WordPress `upload_files` capability.

### Headers

```text
Idempotency-Key: <client generated UUID>
X-ACF-Rest-Upload-Version: 1
```

### Payload rules

- A value of exactly `$file:<key>` references a binary sent as the multipart
  file part `_acf_rest_files[<key>]`. Keys match `[A-Za-z0-9_-]+`; numeric
  keys (`$file:0` → `_acf_rest_files[0]`) are supported. The same key may be
  referenced from several fields and creates exactly one attachment.
- Bracket paths use explicit numeric indices end to end
  (`acf[gallery][1]`), so values keep their exact positions.
- Multipart cannot represent JSON `null` or an empty array, so they travel in
  two reserved body lists. Both use bracket paths rooted at the request root:
  - `_acf_rest_nulls[]` — the value is cleared to `null`
    (`acf[optional_image]`).
  - `_acf_rest_empty[]` — the value is cleared to an empty list
    (`acf[gallery]`). The append form (`acf[gallery][]`) also defines the
    whole list as empty; it never appends an empty item.
- A null/empty path that collides with a `$file:` reference, a path that is
  not rooted at `acf`, missing or unexpected file parts, body fields named
  `_acf_rest_files`, file parts outside `_acf_rest_files`, and nested part
  names deeper than `_acf_rest_files[<key>]` are rejected with HTTP 400.
- A referenced field must be an image/file/gallery field with the field
  setting "Allow multipart REST upload" enabled, must live in exactly one
  REST exposed field group located on the destination post type, and its name
  must be unambiguous.
- A gallery may mix existing destination attachment ids and `$file:` markers;
  order and existing ids are preserved.

### Idempotency and retries

- The first request with a given key claims it atomically and owns it.
- While a request with the same key is still running, retries receive
  `425 Too Early` with a `Retry-After` header. Senders should treat 425 as
  retryable and honor `Retry-After`.
- A completed key replays the original result without re-running the native
  callback: `201 Created` with `{"id": <post id>}` for creates, `200` with
  `{"id": <post id>}` for updates.
- New operations retain a fingerprint of request values, filenames, MIME
  hints, and file bytes. Temporary paths and response controls are excluded.
  Reusing a key with different data returns `409 acf_rest_upload_key_conflict`
  before any new save or media creation. Create fingerprints remain with the
  original post after claim-history cleanup. Legacy records without a
  fingerprint keep their previous replay behavior because their original
  input is unavailable. Update replay/conflict protection is bounded by the
  retained claim history.
- A completed claim whose post is deleted or no longer has the original post
  type returns `410 acf_rest_upload_resource_gone`, not a false success.
- Claims hold an owner token and expire after 15 minutes. Expiry does not
  permit a new save. Interrupted operations return a controlled 425 recovery
  response unless the permanent create identity proves full completion.
  Claims retain operation scope, owned attachment IDs, and progress phase.
- Every ownership-sensitive claim transition (takeover, adoption, completion,
  release) is a database-level compare-and-swap on the exact stored claim
  state, so concurrent senders of the same key serialize on the row and all
  losers fail closed to replays or 425. Only the transition winner attempts
  the completion action. The durable attempt is not confirmation of delivery.
- On failure the receiver deletes the draft it created, restores overwritten
  ACF values of updates, and deletes only the attachments this request
  created — old and shared attachments are never removed.

### Multipart updates and recovery

- Only native placeholder errors on collected file-reference paths are
  deferred. Other validation and policy errors remain errors. Ordinary
  parameters are sanitized once before endpoint permission checks; ACF
  parameters are sanitized after upload references become attachment IDs.
- Multipart updates support only `title`, `status`, and `acf`. Other mutable
  fields fail with `acf_rest_upload_unsupported_update` before saving or uploads.
  Requests without the protocol header keep native JSON behavior.
- Before saving, the receiver captures submitted native values and every
  submitted ACF field by its exact destination field key. ACF snapshots use
  raw values, not formatted image arrays or display values.
- Recovery restores these values and checks storage afterward. An unchanged
  value is not a recovery failure. Absent ACF values remain distinct from
  stored false/null values, and field references are restored.
- Failed restoration or media cleanup retains `recovery_failed` evidence and
  blocks duplicate work. Snapshots are request-local: process interruption
  requires explicit recovery, not automatic replay of partial work. Custom
  field hooks that modify other objects require their own compensation.
- Native sideload destinations are recorded against the exact input temporary
  file before the move. If attachment insertion fails, recovery removes that
  file too. Failed file deletion retains its path in the claim for recovery;
  nested sideloads with a different input file are not owned by the outer request.

### Upload limits and errors

New operations support at most **8 MiB of aggregate file bytes** and **1 MiB
of JSON-encoded parameter data**. The receiver checks actual file sizes before
claiming or saving. PHP/web-server request limits still apply before parsing.
Configure the sender's origin upload limit no higher than 8 MiB and reserve
at least 256 MiB of PHP memory for the buffered sender and normal image
processing. This is not a streaming or arbitrary-size upload protocol.
Decoded image dimensions require appropriate destination image-processing
limits; the transport byte cap does not bound decoded pixel memory.

Known invalid MIME, empty uploads, and size-limit violations return 415, 400,
and 413 respectively. PHP temporary-directory/write failures and unknown
storage errors remain 5xx. Existing explicit error statuses are preserved.
WordPress's native filename, EXIF title/caption, and alt-text defaults remain
in effect. Field settings accept group keys and database IDs, and field
loading preserves stored opt-in intent; the receiver always checks current
REST exposure separately.

### Notifications

After a protocol request fully succeeds, the receiver attempts
`AcfRestUpload/afterInsertPost` with the post ID and scoped operation option.
Replays do not fire this action. Finalization runs at the last filter priority,
after ordinary REST response filters, for both internal and HTTP dispatch.

Sponsor notifications keep pending templates by operation and post. Both
submission and publish mail wait for full protocol success. A failed operation
discards its pending batch. Completed batches send only to that post's
recipients and are consumed through an atomic durable delivery claim.

Delivery is **at most once**, not exactly once. A crash after the completion
or delivery claim can lose an email, including remaining messages in a batch.
`completion_attempted` and `delivery_attempted` record attempts, not confirmed
delivery. A new process cannot repeat a claimed batch. No automatic outbox
recovery is provided.

The frontend-form Database path keeps its own
`ModularityFrontendForm/afterInsertPost` action. It consumes only the queue for
the supplied post ID. Non-protocol publish behavior remains unchanged.

## Testing

Run tests only in isolated, non-deployed environments. The lockfile includes
Brain Monkey, Mockery, WordPress 7.1, its matching test library, PHPUnit
polyfills, and the plugin's ACF import helper.

The pre-merge workflow (`.github/workflows/pre-merge-test.yaml`) uses PHP 8.3,
installs dependencies from the committed `composer.lock`, and runs
`composer test` (the standalone unit suite). The committed dependencies require
PHP 8.2 or newer, so PHP 7.4 cannot install them from the lockfile.

### Standalone tests

The unit suite (`source/tests/php`, `phpunit.xml`) does not load WordPress.

```sh
composer install
env -u WP_TESTS_DIR composer test
composer lint
```

Mago is pinned to 1.8.0 because this release does not autoload its internal
helper functions. Releases that autoload these functions can cause a fatal
`Cannot redeclare Mago\Internal\locked()` when Municipio loads its own copy.
Keep this pin until a replacement has been verified with both autoloaders.
After pulling this change, run `composer install` in the plugin directory to
replace an incompatible installed version and regenerate the autoloader.
Do not edit generated files under `vendor/`.

### Native integration tests

The integration suite uses `phpunit-integration.xml`. It boots this plugin's
entry file, its production ACF groups, native sponsor controllers, and real
database/media operations. It does not replace sponsor routes. WordPress's
test library manages database transactions; PHPUnit global serialization is
disabled because it invalidates the live database connection.

Prerequisites:
- A **disposable** MySQL/MariaDB database whose name starts with `sponsor_test_`.
- PHP with mysqli, GD, cURL, and process/loopback-server support.
- An isolated licensed ACF PRO installation, not a live plugin checkout.
- A sender worktree for the wire fixture. Only its dependency-free encoder is loaded.

**The WordPress test bootstrap recreates tables. Never supply a live database.**

```sh
export SPONSOR_INTEGRATION_TESTS=1
export SPONSOR_TEST_DB_NAME=sponsor_test_receiver
export SPONSOR_TEST_DB_HOST=localhost:/path/to/isolated/mysql.sock
export SPONSOR_TEST_DB_USER=test_user
export SPONSOR_TEST_DB_PASSWORD=test_password
export ACF_PLUGIN_FILE=/path/to/isolated/advanced-custom-fields-pro/acf.php
export SENDER_PLUGIN_DIR=/path/to/modularity-frontend-form-worktree
composer test:integration
```

`WP_TESTS_DIR` defaults to the installed `vendor/wp-phpunit/wp-phpunit` library.
The bootstrap blocks real mail and external WordPress HTTP requests. All
addresses and input data in the tests are synthetic.

The `wire` group sends the real sender encoder's body to a temporary PHP
loopback server. PHP parses `$_POST` and `$_FILES`; the fixture transfers those
parsed values and file bytes into native WordPress dispatch. It tests numeric
keys, nested nulls, empty arrays, mixed gallery order, and binary preservation.
This is parser-to-native-dispatch verification, not authentication or routing
through a deployed web server. A sandbox must permit HTTP to `127.0.0.1`.

If the sandbox permits that address through its configured proxy but blocks
direct loopback connections, clear proxy exclusions for the test command:
`env NO_PROXY= no_proxy= composer test:integration`. Keep the sandbox proxy
configured and its domain allowlist active. This does not authorize access
to a denied destination.

For a database-only run when loopback HTTP is unavailable:

```sh
vendor/bin/phpunit --configuration phpunit-integration.xml --exclude-group wire
```

Such a run does **not** complete the wire gate. Missing sender configuration
also skips that gate and must be reported.

Known upstream compatibility: ACF's select schema uses `int` instead of
`integer`. Native tests explicitly expect WordPress's corresponding notice
only for `acf[contact_method]`; other notices still fail. `NativeTestCase`
bridges WordPress's legacy expected-notice reader to PHPUnit 11 without
disabling notice assertions. Upstream PHPUnit deprecation reports remain visible.

## Deploy

Install production dependencies with `composer install --no-dev --prefer-dist`
(also used by `build.php`). Do not deploy a development `vendor/` directory:
Mago and the test tools are not runtime dependencies.

## Roadmap

See the [open issues][issues-url] for a list of proposed features (and known issues).

## Contributing

Contributions are what make the open source community such an amazing place to be learn, inspire, and create. Any contributions you make are **greatly appreciated**.

1. Fork the Project
2. Create your Feature Branch (`git checkout -b feature/AmazingFeature`)
3. Commit your Changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the Branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## License

Distributed under the [MIT License][license-url].

## Acknowledgements

- [othneildrew Best README Template](https://github.com/othneildrew/Best-README-Template)


<!-- MARKDOWN LINKS & IMAGES -->
<!-- https://www.markdownguide.org/basic-syntax/#reference-style-links -->
[contributors-shield]: https://img.shields.io/github/contributors/helsingborg-stad/api-sponsor-manager.svg?style=flat-square
[contributors-url]: https://github.com/helsingborg-stad/api-sponsor-manager/graphs/contributors
[forks-shield]: https://img.shields.io/github/forks/helsingborg-stad/api-sponsor-manager.svg?style=flat-square
[forks-url]: https://github.com/helsingborg-stad/api-sponsor-manager/network/members
[stars-shield]: https://img.shields.io/github/stars/helsingborg-stad/api-sponsor-manager.svg?style=flat-square
[stars-url]: https://github.com/helsingborg-stad/api-sponsor-manager/stargazers
[issues-shield]: https://img.shields.io/github/issues/helsingborg-stad/api-sponsor-manager.svg?style=flat-square
[issues-url]: https://github.com/helsingborg-stad/api-sponsor-manager/issues
[license-shield]: https://img.shields.io/github/license/helsingborg-stad/api-sponsor-manager.svg?style=flat-square
[license-url]: https://raw.githubusercontent.com/helsingborg-stad/api-sponsor-manager/master/LICENSE
[product-screenshot]: images/screenshot.png
