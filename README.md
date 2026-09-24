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
- [About API Sponsor Manager](#about-api-sponsor-manager)
  - [Built With](#built-with)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
- [Usage](#usage)
- [Multipart REST upload protocol (v4)](#multipart-rest-upload-protocol-v4)
- [Testing](#testing)
  - [Standalone tests](#standalone-tests)
- [Deploy](#deploy)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)
- [Acknowledgements](#acknowledgements)

## About API Sponsor Manager

[![API Sponsor Manager Screen Shot][product-screenshot]](https://example.com)

Here's a blank template to get started:

### Built With

- PHP
- NPM
- Webpack
- Modularity

## Getting Started

To get a local copy up and running follow these simple steps.

### Prerequisites

This is an example of how to list things you need to use the software and how to install them (mac os).

- composer

```sh
brew install composer
```

- npm

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

## Multipart REST upload protocol (v4)

The receiver accepts native collection `POST` creates for
`/wp/v2/sponsor-assignments` and `/wp/v2/sponsor-offerings`. The image field
must be an ACF `image` field exposed in the native REST schema. No opt-in
setting is required.

Send `X-ACF-Rest-Upload-Version: 4`. Requests without that header pass through
untouched and keep native JSON behavior; a headerless native create still
returns 201. A present header whose value is not `4`, including an empty value,
returns 400 `acf_rest_upload_unsupported_version`. Non-multipart requests, item
routes, and endpoints that do not keep native post creation return 400
`acf_rest_upload_unsupported_request`.

Send ordinary fields directly with PHP-compatible names such as `title`,
`acf[location][lat]`, and `acf[image]`. An uploaded image uses the same
top-level `acf[image]` destination name as its binary part. Existing attachment
IDs remain ordinary `acf[image]` values. A file may target only an exposed
top-level ACF image field; an unsupported field returns 400
`acf_rest_upload_invalid_reference`. A body value and file for the same image
field returns 400 `acf_rest_upload_ambiguous_image`; malformed file bags return
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
sends its normal notification after metadata saving. A version-4 completion
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
composer test
composer lint
```

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
