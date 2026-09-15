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
- [Upload provider selection](#upload-provider-selection)
- [Multipart REST upload protocol (v2)](#multipart-rest-upload-protocol-v2)
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

## Upload provider selection

The host selects one receiver at `init` priority 20. It applies
`AcfRestUpload/provider` to `null`. Null selects the embedded receiver.
The earlier `ApiSponsorManager/enableCreateUploads` opt-in no longer controls selection.

Register an external provider filter during plugin loading, or at an `init`
priority below 20. Registering it after selection has no effect on that request.
Return an array with these values:

- `api_version`: integer `1`.
- `protocol_versions`: an array containing integer `2`.
- `boot`: a callable that accepts the injected `WpService` and `AcfService`, in that order.

Provider API version 1 is distinct from HTTP upload protocol version 2.
The host validates the descriptor before it calls `boot`. Repeated selection
on the same bootstrap does not call `boot` again. Filters must compose one
descriptor. The host does not maintain a provider registry.

The selected provider uses these public hooks:

- `AcfRestUpload/destinations` filters an empty array. Each route maps to
  `post_type` and `image_field`. The host registers both sponsor collections.
- `AcfRestUpload/imagePolicy` filters resolved native limits. It also receives
  the field and exact request. Sponsor images retain their native field limits.
  A provider must enforce field eligibility and authorization separately.
  Policy filters may tighten limits but cannot remove native restrictions.
- `AcfRestUpload/created` receives the post ID, attachment ID or null, and exact
  request. Emit it only after complete success. The sponsor consumer sends
  queued notifications through this action.

Only the null fallback loads files from `source/php/AcfRestUpload/`.
External selection works without that directory. Keep the host bootstrap
`source/php/UploadProvider.php` and the sponsor integration files available.

An incompatible descriptor never selects the fallback. An incompatible
descriptor or missing fallback writes a diagnostic to the PHP error log.
Version-2 requests on registered collection and item route families receive
`acf_rest_upload_unavailable` with HTTP 503. Requests without the version-2
header and unrelated routes remain unchanged.

Version 2 supports native collection creates with at most one top-level image.
It does not provide idempotency. Repeated submissions can create duplicate
posts, images, and notifications. Observed failures attempt request-owned
cleanup. A process crash can leave partial data. Notification delivery is not
guaranteed across a crash.

## Multipart REST upload protocol (v2)

The embedded receiver supports native collection `POST` creates for
`/wp/v2/sponsor-assignments` and `/wp/v2/sponsor-offerings`. Each destination
accepts one top-level image field. The image field must be REST exposed and
must enable **Allow multipart REST upload**.

Send `X-ACF-Rest-Upload-Version: 2`. A value of `$file:<key>` in the destination
image field references exactly one `_acf_rest_files[<key>]` binary part. The
receiver accepts existing image IDs when native validation accepts them.

The receiver rejects version 1 and all other protocol versions on registered
route families. It rejects item routes, non-POST methods, galleries, nested
references, generic files, reserved null or empty markers, and malformed parts
before it creates media or saves a post. Requests without the header and
unregistered routes keep native JSON behavior.

The receiver preserves native authorization, field validation, and image
limits. It accepts at most 8 MiB of uploaded image data and 1 MiB of parameters.
Invalid MIME returns 415. Size limits return 413. Unsupported requests return
400. Storage and cleanup failures return controlled 500 errors.

The receiver has no idempotency, replay, durable history, update rollback, or
durable notification delivery. Separate submissions can create duplicate posts,
images, and notifications. The receiver cleans up resources owned by an
observed failed request. A crash can leave partial data.

The local Database handler keeps `ModularityFrontendForm/afterInsertPost`.
It sends its normal notification after metadata saving. Version-2 notifications
send only after the receiver completes the exact request successfully.

Existing legacy post metadata and option records remain in the database. They
are inert under version 2. A rollback to the previous receiver may depend on
those records. This release does not delete records or install a wildcard
cleanup migration.

The frontend sender keeps its version-1 multipart profile for unrelated
receivers. Do not select that profile for these sponsor routes.

### Coordinated transition and rollback

1. Prepare the receiver and sender artifacts.
2. Stop sponsor submissions.
3. Deploy both artifacts.
4. Select the sender `multipart-create` profile.
5. Run the agreed smoke checks.
6. Resume sponsor submissions.

Writing this procedure does not authorize its execution. To roll back, stop
sponsor submissions, restore both previous artifacts, select the previous
sender profile, run rollback smoke checks, then resume submissions. Historical
records remain available for the previous receiver. Rollback does not remove
duplicates or recover data from a process crash.

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
