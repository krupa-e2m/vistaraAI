<?php
/**
 * "E2M Plugin" client panel — rendered inside the MCP Connect tab's client
 * switcher (`so-pill-tabs e2m-client-tabs`), alongside Claude Desktop / Claude
 * Code / Cursor. Shows the steps to install the E2M Connect agent plugin in the
 * developer's AI client and connect it to this site.
 *
 * The engine (admin-pages.php) calls PluginGuide::renderPanel() from inside the
 * `#e2m-client-e2m-plugin` panel. Self-contained CSS + JS scoped to .e2m-plug.
 *
 * @package E2M\Connect
 */

namespace E2M\Connect\Admin;

final class PluginGuide {

    /**
     * Render the contents of the "E2M Plugin" client panel.
     *
     * @param string $username Current WP user login (real username, like the
     *                         Claude Desktop / Claude Code tabs).
     * @param string $password The just-generated Application Password, or the
     *                         'YOUR-APP-PASSWORD' placeholder when none exists.
     */
    public static function renderPanel(string $username = '', string $password = ''): void {
        // The connect command, pre-filled like the other client tabs: full site
        // URL, real username, and the generated Application Password when
        // available. Keep the scheme from home_url() — it is http:// for local
        // sites (e.g. LocalWP's http://localhost:10023) and https:// for
        // staging/hosted sites — so the connect command targets the right
        // endpoint as-is.
        $siteurl     = untrailingslashit((string) home_url());
        $username    = $username !== '' ? $username : '<username>';
        $has_pw      = ($password !== '' && $password !== 'YOUR-APP-PASSWORD');
        $pw_value    = $password !== '' ? $password : 'YOUR-APP-PASSWORD';
        $connect_cmd = '/e2m-command:e2m-command ' . $siteurl . ' ' . $username . ' ' . $pw_value;

        // The full install + connect steps (Claude Code slash commands). The
        // marketplace-update / plugin-update pair is folded inline so a fresh
        // install always pulls the latest build too — contributors push daily,
        // and re-running these on an existing install is harmless (they report
        // "already up to date"). Marketplace name is `e2m-command`.
        $steps = [
            '/plugin marketplace add https://github.com/e2m-solutions/ai-agents.git#e2m-command',
            '/plugin install e2m-command@e2m-command',
            '/plugin marketplace update e2m-command',
            '/plugin update e2m-command@e2m-command',
            '/reload-plugins',
            $connect_cmd,
            '/exit',
            '/e2m-command:e2m-doctor',
            '/e2m-command:e2m-start',
        ];
        ?>
        <div class="e2m-plug">
            <div class="e2m-plug-note">
                <strong><?php esc_html_e('Claude Code needs to be installed.', 'e2m-connect'); ?></strong>
                <span><?php esc_html_e('Run these commands inside your AI client to install the E2M Connect plugin and connect it to this site.', 'e2m-connect'); ?></span>
            </div>

            <ol class="e2m-plug-steps">
                <?php foreach ($steps as $i => $cmd): ?>
                    <li>
                        <div class="e2m-plug-code">
                            <span class="num"><?php echo (int) ($i + 1); ?></span>
                            <code><?php echo esc_html($cmd); ?></code>
                            <button type="button" class="e2m-plug-copy" data-copy="<?php echo esc_attr($cmd); ?>"><?php esc_html_e('Copy', 'e2m-connect'); ?></button>
                        </div>
                        <?php if ($i === 5): ?>
                            <?php if ($has_pw): ?>
                                <p class="e2m-plug-hint"><?php esc_html_e('Filled in with your username and the Application Password you just generated. Copy it now — the password is shown only once.', 'e2m-connect'); ?></p>
                            <?php else: ?>
                                <p class="e2m-plug-hint"><?php esc_html_e('Generate an Application Password above and this command will be filled in with it automatically.', 'e2m-connect'); ?></p>
                            <?php endif; ?>
                        <?php elseif ($i === 6): ?>
                            <p class="e2m-plug-hint"><?php esc_html_e('Type /exit to close Claude Code, then start it again and log back in.', 'e2m-connect'); ?></p>
                        <?php elseif ($i === 7): ?>
                            <p class="e2m-plug-hint"><?php esc_html_e('Verifies the connection and lists the abilities the connected site exposes.', 'e2m-connect'); ?></p>
                        <?php elseif ($i === 8): ?>
                            <p class="e2m-plug-hint"><?php esc_html_e('Starts your project: asks whether this is a new or existing site, then guides the build. Run this before your first prompt.', 'e2m-connect'); ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="e2m-plug-success">
                <span><?php esc_html_e('Once connected, run /e2m-start — it sets up your project (new or existing), then you can start prompting.', 'e2m-connect'); ?></span>
            </div>
        </div>

        <style>
            .e2m-plug { display: flex; flex-direction: column; gap: 16px; }
            .e2m-plug-note { background: rgba(255,106,26,.08); border: 1px solid rgba(255,106,26,.35); border-radius: 8px; padding: 10px 14px; line-height: 1.5; }
            .e2m-plug-note strong { display: block; font-size: 14px; margin-bottom: 2px; }
            .e2m-plug-note span { font-size: 13px; opacity: .8; }
            .e2m-plug-steps { list-style: none; margin: 0; padding: 0; }
            .e2m-plug-steps li { margin: 0 0 10px; }
            .e2m-plug-code { display: flex; align-items: center; gap: 10px; background: rgba(0,0,0,.28); border: 1px solid rgba(255,255,255,.1); border-radius: 8px; padding: 9px 12px; }
            .e2m-plug-code .num { flex: 0 0 22px; width: 22px; height: 22px; line-height: 22px; text-align: center; border-radius: 50%; background: rgba(255,106,26,.2); color: #FF6A1A; font-size: 12px; font-weight: 700; }
            .e2m-plug-code code { flex: 1 1 auto; background: none; padding: 0; font-family: ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size: 13px; word-break: break-all; color: inherit; }
            .e2m-plug-copy { flex: 0 0 auto; cursor: pointer; border: 1px solid rgba(255,255,255,.18); background: transparent; color: inherit; padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; transition: all .15s; }
            .e2m-plug-copy:hover { border-color: #FF6A1A; color: #FF6A1A; }
            .e2m-plug-copy.copied { background: #FF6A1A; border-color: #FF6A1A; color: #1a1a1a; }
            .e2m-plug-hint { margin: 6px 0 0 32px; font-size: 12px; opacity: .7; }
            .e2m-plug-success { background: rgba(46,160,67,.1); border: 1px solid rgba(46,160,67,.4); border-radius: 8px; padding: 10px 14px; line-height: 1.5; }
            .e2m-plug-success span { font-size: 13px; font-weight: 600; }
        </style>
        <script>
        (function () {
            var root = document.querySelector('#e2m-client-e2m-plugin .e2m-plug');
            if (!root || root.dataset.bound) { return; }
            root.dataset.bound = '1';
            var copiedLabel = <?php echo wp_json_encode(__('Copied!', 'e2m-connect')); ?>;
            var failedLabel = <?php echo wp_json_encode(__('Copy failed', 'e2m-connect')); ?>;

            // Fallback for non-secure contexts (plain HTTP / non-localhost hosts),
            // where navigator.clipboard is unavailable. Uses a temporary textarea.
            function legacyCopy(text) {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-9999px';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try { ta.setSelectionRange(0, text.length); } catch (e) {}
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                document.body.removeChild(ta);
                return ok;
            }

            root.querySelectorAll('.e2m-plug-copy').forEach(function (btn) {
                var orig = btn.textContent;
                btn.addEventListener('click', function () {
                    var text = btn.getAttribute('data-copy') || '';
                    var flash = function (ok) {
                        btn.textContent = ok ? copiedLabel : failedLabel;
                        btn.classList.toggle('copied', ok);
                        setTimeout(function () { btn.textContent = orig; btn.classList.remove('copied'); }, 2000);
                    };
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(
                            function () { flash(true); },
                            function () { flash(legacyCopy(text)); }
                        );
                    } else {
                        flash(legacyCopy(text));
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
