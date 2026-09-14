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

Two separate suites exist. All commands below are meant to be run by a human
in a prepared environment.

Unit suite (`source/tests/php`): standalone, no WordPress needed, uses Brain
Monkey + Mockery.

> **Blocker:** `composer.lock` does not yet contain the dev dependencies
> `brain/monkey` and `mockery/mockery`, although the unit suite (via
> `PluginTestCase`) requires them. The lock must be regenerated once with
> `composer update` before any test can boot; this is intentionally not done
> by hand.

```sh
composer update            # once: writes brain/monkey + mockery into composer.lock
composer lint              # mago lint
composer test              # unit suite (vendor/bin/phpunit --testsuite unit)
```

Integration suite (`source/tests/integration`): runs against a real
WordPress test library plus ACF and creates real media. It is excluded from
the unit run and requires the WordPress core test bootstrap:

```sh
export WP_TESTS_DIR=/path/to/wordpress-tests/lib
export ACF_PLUGIN_FILE=/path/to/advanced-custom-fields-pro/acf.php
composer test:integration  # vendor/bin/phpunit --testsuite integration
```

The bootstrap activates ACF (when `ACF_PLUGIN_FILE` is set) and this plugin
via `muplugins_loaded`, so no extra test plugin is needed.

## Deploy

Instructions for deploys.

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
