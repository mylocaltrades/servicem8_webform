# ServiceM8 Webform Integration for Drupal

Integrates Drupal Webforms with ServiceM8 to automatically create jobs/quotes from form submissions.

## Features
- ✅ Automatic job/quote creation in ServiceM8
- ✅ Contact duplicate detection
- ✅ File uploads (photos/documents)
- ✅ Lead source tracking with badges
- ✅ Loading overlay for better UX
- ✅ Field mapping configuration

## Requirements
- Drupal 9/10/11
- Webform module
- ServiceM8 account with API access
- PHP 7.4 or higher

## Installation
1. Download and place in `/modules/custom/servicem8_webform`
2. Enable the module: `drush en servicem8_webform`
3. Clear cache: `drush cr`

## Configuration
1. Get your ServiceM8 API key from Settings > Integrations in ServiceM8
2. Go to `/admin/config/services/servicem8` and enter your API key
3. Add the ServiceM8 handler to any webform
4. Map your form fields to ServiceM8 fields

## License
GPL-2.0-or-later
