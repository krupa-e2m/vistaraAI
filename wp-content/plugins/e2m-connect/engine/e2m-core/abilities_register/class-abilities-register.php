<?php
/**
 * Register E2M MCP ability categories and ability loaders.
 *
 * @link https://posimyth.com/
 * @since 1.0.0
 * @package E2M Connect_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Ability registrar bootstrap.
 *
 * @since 1.0.0
 */
class E2M_Abilities_Register {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Return the Elementor plugin instance.
	 *
	 * @return \Elementor\Plugin
	 */
	public static function elementor() {
		return \Elementor\Plugin::$instance;
	}

	/**
	 * Return the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'e2m_register_ability_categories' ], 1 );
		add_action( 'wp_abilities_api_init', [ $this, 'e2m_manage_ability_file' ], 1 );
	}

	/**
	 * Register every ability category E2M Connect exposes.
	 *
	 * Categories are grouped by surface area (content, taxonomies, menus, page
	 * builder, etc.) so the admin UI can present clean toggles. New categories
	 * can be added here without touching the loader.
	 */
	public function e2m_register_ability_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		// Bridge stays available even when E2M Connect is globally disabled, so
		// that discovery/inspection tools never vanish from MCP clients.
		$this->maybe_register_category( 'e2m-bridge', 'E2M Bridge', 'Bridge abilities for discovery, inspection, and dispatch.' );

		if ( ! e2m_engine_is_enabled() ) {
			return;
		}

		// Core categories that exist today.
		$this->maybe_register_category( 'e2m-content', 'E2M Content', 'Page, post, custom post type, media, and comment abilities.' );
		$this->maybe_register_category( 'e2m-theme', 'E2M Theme', 'Theme and child theme editing abilities.' );
		$this->maybe_register_category( 'e2m-filesystem', 'E2M Filesystem', 'Filesystem and sandbox abilities.' );
		$this->maybe_register_category( 'e2m-code-execution', 'E2M Code Execution', 'Server-side PHP execution abilities.' );

		// WordPress-surface categories added in the core expansion pass.
		$this->maybe_register_category( 'e2m-taxonomies', 'E2M Taxonomies', 'Taxonomy and term abilities for categories, tags, and custom taxonomies.' );
		$this->maybe_register_category( 'e2m-users', 'E2M Users', 'User lifecycle abilities (list, get, create, update, delete).' );
		$this->maybe_register_category( 'e2m-menus', 'E2M Menus', 'Navigation menu abilities for menus, menu items, and menu locations.' );
		$this->maybe_register_category( 'e2m-admin', 'E2M Admin', 'Plugin lifecycle and site option abilities.' );
		$this->maybe_register_category( 'e2m-context', 'E2M Context', 'Site introspection abilities (site context, theme docs, builder info, server compatibility).' );

		// Page builder surfaces (files ship empty in this pass but categories
		// are reserved up front so we never break discovery when abilities are
		// added incrementally).
		$this->maybe_register_category( 'e2m-builder', 'E2M Page Builder', 'Builder-agnostic abilities that operate across Elementor, Gutenberg, and Bricks.' );
		$this->maybe_register_category( 'e2m-elementor', 'E2M Elementor', 'Elementor-specific intelligence abilities.' );
		$this->maybe_register_category( 'e2m-gutenberg', 'E2M Gutenberg', 'Gutenberg block editor abilities.' );
		$this->maybe_register_category( 'e2m-bricks', 'E2M Bricks', 'Bricks Builder specific abilities.' );

		// Cross-cutting categories.
		$this->maybe_register_category( 'e2m-analysis', 'E2M Analysis', 'SEO, performance, and readability audit abilities.' );
		$this->maybe_register_category( 'e2m-migrations', 'E2M Migrations', 'Cross-builder content migration abilities.' );
		$this->maybe_register_category( 'e2m-safety', 'E2M Safety', 'Safety controls: audit log, protected posts, confirmation tokens, safety status.' );
		$this->maybe_register_category( 'e2m-memory', 'E2M Memory', 'Persistent, audited memory subsystem: save, get, list, search, archive memories used as both site context and slug-callable playbooks.' );

		// ACF integration.
		$this->maybe_register_category( 'e2m-acf', 'E2M ACF', 'Advanced Custom Fields abilities — read/write field values, manage field groups, and control options pages.' );

		// JetEngine integration.
		$this->maybe_register_category( 'e2m-jetengine', 'E2M JetEngine', 'JetEngine abilities — meta boxes, custom fields, Custom Content Types, and their records.' );

		// Meta Box integration.
		$this->maybe_register_category( 'e2m-metabox', 'E2M Meta Box', 'Meta Box plugin abilities — read/write custom field values and manage object-to-object relationships.' );

		// ACPT integration.
		$this->maybe_register_category( 'e2m-acpt-plugin', 'E2M ACPT', 'ACPT (Advanced Custom Post Types) abilities — read/write custom field values across posts, terms, users, and options pages.' );

		// ASE integration.
		$this->maybe_register_category( 'e2m-ase', 'E2M ASE', 'ASE Pro (Admin and Site Enhancements) abilities — read/write custom field values on posts and CPTs.' );

		// Pods integration.
		$this->maybe_register_category( 'e2m-pods', 'E2M Pods', 'Pods Framework abilities — full CRUD for pod definitions, field schemas, and pod item records.' );

		// Divi integration.
		$this->maybe_register_category( 'e2m-divi', 'E2M Divi', 'Divi 4 + Divi 5 abilities — presets, modules, global modules, design tokens, and Theme Builder layouts.' );

		// Oxygen Builder integration.
		$this->maybe_register_category( 'e2m-oxygen', 'E2M Oxygen', 'Oxygen Builder abilities — component registry, schema, settings validator, page read/write. Supports Oxygen 3/4 and Oxygen 6.' );

		$this->maybe_register_category( 'e2m-beaver', 'E2M Beaver Builder', 'Beaver Builder abilities — module registry, schema, layout validator, page read/write, and patterns.' );

		$this->maybe_register_category( 'e2m-breakdance', 'E2M Breakdance', 'Breakdance Builder abilities — element registry, schema, layout validator, page read/write, and patterns.' );

		$this->maybe_register_category( 'e2m-wpbakery', 'E2M WPBakery', 'WPBakery Page Builder abilities — shortcode registry, schema, layout validator, page read/write, and patterns.' );

		// Optional integrations.
		$this->maybe_register_category( 'nexter-extension', 'Nexter Extension', 'Nexter Extension abilities for code snippets, theme builder, and site settings.' );
		$this->maybe_register_category( 'wdesignkit', 'WDesignKit', 'WDesignKit widget builder abilities for creating, managing, and deploying custom widgets.' );
		$this->maybe_register_category( 'woocommerce-e2m', 'WooCommerce', 'WooCommerce abilities for products, categories, tags, orders, and sales reports.' );
	}

	/**
	 * Register a category only when it has not been registered elsewhere.
	 *
	 * Keeps the registrar idempotent so multiple boot cycles (tests, reloads)
	 * never emit duplicate-category warnings.
	 */
	private function maybe_register_category( string $slug, string $label, string $description ): void {
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( $slug ) ) {
			return;
		}

		wp_register_ability_category(
			$slug,
			[
				'label'       => $label,
				'description' => $description,
			]
		);
	}

	/**
	 * Load the PHP files that wp_register_ability() for each surface.
	 *
	 * Grouped by folder so the structure of the abilities/ directory mirrors
	 * the structure of this loader. Any new tool added to a folder only needs
	 * its own file and a single require_once line here.
	 */
	public function e2m_manage_ability_file(): void {
		$base = E2M_ENGINE_PLUGIN_DIR . 'e2m-core/abilities/';

		// Bridge abilities are always available, regardless of enablement.
		require_once $base . 'bridge/e2m-bridge-discovery.php';
		require_once $base . 'bridge/e2m-bridge-inspector.php';
		require_once $base . 'bridge/e2m-bridge-dispatch.php';

		if ( ! e2m_engine_is_enabled() ) {
			return;
		}

		// Shared helpers (loaded before the abilities that rely on them).
		require_once $base . 'theme/theme-helpers.php';
		$this->load_builder_helpers( $base );

		// Safety abilities are always loaded - they don't depend on the
		// wordpress or elementor module toggles. They manage the safety
		// controls themselves.
		$this->load_safety_abilities( $base );

		// Memory & Instructions abilities — always available when MCP is on.
		$this->load_memory_abilities( $base );

		if ( e2m_engine_is_module_enabled( 'wordpress' ) ) {
			$this->load_wordpress_abilities( $base );
			$this->load_theme_abilities( $base );
			$this->load_filesystem_abilities( $base );
			$this->load_builder_abilities( $base );

			// Code-execution abilities (sandbox PHP eval, sandbox file
			// edits) are explicitly opt-in. Default-off keeps the surface
			// safer + smaller for fresh installs; agencies that want PHP
			// execution flip it on in the admin Sandbox tab.
			if ( function_exists( 'e2m_engine_is_sandbox_enabled' ) && e2m_engine_is_sandbox_enabled() ) {
				$this->load_code_execution_abilities( $base );
			}
		}

		// Plugin-presence gating: an ability set only registers when both the
		// module toggle AND the underlying plugin/theme are active. Sites
		// that don't run Elementor / WooCommerce / Bricks never see those
		// abilities in discovery, which keeps the MCP token surface small.
		if ( e2m_engine_is_module_enabled( 'elementor' ) && e2m_engine_has_elementor() ) {
			$this->load_elementor_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'woocommerce' ) && e2m_engine_has_woocommerce() ) {
			$this->load_woocommerce_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'bricks' ) && e2m_engine_has_bricks() ) {
			$this->load_bricks_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'acf' ) && e2m_engine_has_acf() ) {
			$this->load_acf_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'jetengine' ) && e2m_engine_has_jetengine() ) {
			$this->load_jetengine_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'metabox' ) && e2m_engine_has_metabox() ) {
			$this->load_metabox_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'acpt' ) && e2m_engine_has_acpt() ) {
			$this->load_acpt_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'ase' ) && e2m_engine_has_ase() ) {
			$this->load_ase_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'pods' ) && e2m_engine_has_pods() ) {
			$this->load_pods_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'divi' ) && e2m_engine_has_divi() ) {
			$this->load_divi_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'oxygen' ) && e2m_engine_has_oxygen() ) {
			$this->load_oxygen_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'beaver' ) && e2m_engine_has_beaver() ) {
			$this->load_beaver_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'breakdance' ) && e2m_engine_has_breakdance() ) {
			$this->load_breakdance_abilities( $base );
		}

		if ( e2m_engine_is_module_enabled( 'wpbakery' ) && e2m_engine_has_wpbakery() ) {
			$this->load_wpbakery_abilities( $base );
		}
	}

	/**
	 * Load safety abilities under abilities/safety/. These sit outside the
	 * module toggles because they *manage* the safety controls themselves
	 * (getting status, issuing confirmation tokens, listing audit log,
	 * etc.) and must always be reachable.
	 */
	private function load_safety_abilities( string $base ): void {
		require_once $base . 'safety/get-safety-status.php';
		require_once $base . 'safety/protect-post.php';
		require_once $base . 'safety/unprotect-post.php';
		require_once $base . 'safety/list-protected-posts.php';
		require_once $base . 'safety/get-audit-log.php';
		require_once $base . 'safety/clear-audit-log.php';

		// Meta-snapshot history tools.
		require_once $base . 'safety/list-post-history.php';
		require_once $base . 'safety/get-post-state.php';
		require_once $base . 'safety/diff-post-states.php';
		require_once $base . 'safety/restore-post-state.php';

		// Unified backup store tools (file/Elementor/DB backups + rollback).
		require_once $base . 'safety/list-backups.php';
		require_once $base . 'safety/rollback-file.php';
		require_once $base . 'safety/rollback-db.php';
	}

	/**
	 * Load abilities under abilities/elementor/.
	 *
	 * The helper class is required first so every tool can call the same
	 * Pro-detection and widget-manager accessors. Pro-only tools register
	 * unconditionally and return elementor_pro_required when Pro is missing.
	 */
	private function load_elementor_abilities( string $base ): void {
		require_once $base . 'elementor/helpers/class-e2m-elementor-helper.php';

		// Discovery (5).
		require_once $base . 'elementor/list-widgets.php';
		require_once $base . 'elementor/get-widget-schema.php';
		require_once $base . 'elementor/get-container-schema.php';
		require_once $base . 'elementor/get-element-settings.php';
		require_once $base . 'elementor/list-pages.php';

		// Globals (3).
		require_once $base . 'elementor/get-global-settings.php';
		require_once $base . 'elementor/update-global-colors.php';
		require_once $base . 'elementor/update-global-typography.php';

		// Page operations (5).
		require_once $base . 'elementor/update-page-settings.php';
		require_once $base . 'elementor/delete-page-content.php';
		require_once $base . 'elementor/export-page.php';
		require_once $base . 'elementor/validate-conversion.php';
		require_once $base . 'elementor/duplicate-page.php';

		// Safety: file backups + native-revision recovery (4).
		require_once $base . 'elementor/backup-elementor-page.php';
		require_once $base . 'elementor/restore-elementor-backup.php';
		require_once $base . 'elementor/list-elementor-revisions.php';
		require_once $base . 'elementor/restore-elementor-revision.php';

		// Templates (4).
		require_once $base . 'elementor/list-templates.php';
		require_once $base . 'elementor/save-as-template.php';
		require_once $base . 'elementor/apply-template.php';
		require_once $base . 'elementor/import-template.php';

		// Free custom-code + SVG (2).
		require_once $base . 'elementor/add-custom-js.php';
		require_once $base . 'elementor/upload-svg.php';

		// Pro: custom code (3).
		require_once $base . 'elementor/add-custom-css.php';
		require_once $base . 'elementor/add-code-snippet.php';
		require_once $base . 'elementor/list-code-snippets.php';

		// Pro: theme builder (2).
		require_once $base . 'elementor/create-theme-template.php';
		require_once $base . 'elementor/set-template-conditions.php';

		// Pro: dynamic tags (2).
		require_once $base . 'elementor/list-dynamic-tags.php';
		require_once $base . 'elementor/set-dynamic-tag.php';

		// Pro: popups (2).
		require_once $base . 'elementor/create-popup.php';
		require_once $base . 'elementor/set-popup-settings.php';

		// Advanced: global classes, variables, and styles v3 (3).
		require_once $base . 'elementor/manage-global-classes.php';
		require_once $base . 'elementor/manage-variables.php';
		require_once $base . 'elementor/manage-global-styles-v3.php';

		// Advanced: style schema and interactions (2).
		require_once $base . 'elementor/get-style-schema.php';
		require_once $base . 'elementor/manage-interactions.php';

		// Advanced: atomic widget creation and validation (2).
		require_once $base . 'elementor/create-atomic-widget.php';
		require_once $base . 'elementor/validate-widget.php';

		// Advanced: multi-step pipeline (1).
		require_once $base . 'elementor/pipeline.php';
	}

	/**
	 * Load Bricks Builder abilities under abilities/bricks/.
	 * Only called when the bricks module is enabled AND Bricks is active.
	 */
	private function load_bricks_abilities( string $base ): void {
		// Design system (4).
		require_once $base . 'bricks/manage-global-classes.php';
		require_once $base . 'bricks/manage-color-palette.php';
		require_once $base . 'bricks/manage-theme-styles.php';
		require_once $base . 'bricks/manage-variables.php';

		// Components and interactions (2).
		require_once $base . 'bricks/manage-components.php';
		require_once $base . 'bricks/manage-interactions.php';

		// Dynamic data bindings (1).
		require_once $base . 'bricks/manage-dynamic-data.php';
	}

	/**
	 * Load ACF abilities under abilities/acf/.
	 * Only called when the acf module is enabled AND ACF is active.
	 */
	private function load_acf_abilities( string $base ): void {
		// Read / write field values on any post, term, user, or options page.
		require_once $base . 'acf/read-write-values.php';

		// Create, read, update, delete field groups and their fields.
		require_once $base . 'acf/manage-field-groups.php';

		// Manage ACF options pages and their global field values (Pro-gated gracefully).
		require_once $base . 'acf/manage-options-pages.php';
	}

	/**
	 * Load Pods abilities under abilities/pods/.
	 * Only called when the pods module is enabled AND Pods Framework is active.
	 */
	private function load_pods_abilities( string $base ): void {
		require_once $base . 'pods/full-crud.php';
	}

	/**
	 * Load Oxygen Builder abilities under abilities/oxygen/.
	 * Only called when the oxygen module is enabled AND Oxygen is active.
	 */
	private function load_oxygen_abilities( string $base ): void {
		// Component registry — list / get / categories (includes shared helpers).
		require_once $base . 'oxygen/component-registry.php';

		// Component schema — get_schema / multi_schema / common_props.
		require_once $base . 'oxygen/component-schema.php';

		// Settings validator — validate / validate_tree.
		require_once $base . 'oxygen/settings-validator.php';

		// Page read/write — read / write / get_version / list_pages.
		require_once $base . 'oxygen/manage-pages.php';

		// Layout validator — validate_layout / validate_tree (duplicate IDs, nesting, ct_parent refs).
		require_once $base . 'oxygen/layout-validator.php';

		// Built-in patterns — list / get / categories / create_component.
		require_once $base . 'oxygen/patterns.php';
	}

	/**
	 * Load Breakdance Builder abilities under abilities/breakdance/.
	 * Only called when the breakdance module is enabled AND Breakdance is active.
	 */
	private function load_breakdance_abilities( string $base ): void {
		// Element registry — list / get / categories (includes shared helpers).
		require_once $base . 'breakdance/element-registry.php';

		// Element schema — get_schema / multi_schema / structure_notes.
		require_once $base . 'breakdance/element-schema.php';

		// Layout validator — validate_layout.
		require_once $base . 'breakdance/layout-validator.php';

		// Page read/write — read / write / list_pages / get_version.
		require_once $base . 'breakdance/manage-pages.php';

		// Built-in patterns — list / get / categories / create_element.
		require_once $base . 'breakdance/patterns.php';
	}

	/**
	 * Load WPBakery Page Builder abilities under abilities/wpbakery/.
	 * Only called when the wpbakery module is enabled AND WPBakery is active.
	 */
	private function load_wpbakery_abilities( string $base ): void {
		// Shortcode registry — list / get / categories (includes shared helpers).
		require_once $base . 'wpbakery/shortcode-registry.php';

		// Shortcode schema — get_schema / multi_schema / structure_notes.
		require_once $base . 'wpbakery/shortcode-schema.php';

		// Layout validator — validate_layout / validate_shortcode_string.
		require_once $base . 'wpbakery/layout-validator.php';

		// Page read/write — read / write / list_pages / get_version.
		require_once $base . 'wpbakery/manage-pages.php';

		// Built-in patterns — list / get / categories / create_shortcode.
		require_once $base . 'wpbakery/patterns.php';
	}

	/**
	 * Load Beaver Builder abilities under abilities/beaver/.
	 * Only called when the beaver module is enabled AND Beaver Builder is active.
	 */
	private function load_beaver_abilities( string $base ): void {
		// Module registry — list / get / categories (includes shared helpers).
		require_once $base . 'beaver/module-registry.php';

		// Module schema — get_schema / multi_schema / structure_notes.
		require_once $base . 'beaver/module-schema.php';

		// Layout validator — validate_layout.
		require_once $base . 'beaver/layout-validator.php';

		// Page read/write — read / write / list_pages / get_version.
		require_once $base . 'beaver/manage-pages.php';

		// Built-in patterns — list / get / categories / create_row / create_module.
		require_once $base . 'beaver/patterns.php';
	}

	/**
	 * Load Divi abilities under abilities/divi/.
	 * Only called when the divi module is enabled AND Divi is active.
	 */
	private function load_divi_abilities( string $base ): void {
		// Presets — list, get, upsert, bulk_upsert, resolve, delete.
		require_once $base . 'divi/manage-presets.php';

		// Modules — schema, patch attrs, materialize node IDs.
		require_once $base . 'divi/manage-modules.php';

		// Global Modules — list, get, create, update, delete, resolve refs.
		require_once $base . 'divi/manage-global-modules.php';

		// Design Tokens — read / write Divi 5 global colors + variables.
		require_once $base . 'divi/manage-design-tokens.php';

		// Theme Builder — list, get, create, update, delete layouts.
		require_once $base . 'divi/manage-theme-builder.php';
	}

	/**
	 * Load ASE abilities under abilities/ase/.
	 * Only called when the ase module is enabled AND ASE Pro custom fields are active.
	 */
	private function load_ase_abilities( string $base ): void {
		require_once $base . 'ase/read-write-values.php';
	}

	/**
	 * Load ACPT abilities under abilities/acpt/.
	 * Only called when the acpt module is enabled AND ACPT is active.
	 */
	private function load_acpt_abilities( string $base ): void {
		require_once $base . 'acpt/read-write-values.php';
	}

	/**
	 * Load Meta Box abilities under abilities/metabox/.
	 * Only called when the metabox module is enabled AND Meta Box is active.
	 */
	private function load_metabox_abilities( string $base ): void {
		require_once $base . 'metabox/read-write-values.php';
		require_once $base . 'metabox/manage-relationships.php';
	}

	/**
	 * Load JetEngine abilities under abilities/jetengine/.
	 * Only called when the jetengine module is enabled AND JetEngine is active.
	 */
	private function load_jetengine_abilities( string $base ): void {
		require_once $base . 'jetengine/manage-meta-boxes.php';
		require_once $base . 'jetengine/manage-ccts.php';
	}

	/**
	 * Load the builder adapter stack (interface, helpers, per-builder
	 * adapters). These classes back every ability under abilities/builder/
	 * and are required BEFORE those tools are included.
	 */
	private function load_builder_helpers( string $base ): void {
		require_once $base . 'builder/helpers/interface-e2m-builder-adapter.php';
		require_once $base . 'builder/helpers/class-e2m-builder-canonical.php';
		require_once $base . 'builder/helpers/class-e2m-builder-elementor-adapter.php';
		require_once $base . 'builder/helpers/class-e2m-builder-gutenberg-adapter.php';
		require_once $base . 'builder/helpers/class-e2m-builder-bricks-adapter.php';
		require_once $base . 'builder/helpers/class-e2m-builder-registry.php';
	}

	/**
	 * Load the 15 core builder-agnostic abilities under abilities/builder/.
	 * Each ability operates on the canonical tree produced by the helpers
	 * above and delegates back to the correct adapter for persistence.
	 */
	private function load_builder_abilities( string $base ): void {
		require_once $base . 'builder/extract-content.php';
		require_once $base . 'builder/inject-content.php';
		require_once $base . 'builder/detect-builder.php';
		require_once $base . 'builder/find-element.php';
		require_once $base . 'builder/update-element.php';
		require_once $base . 'builder/move-element.php';
		require_once $base . 'builder/duplicate-element.php';
		require_once $base . 'builder/remove-element.php';
		require_once $base . 'builder/reorder-elements.php';
		require_once $base . 'builder/batch-update.php';
		require_once $base . 'builder/apply-patch.php';
		require_once $base . 'builder/build-page.php';
		require_once $base . 'builder/convert-html.php';
		require_once $base . 'builder/bulk-pages-operation.php';
		require_once $base . 'builder/find-builder-targets.php';

		// Recursive image import walks the canonical tree via the builder
		// helpers above, so it is loaded here (not alongside the other
		// media/* abilities) even though it registers under the e2m-content
		// category like the rest of the media tools.
		require_once $base . 'wordpress/media/import-tree-images.php';

		// Load the widget-shortcut helper BEFORE any file that relies on its
		// functions - add-widget / add-container / add-section registrations
		// evaluate at require time and call helper functions inline.
		require_once $base . 'builder/widgets/widget-shortcut-helper.php';

		// Structural + universal widget tools (live alongside the core set).
		require_once $base . 'builder/add-widget.php';
		require_once $base . 'builder/add-container.php';
		require_once $base . 'builder/add-section.php';

		$this->load_builder_widget_shortcuts( $base );
		$this->load_gutenberg_abilities( $base );
	}

	/**
	 * Load abilities under abilities/gutenberg/.
	 *
	 * Gutenberg is WordPress core, not an optional plugin/theme, so these
	 * discovery abilities load unconditionally alongside the other
	 * builder-agnostic tools rather than behind a per-plugin module toggle.
	 * Each ability guards internally on WP_Block_Type_Registry in case a
	 * very old WP core somehow lacks it.
	 */
	private function load_gutenberg_abilities( string $base ): void {
		require_once $base . 'gutenberg/list-blocks.php';
		require_once $base . 'gutenberg/get-block-schema.php';
		require_once $base . 'gutenberg/validate-blocks.php';
		require_once $base . 'gutenberg/manage-theme-json.php';
		require_once $base . 'gutenberg/manage-global-styles.php';
		require_once $base . 'gutenberg/manage-patterns.php';
		require_once $base . 'gutenberg/manage-synced-patterns.php';
		require_once $base . 'gutenberg/manage-templates.php';
		require_once $base . 'gutenberg/manage-template-parts.php';
		require_once $base . 'gutenberg/manage-navigation.php';
		require_once $base . 'gutenberg/manage-fonts.php';
		require_once $base . 'gutenberg/register-block-style.php';
		require_once $base . 'gutenberg/register-block.php';
	}

	/**
	 * Load the widget-shortcut tools (e2m/add-*). The helper file that
	 * provides the shared functions is already loaded by load_builder_abilities
	 * above; each shortcut is just a thin registration + callback.
	 */
	private function load_builder_widget_shortcuts( string $base ): void {

		// Core content widgets.
		require_once $base . 'builder/widgets/add-heading.php';
		require_once $base . 'builder/widgets/add-text.php';
		require_once $base . 'builder/widgets/add-button.php';
		require_once $base . 'builder/widgets/add-image.php';
		require_once $base . 'builder/widgets/add-video.php';
		require_once $base . 'builder/widgets/add-divider.php';
		require_once $base . 'builder/widgets/add-spacer.php';
		require_once $base . 'builder/widgets/add-icon.php';
		require_once $base . 'builder/widgets/add-icon-list.php';
		require_once $base . 'builder/widgets/add-html.php';

		// Interactive widgets.
		require_once $base . 'builder/widgets/add-tabs.php';
		require_once $base . 'builder/widgets/add-accordion.php';
		require_once $base . 'builder/widgets/add-toggle.php';
		require_once $base . 'builder/widgets/add-alert.php';
		require_once $base . 'builder/widgets/add-counter.php';
		require_once $base . 'builder/widgets/add-progress-bar.php';
		require_once $base . 'builder/widgets/add-gallery.php';
		require_once $base . 'builder/widgets/add-slider.php';
		require_once $base . 'builder/widgets/add-carousel.php';
		require_once $base . 'builder/widgets/add-testimonial.php';

		// Composite widgets.
		require_once $base . 'builder/widgets/add-form.php';
		require_once $base . 'builder/widgets/add-search.php';
		require_once $base . 'builder/widgets/add-map.php';
		require_once $base . 'builder/widgets/add-pricing-table.php';
		require_once $base . 'builder/widgets/add-cta.php';
		require_once $base . 'builder/widgets/add-menu.php';
		require_once $base . 'builder/widgets/add-sidebar.php';
		require_once $base . 'builder/widgets/add-icon-box.php';
		require_once $base . 'builder/widgets/add-image-box.php';
		require_once $base . 'builder/widgets/add-social-icons.php';
	}

	/**
	 * Load abilities under abilities/wordpress/.
	 *
	 * Each subfolder maps to a E2M Connect category. Adding a new tool only
	 * requires dropping a file into the matching folder and appending one
	 * require_once line below.
	 */
	private function load_wordpress_abilities( string $base ): void {
		// Pages.
		require_once $base . 'wordpress/pages/create-page.php';
		require_once $base . 'wordpress/pages/update-page.php';
		require_once $base . 'wordpress/pages/list-pages.php';
		require_once $base . 'wordpress/pages/delete-page.php';
		require_once $base . 'wordpress/pages/detect-page-builder.php';

		// Posts.
		require_once $base . 'wordpress/posts/list-posts.php';
		require_once $base . 'wordpress/posts/get-post.php';
		require_once $base . 'wordpress/posts/create-post.php';
		require_once $base . 'wordpress/posts/update-post.php';
		require_once $base . 'wordpress/posts/delete-post.php';

		// Custom post types.
		require_once $base . 'wordpress/custom-posts/list-post-types.php';
		require_once $base . 'wordpress/custom-posts/list-custom-posts.php';
		require_once $base . 'wordpress/custom-posts/get-custom-post.php';
		require_once $base . 'wordpress/custom-posts/create-custom-post.php';
		require_once $base . 'wordpress/custom-posts/update-custom-post.php';
		require_once $base . 'wordpress/custom-posts/delete-custom-post.php';

		// Media.
		require_once $base . 'wordpress/media/list-media.php';
		require_once $base . 'wordpress/media/get-media.php';
		require_once $base . 'wordpress/media/upload-media.php';
		require_once $base . 'wordpress/media/find-media-by-hash.php';
		require_once $base . 'wordpress/media/update-media.php';
		require_once $base . 'wordpress/media/delete-media.php';
		require_once $base . 'wordpress/media/sideload-image.php';
		require_once $base . 'wordpress/media/search-stock-images.php';

		// Comments.
		require_once $base . 'wordpress/comments/list-comments.php';
		require_once $base . 'wordpress/comments/get-comment.php';
		require_once $base . 'wordpress/comments/create-comment.php';
		require_once $base . 'wordpress/comments/update-comment.php';
		require_once $base . 'wordpress/comments/delete-comment.php';

		// Taxonomies.
		require_once $base . 'wordpress/taxonomies/list-taxonomies.php';
		require_once $base . 'wordpress/taxonomies/get-taxonomy.php';
		require_once $base . 'wordpress/taxonomies/list-terms.php';
		require_once $base . 'wordpress/taxonomies/get-term.php';
		require_once $base . 'wordpress/taxonomies/create-term.php';
		require_once $base . 'wordpress/taxonomies/update-term.php';
		require_once $base . 'wordpress/taxonomies/delete-term.php';

		// Users.
		require_once $base . 'wordpress/users/list-users.php';
		require_once $base . 'wordpress/users/get-user.php';
		require_once $base . 'wordpress/users/create-user.php';
		require_once $base . 'wordpress/users/update-user.php';
		require_once $base . 'wordpress/users/delete-user.php';

		// Menus.
		require_once $base . 'wordpress/menus/list-menus.php';
		require_once $base . 'wordpress/menus/get-menu.php';
		require_once $base . 'wordpress/menus/create-menu.php';
		require_once $base . 'wordpress/menus/update-menu.php';
		require_once $base . 'wordpress/menus/delete-menu.php';
		require_once $base . 'wordpress/menus/list-menu-locations.php';
		require_once $base . 'wordpress/menus/assign-menu-location.php';
		require_once $base . 'wordpress/menus/list-menu-items.php';
		require_once $base . 'wordpress/menus/get-menu-item.php';
		require_once $base . 'wordpress/menus/create-menu-item.php';
		require_once $base . 'wordpress/menus/update-menu-item.php';
		require_once $base . 'wordpress/menus/delete-menu-item.php';

		// Admin (plugins + options).
		require_once $base . 'wordpress/plugins/list-plugins.php';
		require_once $base . 'wordpress/plugins/install-plugin.php';
		require_once $base . 'wordpress/plugins/activate-plugin.php';
		require_once $base . 'wordpress/plugins/deactivate-plugin.php';
		require_once $base . 'wordpress/plugins/update-plugin.php';
		require_once $base . 'wordpress/plugins/delete-plugin.php';

		require_once $base . 'wordpress/options/list-options.php';
		require_once $base . 'wordpress/options/get-option.php';
		require_once $base . 'wordpress/options/update-option.php';
		require_once $base . 'wordpress/options/delete-option.php';

		// Context / introspection.
		require_once $base . 'wordpress/context/get-site-context.php';
		require_once $base . 'wordpress/context/get-theme-docs.php';
		require_once $base . 'wordpress/context/get-builder-info.php';
		require_once $base . 'wordpress/context/get-server-compatibility.php';
	}

	/**
	 * Load abilities under abilities/woocommerce/. 22 tools grouped by
	 * resource (products, categories, tags, orders, reports). All of them
	 * return `woocommerce_required` WP_Errors when WC isn't active; the
	 * registrar already gates the require block on e2m_engine_has_woocommerce()
	 * so that path is only used by direct callers.
	 */
	private function load_woocommerce_abilities( string $base ): void {
		require_once $base . 'woocommerce/helpers/class-e2m-woocommerce-helper.php';

		// Products (6).
		require_once $base . 'woocommerce/products/list-products.php';
		require_once $base . 'woocommerce/products/get-product.php';
		require_once $base . 'woocommerce/products/create-product.php';
		require_once $base . 'woocommerce/products/update-product.php';
		require_once $base . 'woocommerce/products/delete-product.php';
		require_once $base . 'woocommerce/products/duplicate-product.php';

		// Categories (5).
		require_once $base . 'woocommerce/categories/list-product-categories.php';
		require_once $base . 'woocommerce/categories/get-product-category.php';
		require_once $base . 'woocommerce/categories/create-product-category.php';
		require_once $base . 'woocommerce/categories/update-product-category.php';
		require_once $base . 'woocommerce/categories/delete-product-category.php';

		// Tags (5).
		require_once $base . 'woocommerce/tags/list-product-tags.php';
		require_once $base . 'woocommerce/tags/get-product-tag.php';
		require_once $base . 'woocommerce/tags/create-product-tag.php';
		require_once $base . 'woocommerce/tags/update-product-tag.php';
		require_once $base . 'woocommerce/tags/delete-product-tag.php';

		// Orders (5).
		require_once $base . 'woocommerce/orders/list-orders.php';
		require_once $base . 'woocommerce/orders/get-order.php';
		require_once $base . 'woocommerce/orders/update-order.php';
		require_once $base . 'woocommerce/orders/delete-order.php';
		require_once $base . 'woocommerce/orders/update-order-status.php';

		// Reports (1).
		require_once $base . 'woocommerce/reports/get-sales-report.php';
	}

	/**
	 * Load abilities under abilities/theme/.
	 */
	private function load_theme_abilities( string $base ): void {
		require_once $base . 'theme/list-theme-files.php';
		require_once $base . 'theme/read-theme-file.php';
		require_once $base . 'theme/update-theme-file.php';
		require_once $base . 'theme/update-theme-stylesheet.php';
	}

	/**
	 * Load abilities under abilities/ops/ and abilities/filesystem/.
	 */
	private function load_filesystem_abilities( string $base ): void {
		require_once $base . 'ops/e2m-file-read.php';
		require_once $base . 'ops/e2m-file-write.php';
		require_once $base . 'ops/e2m-file-edit.php';
		require_once $base . 'ops/e2m-file-delete.php';
		require_once $base . 'ops/e2m-directory-list.php';
		require_once $base . 'filesystem/manage-modules.php';
	}

	/**
	 * Load abilities under abilities/ops/ that cover server-side code.
	 */
	private function load_code_execution_abilities( string $base ): void {
		require_once $base . 'ops/e2m-code-execute.php';
		require_once $base . 'ops/e2m-sandbox-disable.php';
		require_once $base . 'ops/e2m-sandbox-enable.php';
		require_once $base . 'filesystem/batch-execute.php';
	}

	/**
	 * Load Memory abilities — gated on the master subsystem flag. When the
	 * Memory tab toggle is off, none of the memory abilities register, so
	 * `e2m-bridge/discover-tools` never lists them and the AI cannot
	 * call them. CPT + admin tab stay accessible so the user can re-enable.
	 *
	 * Legacy abilities (get-instructions, list-memories) ship for now and
	 * forward to the new memory subsystem; they are deprecated and will be
	 * removed in v0.2.0. They are also gated by the same flag.
	 */
	private function load_memory_abilities( string $base ): void {
		if ( function_exists( 'e2m_memory_is_enabled' ) && ! e2m_memory_is_enabled() ) {
			return;
		}

		// Legacy — kept temporarily, deprecation shim lands in v0.2.0.
		require_once $base . 'memory/get-instructions.php';
		require_once $base . 'memory/list-memories.php';

		// v0.1.0 unified memory abilities.
		require_once $base . 'memory/memory-save.php';
		require_once $base . 'memory/memory-get.php';
		require_once $base . 'memory/memory-list.php';
		require_once $base . 'memory/memory-search.php';
		require_once $base . 'memory/memory-archive.php';
	}
}

E2M_Abilities_Register::instance();
