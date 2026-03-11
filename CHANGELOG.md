# Changelog

All notable changes to Starter Shelter Donations will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-XX-XX

### Added
- Config-driven architecture with JSON definitions for entities, abilities, products, emails, and post types.
- Core infrastructure: Config loader with `$ref` resolution, Entity Hydrator, Query builder, CPT Registry.
- WordPress 6.9+ Abilities API integration with 16 registered abilities across donations, memberships, memorials, donors, and reports.
- WooCommerce integration: Product Mapper, Order Handler, Checkout Fields, Cart Handler, My Account endpoints.
- Four variable WooCommerce products: General Donations, In Memoriam Donations, Individual Memberships, Business Memberships.
- Custom post types: `sd_donation`, `sd_membership`, `sd_memorial`, `sd_donor`.
- Nine custom blocks: donation-form, membership-form, memorial-form, memorial-wall, memorial-archive, campaign-card, campaign-progress, donor-dashboard, donor-stats.
- Block Bindings sources for shelter post data, post meta, term data, and pattern overrides.
- Interactivity API stores for memorials, donations, memberships, campaigns, and donor dashboard.
- Config-driven WooCommerce email integration with HTML and plain-text templates.
- Admin pages: Settings, Reports, Dashboard Widget, Logo Moderation, Import/Export, Legacy Order Sync, Data Integrity, Activity Log.
- Auto-generated meta boxes from entity config.
- Custom list table columns for all CPTs.
- CSV import/export with validation and legacy memorial parsing.
- Legacy order sync tooling for migrating historical WooCommerce orders.
- Membership renewal reminder cron job.
- REST API controller for ability-backed endpoints.
- Block editor enhancements: custom block category, editor-only assets.
- Single memorial template (block-based and classic fallback).
