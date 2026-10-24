# WP DesignChecker

WP DesignChecker is a visual quality assurance and regression testing project for WordPress websites.

The goal is to help website designers, developers, and administrators identify unexpected visual changes after theme updates, plugin updates, CSS modifications, and content changes.

## The Problem

WordPress websites can experience unintended layout changes after routine maintenance. These issues may go unnoticed until a visitor or client reports them.

WP DesignChecker aims to make visual checks more systematic by capturing webpage screenshots, comparing them against approved baselines, and highlighting differences for review.

## Planned Features

- Automated webpage screenshot capture
- Desktop and mobile viewport testing
- Approved visual baselines
- Screenshot comparison and difference highlighting
- Visual change metrics
- WordPress admin review dashboard
- Comparison history and baseline management

## Technology Stack

- **PHP:** WordPress plugin development
- **Python:** Screenshot automation and image processing
- **Playwright:** Browser automation
- **Pillow:** Image comparison
- **JavaScript:** Dashboard interactions
- **MySQL:** WordPress-side data storage

## Project Structure

```text
wp-designchecker/
├── checker-plugin/
├── checker-engine/
├── docs/
├── .gitignore
└── README.md
```

## Development Status

**Version:** 0.1.0 — Initial development

The WordPress plugin foundation and local development environment have been established. Screenshot automation, visual comparison, and dashboard functionality are under development.

## Author

Developed by Favogab.