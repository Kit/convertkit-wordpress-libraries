# Deployment Guide

This document describes the workflow for deploying updates from GitHub to a published package.

## Merge Pull Requests

Merge the approved Pull Request(s) to the `main` branch.

An *approved* Pull Request is when a PR passes all tests **and** has been approved by **one or more** reviewers.

## Create a New Release

[Create a New Release](https://github.com/Kit/convertkit-wordpress-libraries/releases/new), completing the following:

- Choose a tag: Click this button and enter the new version number (e.g. `1.0.1`)
- Release title: The version number (e.g. `1.0.1`)
- Describe this release: Enter a bullet point list of changes.  A good example and format:

```
* Added: API Class: `get_products()` function.
* Fix: API Class: Return array instead of WP_Error when no resources exist.
```

## Publish the Release

When you're happy with the above, click `Publish Release`.

## Use the Published Release in Kit Plugins

To use the published release in Kit Plugins, **in the Kit Plugin** edit the `composer.json` file to use the tagged version:

```
"require": {
    "convertkit/convertkit-wordpress-libraries": "1.0.1"
},
```

The specific version number tells Composer that we want to use a specific tag of this repository in our Kit Plugin. [Read more](https://getcomposer.org/doc/articles/versions.md#vcs-tags-and-branches).