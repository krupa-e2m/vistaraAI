# E2M Connect

**Figma-to-WordPress AI builder.** E2M Connect drives [Claude Code](https://claude.com/claude-code) to build ACF Pro themes or page-builder layouts from Figma designs — without leaving WP admin.

It ships an [MCP](https://modelcontextprotocol.io) server (the "E2M Connect bridge") that exposes WordPress as a set of tool abilities. The companion **`e2m-command`** Claude Code plugin connects to that bridge and runs the build agents.

- **Version:** 0.2.5
- **Requires WordPress:** 5.8+
- **Requires PHP:** 8.1+
- **License:** GPL v2 or later

## What it does

- Exposes a private, authenticated MCP endpoint at `/wp-json/mcp/e2m-connect-server`.
- Lets Claude Code create and edit pages, manage media (with SHA-1 de-duplication), read/write ACF values, build page-builder layouts, manage menus, run SEO and maintenance tasks, and more — all through the bridge's discover → inspect → dispatch abilities.
- Keeps everything inside WP admin: you generate an Application Password, paste one connect command into Claude Code, and start prompting.

## Supported builders

Abilities are grouped per builder/integration:

ACF · ACPT · ASE · Beaver Builder · Breakdance · Bricks · Divi · Elementor · JetEngine · MetaBox · Oxygen · Pods · WPBakery · Gutenberg · WooCommerce — plus core WordPress, filesystem, memory, safety, and theme operations.

## Installation

1. Download `e2m-connect-v<version>.zip` from the [Releases page](https://github.com/e2m-solutions/ai-agents/releases).
2. In WP admin, go to **Plugins → Add New → Upload Plugin**, choose the zip, and **Activate**.
3. Open the **E2M Connect** admin screen and follow the **MCP Connect → E2M Plugin** panel.

## Connect to Claude Code

The connect panel pre-fills these steps for you (with your real site URL, username, and a freshly generated Application Password):

```text
/plugin marketplace add https://github.com/e2m-solutions/ai-agents.git#e2m-command
/plugin install e2m-command@e2m-command
/reload-plugins
/e2m-command:e2m-command <your-site-url> <username> <app-password>
/exit
/e2m-command:e2m-doctor
/e2m-command:e2m-start
```

- The site URL is filled in with its scheme — `http://` for local sites (e.g. LocalWP's `http://localhost:10023`) and `https://` for staging/hosted.
- Create the Application Password under **Users → Profile → Application Passwords**.
- `/e2m-doctor` verifies the connection and lists the abilities your site exposes.
- `/e2m-start` runs the discovery gate and sets up the project (new or existing) before your first build prompt.

## License

[GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
