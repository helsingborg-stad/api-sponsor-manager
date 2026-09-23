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
- [Multipart REST upload protocol (v3)](#multipart-rest-upload-protocol-v3)
- [Testing](#testing)
- [Deploy](#deploy)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)
- [Acknowledgements](#acknowledgements)

## About API Sponsor Manager

Here's a blank template to get started:

### Built With

* PHP
* NPM
* Vite
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

## Multipart REST upload protocol (v3)

The receiver accepts native collection `POST` creates for
`/wp/v2/sponsor-assignments` and `/wp/v2/sponsor-offerings`. The image field
must be an ACF `image` field exposed in the native REST schema. No opt-in
setting is required.

Send `X-ACF-Rest-Upload-Version: 3`. Requests without that header pass through
untouched and keep native JSON behavior; a headerless native create still
returns 201. A present header whose value is not `3`, including an empty value,
returns 400 `acf_rest_upload_unsupported_version`. Non-multipart requests, item
routes, and endpoints that do not keep native post creation return 400
`acf_rest_upload_unsupported_request`.

The authoritative field values live in the `_acf_rest_payload` JSON body
parameter. The payload is limited to 1 MiB; a larger payload returns 413
`acf_rest_upload_too_large`. A `$file:<key>` string at the top level of the
`acf` object references one `_acf_rest_files[<key>]` binary part. A reference
that is not top-level or is not an exposed image field returns 400
`acf_rest_upload_invalid_reference`. A missing or malformed binary part returns
400 `acf_rest_upload_invalid_parts`.

Per-file size is limited to `min(8 MiB, wp_max_upload_size())`; the aggregate
upload is limited to 8 MiB. Exceeding either returns 413
`acf_rest_upload_too_large`. Field size, width, and height limits also return
400 or 413. An invalid, unreadable, or empty binary part returns 400
`acf_rest_upload_invalid_file`. A disallowed image type or a field MIME
mismatch returns 415 `acf_rest_upload_invalid_file_type`.

Authorization failures return 403 `acf_rest_upload_forbidden`. Storage failures
return a controlled 500 `acf_rest_upload_storage_failed`. Native authorization,
schema validation, and image limits still apply.

The receiver has no idempotency, replay, durable history, or durable
notification delivery. Separate submissions can create duplicate posts, images,
and notifications. The receiver cleans up resources owned by an observed failed
request. A crash can leave partial data.

The local Database handler keeps `ModularityFrontendForm/afterInsertPost`. It
sends its normal notification after metadata saving. A version-3 completion
notification sends only after the receiver completes the exact request
successfully.

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
- PHP with mysqli, GD, and cURL.
- An isolated licensed ACF PRO installation, not a live plugin checkout.

**The WordPress test bootstrap recreates tables. Never supply a live database.**

```sh
export SPONSOR_INTEGRATION_TESTS=1
export SPONSOR_TEST_DB_NAME=sponsor_test_receiver
export SPONSOR_TEST_DB_HOST=localhost:/path/to/isolated/mysql.sock
export SPONSOR_TEST_DB_USER=test_user
export SPONSOR_TEST_DB_PASSWORD=test_password
export ACF_PLUGIN_FILE=/path/to/isolated/advanced-custom-fields-pro/acf.php
composer test:integration
```

`WP_TESTS_DIR` defaults to the installed `vendor/wp-phpunit/wp-phpunit` library.
The bootstrap blocks real mail and external WordPress HTTP requests. All
addresses and input data in the tests are synthetic.

Known upstream compatibility: ACF's select schema uses `int` instead of
`integer`. Native tests explicitly expect WordPress's corresponding notice
only for `acf[contact_method]`; other notices still fail. `NativeTestCase`
bridges WordPress's legacy expected-notice reader to PHPUnit 11 without
disabling notice assertions. Upstream PHPUnit deprecation reports remain visible.

## Deploy

Install production dependencies with `composer install --no-dev --prefer-dist`
(also used by `build.php`). Do not deploy a development `vendor/` directory:
Mago and the test tools are not runtime dependencies.

Run `php build.php --cleanup` from the root of a disposable source copy only.
Cleanup removes build inputs. Do not run it in a deployed plugin or a working
checkout. The build uses the committed npm lockfile; browser-data and dependency
updates require a separate reviewed change. The ACF export manager is required
by the plugin bootstrap and must be present in the production autoloader.

Before accepting an artifact, verify generated assets and production autoloading
without a development vendor directory. Check that plugin and dependency tests,
PHPUnit configuration, development tools, local verification helpers, and
credentials are absent. Service contracts whose names end in `Test.php` are
runtime code, not test suites. Keep build credentials outside the source copy.
Record source and lockfile identities, artifact checksums, commands, and audit
findings. A successful local build does not establish production consumer
compatibility, effective web/proxy upload limits, or deployment approval.

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
