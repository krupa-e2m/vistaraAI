# E2M admin theme — "Onyx Lime"

E2M gives the admin its own identity instead of tinting the vendored engine's design system
(which otherwise reads as SproutOS). Identity: **true-black surfaces, neon-lime accent
(`#C6FF00`), Space Grotesk display type, and a TOP horizontal nav** (the engine's left sidebar is
reflowed into a top tab bar via CSS).

## Owned by e2m-connect (survives re-vendoring the engine)
- `src/Engine/Theme.php` — enqueues the theme stylesheet after the engine design system on the hub
  hooks (`toplevel_page_e2mconnect`, `toplevel_page_e2m-mcp`). Booted from `src/Core/Plugin.php`.
- `assets/admin/css/e2m-admin-theme.css` — the whole look:
  - `:root` token overrides (lime accent + black surfaces + every semantic tint → dark, so there
    are no light patches). The engine design system is ~99% token-driven, so this recolors all of it.
  - Typography: loads Space Grotesk + Inter (Google Fonts `@import`); Space Grotesk for
    titles/nav/buttons, Inter for body.
  - **Top-nav reflow:** `.so-body{flex-direction:column}`, `.so-sidebar` → full-width top bar,
    `.so-sidebar-nav` → horizontal, active tab = lime underline; left active-bar, group labels,
    hamburger and overlay hidden.
  - Flat crisp components (6px radius, 1px borders, no shadows), lime primary buttons with **dark
    text** (lime is bright), lime focus rings, dark tables/inputs/modals, and the dashboard styles.
- `assets/admin/img/e2m-mark.png` — the official E2M mark (white "E2M" icon, from `e2m logo/Vector.png`).
  On the hub it's painted as a CSS background on `.so-header-logo` with an "E2M Connect" wordmark added via
  `::before/::after` (engine `<img>` hidden), so the lockup shows on every hub tab; the standalone Custom
  Abilities page renders `.e2m-abilities-brand` (mark `<img>` + wordmark `<span>`).
  (`assets/admin/img/e2m-logo.svg` is the older wordmark SVG, kept for reference, no longer wired up.)
- `src/Admin/Dashboard.php` + dashboard styles — the landing screen ("Home" tab).
- `src/Admin/AbilitiesAdmin.php` + `assets/admin/css/e2m-abilities-admin.css` — the standalone
  Custom Abilities page, themed to match (scoped via the `e2m-abilities-dark` body class).

## Engine edits to reapply after a re-vendor (`engine/e2m-core/admin-pages.php`)
1. **Header bar** (~line 483): a version chip (`.so-header-version`) and a status chip
   `<div class="so-header-status"><span class="dot"></span>MCP ready</div>`.
2. **Sidebar nav** (~line 498): an `$e2m_nav_groups` map emitting `.so-sidebar-group` headings.
   (These are hidden in the top-nav theme but harmless; can be dropped if re-vendoring.)
3. **Header logo `<img>` src** (~line 431): `$logo_url` points at
   `assets/admin/img/e2m-mark.png` (not the nonexistent `logo.png`) so the `<img>` is a valid
   fallback if the theme CSS background ever fails to load.
4. **MCP Connect client switcher** (~line 2655): the `$clients` list is trimmed to only
   `e2m-plugin` + `custom`, and `$default_client` is forced to `e2m-plugin`. The eight removed
   client panels (Claude Desktop/Code, Cursor, VS Code, Windsurf, Zed, Cline, Continue) and their
   troubleshooting `<ul>`s are fenced off with `<?php if (false): ?> … <?php endif; ?>` rather than
   deleted, so a re-vendor diff stays small. To restore a client, add it back to `$clients` and
   move its panel/troubleshoot block outside the `if (false)` fence.

## Tuning
Palette + font live at the top of `e2m-admin-theme.css`. To change the accent, edit
`--so-brand-primary` (and the lime gradient / dark button-text rules). To change the typeface,
edit the `@import` and the Space Grotesk font-family block.
