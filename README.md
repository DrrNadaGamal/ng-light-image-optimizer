# NG Light Image Optimizer

WordPress plugin that converts uploaded JPG and PNG images (and every generated size) to WebP, and to AVIF where the server can encode it. Originals are never deleted. One click restores the site to its original images.

Product direction, scope and delivery: Nada Gamal.

## Features
- Converts on upload, all registered sizes included.
- Keeps a converted file only if it is non-empty and smaller than the original.
- Serves WebP (or AVIF when the browser accepts it) in image URLs and srcset.
- Bulk convert for the existing library. Restore all removes every converted file.
- Settings: WebP, AVIF, quality 40-100, serve on/off. Stats: images optimized, space saved.
- Labels in English, Arabic (RTL), French and German. FR and DE are machine translations and need review.

## Requirements
WordPress 6+, PHP 7.4+, Imagick or GD with WebP (AVIF needs an AVIF-capable encoder).

## Install
Upload the folder to `wp-content/plugins/`, activate, open Media > Image Optimizer.

## Tests
`tests/run-tests.php` runs inside WordPress (25 checks: conversion of all sizes, size guard, URL and srcset swap, AVIF negotiation, PNG transparency, restore, bulk, delete cleanup, non-image upload, 4-language admin page, input handling). Last run: 25 passed, 0 failed on WordPress (latest) with PHP 8.2 in WordPress Playground.

## Honest limits
- AVIF encoding returned an empty file in the Playground test environment, so AVIF conversion is not verified. WebP is verified.
- Smoke-tested with real Elementor 4.4.2: an Elementor image widget renders with WebP URLs and srcset, no PHP errors. Not tested in the Elementor editor, with Bricks, or on a live host.
- Converted files are named `photo.jpg.webp` next to the original. Offloading them to cloud storage is a separate plugin (NG Cloud Offloader).
- Not for pages cached by a CDN that ignores the Accept header when AVIF is on; turn AVIF off in that case.
