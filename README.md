# LW Registry Platform

Loveworld Registry is a Laravel and Livewire-based platform for managing business registration, verification, payments, document workflows, and public directory discovery.

## Overview

The platform supports three primary audiences:

- Applicants who register businesses, submit documents, complete payments, and track progress.
- Registry staff who review applications, verify submissions, manage compliance items, and process operational tasks.
- Public visitors who search for verified organizations and review business profiles.

## Key capabilities

- End-to-end registration journeys for SBO applicants
- Payment and fee handling for applications and submissions
- Document upload and review workflows
- Verification and approval processes for registry operations
- Public-facing directory and profile discovery
- Notification-driven communications for status updates and follow-ups

## Tech stack

- PHP 8.4
- Laravel 12
- Livewire and Volt
- Vite and Tailwind CSS
- Reverb and Laravel Echo for real-time features
- Spatie Media Library for file management

## Local development

### Prerequisites

- PHP 8.4 or newer
- Composer
- Node.js and npm
- A local database configured in your environment

### Setup

1. Clone the repository and change into the project directory.
2. Install PHP dependencies:
   ```bash
   composer install
   ```
3. Install front-end dependencies:
   ```bash
   npm install
   ```
4. Copy the environment file and configure your local settings:
   ```bash
   cp .env.example .env
   ```
5. Generate the application key:
   ```bash
   php artisan key:generate
   ```
6. Run database migrations:
   ```bash
   php artisan migrate
   ```
7. Start the development environment:
   ```bash
   composer run dev
   ```

This will launch the Laravel app, queue worker, Vite dev server, and log viewer together.

### Useful commands

```bash
php artisan test
composer test
npm run build
php artisan queue:listen --tries=1
```

## Project structure

- app/: application logic, controllers, models, services, and Livewire components
- database/: migrations, seeders, and factories
- resources/: views, CSS, JavaScript, and frontend assets
- routes/: web, admin, and API route definitions
- docs/: product, architecture, payment, and notification documentation
- tests/: feature and unit test coverage

## Documentation

The repository includes detailed documentation in the docs directory, including:

- docs/platform-architecture.md
- docs/registration-fees-guide.md
- docs/notification-operations.md
- docs/premium-ui-refinement-audit.md

## Notes

If you are using Laravel Herd on macOS, the project is already configured in herd.yml for a smooth local setup.
