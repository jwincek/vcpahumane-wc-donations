# Starter Shelter Donations

Animal shelter donations, memberships, and memorials management for WordPress 6.9+ using the Abilities API, Block Bindings, and the Interactivity API.

## Requirements

- WordPress 6.9+
- PHP 8.1+
- WooCommerce 9.0+

## Installation

1. Clone or download this repository into `wp-content/plugins/starter-shelter-v2/`.
2. Run `composer install` (production) or `composer install --dev` (development).
3. Activate the plugin in **Plugins → Installed Plugins**.
4. Activate WooCommerce if not already active.
5. Visit **Shelter Donations → Settings → Products** to set up donation products.

## Development Setup

This plugin uses [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) for local development:

```bash
# Start the environment (WP 6.9 + WooCommerce pre-installed)
npx wp-env start

# Run PHPCS linting
composer lint

# Auto-fix what PHPCS can
composer lint:fix
```

## Architecture

The plugin follows a **config-driven, layered architecture**:

- **Config Layer** — JSON files in `config/` define entities, abilities, products, emails, and post types.
- **Infrastructure Layer** — Reusable PHP classes (`Config`, `Entity_Hydrator`, `Query`, `CPT_Registry`) read config and do the heavy lifting.
- **Abilities Layer** — Discrete operations registered via the WordPress 6.9+ Abilities API with JSON Schema validation.
- **Consumer Layer** — Thin integrations (WooCommerce, blocks, admin pages) that delegate to abilities.

### Key Directories

```
config/              → JSON definitions (source of truth for entities, abilities, products, etc.)
includes/abilities/  → Ability callbacks and registration
includes/admin/      → Admin pages, meta boxes, reports
includes/blocks/     → Block bindings, interactivity stores, editor registration
includes/core/       → Config loader, entity hydrator, query builder, CPT registry
includes/emails/     → Config-driven WooCommerce email integration
includes/woocommerce/→ Order handler, product mapper, checkout fields, cart, My Account
blocks/              → Block definitions (block.json, render.php, edit.js, style.css)
templates/           → Email templates (HTML and plain text)
```

### Custom Post Types

| Post Type | Slug | Visibility |
|-----------|------|------------|
| Donation | `sd_donation` | Private |
| Membership | `sd_membership` | Private |
| Memorial | `sd_memorial` | Public |
| Donor | `sd_donor` | Private |

### WooCommerce Products

The plugin creates four variable products on activation, mapped from `config/products.json`:

- **General Donations** — allocation-based tiers
- **In Memoriam Donations** — memorial tribute tiers
- **Individual Memberships** — tiered annual memberships
- **Business Memberships** — tiered annual business memberships

## Extending the Plugin

### Adding a new entity

1. Add the entity definition to `config/entities.json`.
2. Add a post type to `config/post-types.json`.
3. Add abilities to `config/abilities.json`.
4. Write ~50 lines of ability callbacks.

### Adding a new email

1. Add the email definition to `config/emails.json`.
2. Create an HTML and plain-text template in `templates/emails/`.

See `modern-wordpress-plugin-development-guide.md` in the project docs for the full developer guide.

## License

GPL-2.0-or-later. See WordPress [license](https://wordpress.org/about/license/) for details.
