# Setup Guide

This document describes how to setup your development environment, so that it is ready to develop and test the ConvertKit WordPress Libraries.

Suggestions are provided for the LAMP/LEMP stack and Git client are for those who prefer the UI over a command line and/or are less familiar with 
WordPress, PHP, MySQL and Git - but you're free to use your preferred software.

## Setup

### LAMP/LEMP stack

Any Apache/nginx, PHP 7.x+ and MySQL 5.8+ stack running WordPress.  For example, but not limited to:
- Local by Flywheel (recommended)
- Docker
- MAMP
- WAMP
- VVV

### Composer

If [Composer](https://getcomposer.org) is not installed on your local environment, enter the following commands at the command line to install it:

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === '906a84df04cea2aa72f40b5f787e49f22d4c2f19492ac310e8cba5b96ac8b64115ac402c8cd292b8a03482574915d1a8') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer
```

Confirm that installation was successful by entering the `composer` command at the command line

### Clone Repository

Using your preferred Git client or command line, clone this repository into the `wp-content/plugins/` folder of your local WordPress installation.

If you prefer to clone the repository elsewhere, and them symlink it to your local WordPress installation, that will work as well.

If you're new to this, use [GitHub Desktop](https://desktop.github.com/) or [Tower](https://www.git-tower.com/mac)

### Create Test Database

Create a blank `test` database in MySQL, with a MySQL user who can read and write to it.

### Configure Testing Environment

Copy the `.env.example` file to `.env.testing` in the root of this repository, changing folder and database credentials as necessary.

#### Codeception

Create a `codeception.yml` file in the root of the repository, with the following contents:
```yaml
params:
    - .env.testing
```

This tells Codeception to read the above `.env.testing` file when testing on the local development enviornment.

#### PHPStan

Copy the `phpstan.neon.example` file to `phpstan.neon` in the root of this repository, changing the `scanDirectories` to point to your
local WordPress installation.

### Install Packages

In the Plugin's directory, at the command line, run `composer install`.

This will install packages used in the process of development (i.e. testing, coding standards):
- wp-browser
- Codeception
- PHPStan
- PHPUnit
- PHP_CodeSniffer

How to use these is covered later on, and in the [Testing Guide](TESTING.md)

### Running the Test Suite

In a Terminal window, build and run the tests to make sure there are no errors and that you have correctly setup your environment:

```bash
vendor/bin/codecept build
vendor/bin/codecept run wpunit
```

![Codeception Test Results](/.github/docs/codeception.png?raw=true)

Don't worry if you don't understand these commands; if your output looks similar to the above screenshot, and no test is prefixed with `E`, 
your environment is setup successfully.

### Running CodeSniffer

In the Plugin's directory, run the following command to run PHP_CodeSniffer, which will check the code meets WordPress' Coding Standards:

```bash
vendor/bin/phpcs ./ -v -s
```

![Coding Standards Test Results](/.github/docs/coding-standards.png?raw=true)

Again, don't worry if you don't understand these commands; if your output looks similar to the above screenshot, with no errors, your environment
is setup successfully.

### Running PHPStan

In the Plugin's directory, run the following command to run PHPStan, which will perform static analysis on the code, checking it meets required
standards, that PHP DocBlocks are valid, WordPress action/filter DocBlocks are valid etc:

```bash
vendor/bin/phpstan --memory-limit=1G
```

![PHPStan Test Results](/.github/docs/phpstan.png?raw=true)

Again, don't worry if you don't understand these commands; if your output looks similar to the above screenshot, with no errors, your environment
is setup successfully.

### Next Steps

With your development environment setup, you'll probably want to start development, which is covered in the [Development Guide](DEVELOPMENT.md)