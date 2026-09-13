/* Themed OpenAPI explorer. Keep in sync with bot/bots_api/docs_ui/js/app.js */

(function () {
    "use strict";

    const cfg = Object.assign({
        title: "API Docs",
        subtitle: "BotOfTheSpecter",
        defaultVersion: "v2",
        versions: [
            { id: "v2", label: "V2", openapiUrl: "/v2/openapi.json", authMode: "header" },
            { id: "v1", label: "V1", openapiUrl: "/openapi.json", authMode: "query" },
        ],
        authModes: {
            header: { label: "X-API-KEY header", headerName: "X-API-KEY", queryName: null },
            query: { label: "api_key query", headerName: null, queryName: "api_key" },
            bots: {
                label: "X-API-KEY header",
                headerName: "X-API-KEY",
                queryName: null,
            },
        },
        bannerHtml: "",
        storageKey: "specter_api_docs_key",
    }, window.SPECTER_DOCS_CONFIG || {});

    const el = {
        brandTitle: document.getElementById("docs-brand-title"),
        subtitle: document.getElementById("docs-subtitle"),
        versionToggle: document.getElementById("docs-version-toggle"),
        search: document.getElementById("docs-search"),
        nav: document.getElementById("docs-nav"),
        main: document.getElementById("docs-main"),
        hamburger: document.getElementById("docs-hamburger"),
        sidebar: document.getElementById("docs-sidebar"),
        overlay: document.getElementById("docs-overlay"),
        apiKey: document.getElementById("docs-api-key"),
        banner: document.getElementById("docs-banner"),
    };

    const state = {
        versionId: null,
        spec: null,
        catalog: null,
        selectedId: null,
        loading: false,
    };

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function currentVersion() {
        return cfg.versions.find((v) => v.id === state.versionId) || cfg.versions[0];
    }

    function authConfig() {
        const v = currentVersion();
        return cfg.authModes[v.authMode] || cfg.authModes.header;
    }

    function loadKey() {
        try {
            return sessionStorage.getItem(cfg.storageKey) || "";
        } catch (_) {
            return "";
        }
    }

    function saveKey(value) {
        try {
            if (value) sessionStorage.setItem(cfg.storageKey, value);
            else sessionStorage.removeItem(cfg.storageKey);
        } catch (_) { /* ignore */ }
    }

    function setSidebarOpen(open) {
        el.sidebar.classList.toggle("is-open", open);
        el.overlay.classList.toggle("is-open", open);
    }

    function renderVersionToggle() {
        if (!cfg.versions || cfg.versions.length < 2) {
            el.versionToggle.hidden = true;
            return;
        }
        el.versionToggle.hidden = false;
        el.versionToggle.innerHTML = cfg.versions.map((v) => {
            const active = v.id === state.versionId ? " is-active" : "";
            return '<button type="button" data-version="' + escapeHtml(v.id) + '" class="' + active.trim() + '">' +
                escapeHtml(v.label) + "</button>";
        }).join("");
        el.versionToggle.querySelectorAll("button").forEach((btn) => {
            btn.addEventListener("click", () => {
                if (btn.dataset.version !== state.versionId) {
                    loadVersion(btn.dataset.version);
                }
            });
        });
    }

    function renderNav() {
        if (!state.catalog) {
            el.nav.innerHTML = '<div class="docs-loading">Loading schema…</div>';
            return;
        }
        const filtered = SpecterOpenAPI.filterEndpoints(state.catalog.byTag, el.search.value);
        const tags = state.catalog.orderedTags.filter((t) => filtered[t]);
        if (!tags.length) {
            el.nav.innerHTML = '<div class="docs-empty">No matching endpoints</div>';
            return;
        }
        el.nav.innerHTML = tags.map((tag) => {
            const items = filtered[tag].map((ep) => {
                const active = ep.id === state.selectedId ? " is-active" : "";
                return (
                    '<button type="button" class="docs-endpoint-link' + active + '" data-id="' + escapeHtml(ep.id) + '">' +
                    '<span class="sp-badge ' + SpecterOpenAPI.methodClass(ep.method) + '">' + escapeHtml(ep.method) + "</span>" +
                    '<span class="docs-path" title="' + escapeHtml(ep.path) + '">' + escapeHtml(ep.path) + "</span>" +
                    "</button>"
                );
            }).join("");
            return (
                '<div class="docs-tag">' +
                '<div class="docs-tag-label">' + escapeHtml(tag) + "</div>" +
                items +
                "</div>"
            );
        }).join("");

        el.nav.querySelectorAll(".docs-endpoint-link").forEach((btn) => {
            btn.addEventListener("click", () => {
                selectEndpoint(btn.dataset.id);
                setSidebarOpen(false);
            });
        });
    }

    function findEndpoint(id) {
        if (!state.catalog) return null;
        for (const tag of state.catalog.orderedTags) {
            const list = state.catalog.byTag[tag] || [];
            const found = list.find((ep) => ep.id === id);
            if (found) return found;
        }
        return null;
    }

    function schemaTreeHtml(schema, root, depth) {
        if (!schema || depth > 6) return "<em>…</em>";
        const s = SpecterOpenAPI.resolveRef(schema, root, 0);
        if (!s || typeof s !== "object") return "<em>any</em>";

        if (s.enum) {
            return '<span class="docs-schema-type">enum</span> [' +
                s.enum.map((v) => "<code class=\"docs-code\">" + escapeHtml(JSON.stringify(v)) + "</code>").join(", ") +
                "]";
        }

        if (s.type === "array") {
            return '<span class="docs-schema-type">array</span> of ' + schemaTreeHtml(s.items, root, depth + 1);
        }

        const props = s.properties;
        if (props) {
            const required = new Set(s.required || []);
            const keys = Object.keys(props);
            if (!keys.length) return '<span class="docs-schema-type">object</span>';
            return (
                '<span class="docs-schema-type">object</span><ul class="docs-schema">' +
                keys.map((key) => {
                    const req = required.has(key) ? ' <span class="sp-badge sp-badge-amber">required</span>' : "";
                    const child = props[key];
                    const type = SpecterOpenAPI.schemaTypeLabel(child, root);
                    const desc = child && child.description
                        ? '<div style="color:var(--text-muted);font-size:0.82rem;">' + escapeHtml(child.description) + "</div>"
                        : "";
                    return (
                        "<li><span class=\"docs-schema-key\">" + escapeHtml(key) + "</span>" + req +
                        ' <span class="docs-schema-type">' + escapeHtml(type) + "</span>" + desc +
                        (child && (child.properties || child.items || child.$ref)
                            ? "<div>" + schemaTreeHtml(child, root, depth + 1) + "</div>"
                            : "") +
                        "</li>"
                    );
                }).join("") +
                "</ul>"
            );
        }

        return '<span class="docs-schema-type">' + escapeHtml(SpecterOpenAPI.schemaTypeLabel(s, root)) + "</span>" +
            (s.description ? " — " + escapeHtml(s.description) : "");
    }

    function pathItemParams(path) {
        const item = (state.spec.paths || {})[path] || {};
        return item.parameters || [];
    }

    function renderParamsTable(params) {
        if (!params.length) return '<p class="docs-empty" style="padding:8px 0;text-align:left;">None</p>';
        return (
            '<div class="docs-table-wrap"><table class="docs-table"><thead><tr>' +
            "<th>Name</th><th>In</th><th>Type</th><th>Required</th><th>Description</th>" +
            "</tr></thead><tbody>" +
            params.map((p) => {
                const schema = p.schema || {};
                const type = SpecterOpenAPI.schemaTypeLabel(schema, state.spec);
                return (
                    "<tr>" +
                    "<td><code class=\"docs-code\">" + escapeHtml(p.name) + "</code></td>" +
                    "<td>" + escapeHtml(p.in || "") + "</td>" +
                    "<td>" + escapeHtml(type) + "</td>" +
                    "<td>" + (p.required ? "yes" : "no") + "</td>" +
                    "<td>" + escapeHtml(p.description || "") + "</td>" +
                    "</tr>"
                );
            }).join("") +
            "</tbody></table></div>"
        );
    }

    function renderResponses(responses) {
        const codes = Object.keys(responses || {}).sort();
        if (!codes.length) return '<p class="docs-empty" style="padding:8px 0;text-align:left;">None documented</p>';
        return codes.map((code) => {
            const r = responses[code] || {};
            const content = r.content || {};
            const json = content["application/json"] || content[Object.keys(content)[0]] || null;
            const schemaHtml = json && json.schema
                ? schemaTreeHtml(json.schema, state.spec, 0)
                : "<em>No schema</em>";
            return (
                '<div style="margin-bottom:14px;">' +
                '<div class="docs-meta-row">' +
                '<span class="sp-badge sp-badge-grey">' + escapeHtml(code) + "</span>" +
                "<span>" + escapeHtml(r.description || "") + "</span>" +
                "</div>" +
                schemaHtml +
                "</div>"
            );
        }).join("");
    }

    function buildTryFields(endpoint) {
        const merged = SpecterOpenAPI.mergeParameters(
            pathItemParams(endpoint.path),
            endpoint.parameters
        );
        const pathParams = merged.filter((p) => p.in === "path");
        const queryParams = merged.filter((p) => p.in === "query" && p.name !== "api_key");
        const headerParams = merged.filter((p) => p.in === "header" &&
            String(p.name).toLowerCase() !== "x-api-key");

        let bodySchema = null;
        if (endpoint.requestBody && endpoint.requestBody.content) {
            const c = endpoint.requestBody.content;
            const json = c["application/json"] || c[Object.keys(c)[0]];
            if (json && json.schema) bodySchema = json.schema;
        }

        return { pathParams, queryParams, headerParams, bodySchema, allParams: merged };
    }

    function paramInputHtml(p, prefix) {
        const id = prefix + "-" + p.name;
        const required = p.required ? " required" : "";
        return (
            '<div class="docs-field">' +
            '<label for="' + escapeHtml(id) + '">' + escapeHtml(p.name) +
            (p.required ? ' <span class="sp-badge sp-badge-amber">required</span>' : "") +
            "</label>" +
            '<input class="sp-input" id="' + escapeHtml(id) + '" data-param-in="' + escapeHtml(p.in) +
            '" data-param-name="' + escapeHtml(p.name) + '" placeholder="' +
            escapeHtml((p.schema && p.schema.example != null) ? String(p.schema.example) : (p.description || "")) +
            '"' + required + " />" +
            "</div>"
        );
    }

    function renderEndpoint(endpoint) {
        const auth = authConfig();
        const fields = buildTryFields(endpoint);
        const needsAuth = SpecterOpenAPI.endpointRequiresAuth(endpoint, state.spec) ||
            // Product API: treat non-public tags as auth-required for try-it convenience
            !(endpoint.tags || []).some((t) => /public|webhook/i.test(t));

        el.main.innerHTML = (
            '<div class="docs-page-header">' +
            "<h1>" + escapeHtml(endpoint.summary) + "</h1>" +
            (endpoint.deprecated ? '<div class="sp-alert sp-alert-warning">This endpoint is deprecated.</div>' : "") +
            "</div>" +
            '<div class="docs-meta-row">' +
            '<span class="sp-badge ' + SpecterOpenAPI.methodClass(endpoint.method) + '">' +
            escapeHtml(endpoint.method) + "</span>" +
            '<span class="docs-path-display">' + escapeHtml(endpoint.path) + "</span>" +
            (endpoint.tags || []).map((t) => '<span class="sp-badge sp-badge-accent">' + escapeHtml(t) + "</span>").join("") +
            "</div>" +
            (endpoint.description
                ? '<div class="docs-desc">' + escapeHtml(endpoint.description) + "</div>"
                : "") +

            '<div class="sp-card"><div class="sp-card-header"><div class="sp-card-title">Parameters</div></div>' +
            '<div class="sp-card-body">' + renderParamsTable(fields.allParams) + "</div></div>" +

            (fields.bodySchema
                ? '<div class="sp-card"><div class="sp-card-header"><div class="sp-card-title">Request body</div></div>' +
                  '<div class="sp-card-body">' + schemaTreeHtml(fields.bodySchema, state.spec, 0) + "</div></div>"
                : "") +

            '<div class="sp-card"><div class="sp-card-header"><div class="sp-card-title">Responses</div></div>' +
            '<div class="sp-card-body">' + renderResponses(endpoint.responses) + "</div></div>" +

            '<div class="sp-card"><div class="sp-card-header"><div class="sp-card-title">Try it</div></div>' +
            '<div class="sp-card-body"><div class="docs-try-grid">' +
            '<div class="sp-alert sp-alert-info">API keys are stored in <code class="docs-code">sessionStorage</code> only for this browser tab. Auth mode: <strong>' +
            escapeHtml(auth.label) + "</strong>.</div>" +
            '<div class="docs-field"><label for="try-api-key">API key' +
            (needsAuth ? ' <span class="sp-badge sp-badge-amber">usually required</span>' : "") +
            '</label><input class="sp-input" id="try-api-key" type="password" autocomplete="off" placeholder="Paste key…" value="' +
            escapeHtml(el.apiKey ? el.apiKey.value : loadKey()) + '" /></div>' +
            fields.pathParams.map((p) => paramInputHtml(p, "path")).join("") +
            fields.queryParams.map((p) => paramInputHtml(p, "query")).join("") +
            fields.headerParams.map((p) => paramInputHtml(p, "header")).join("") +
            (fields.bodySchema
                ? '<div class="docs-field"><label for="try-body">JSON body</label>' +
                  '<textarea class="sp-input" id="try-body" rows="8" placeholder="{}"></textarea></div>'
                : "") +
            '<div class="docs-try-actions">' +
            '<button type="button" class="sp-btn sp-btn-primary" id="try-send">Send request</button>' +
            '<button type="button" class="sp-btn sp-btn-sm" id="try-clear-key">Clear saved key</button>' +
            "</div>" +
            '<div id="try-result" hidden>' +
            '<div class="docs-response-meta" id="try-meta"></div>' +
            '<pre class="docs-pre" id="try-response"></pre>' +
            "</div>" +
            "</div></div></div>"
        );

        const tryKey = document.getElementById("try-api-key");
        if (tryKey) {
            tryKey.addEventListener("change", () => {
                saveKey(tryKey.value.trim());
                if (el.apiKey) el.apiKey.value = tryKey.value;
            });
        }
        document.getElementById("try-clear-key")?.addEventListener("click", () => {
            saveKey("");
            if (tryKey) tryKey.value = "";
            if (el.apiKey) el.apiKey.value = "";
        });
        document.getElementById("try-send")?.addEventListener("click", () => sendTry(endpoint, fields));
    }

    async function sendTry(endpoint, fields) {
        const tryKey = document.getElementById("try-api-key");
        const key = (tryKey && tryKey.value || "").trim();
        saveKey(key);
        if (el.apiKey) el.apiKey.value = key;

        const auth = authConfig();
        let path = endpoint.path;
        const query = new URLSearchParams();
        const headers = { Accept: "application/json" };

        fields.pathParams.forEach((p) => {
            const input = document.querySelector('[data-param-in="path"][data-param-name="' + CSS.escape(p.name) + '"]');
            const val = input ? input.value : "";
            path = path.replace(new RegExp("\\{" + p.name + "\\}", "g"), encodeURIComponent(val));
        });
        fields.queryParams.forEach((p) => {
            const input = document.querySelector('[data-param-in="query"][data-param-name="' + CSS.escape(p.name) + '"]');
            const val = input ? input.value : "";
            if (val !== "") query.set(p.name, val);
        });
        fields.headerParams.forEach((p) => {
            const input = document.querySelector('[data-param-in="header"][data-param-name="' + CSS.escape(p.name) + '"]');
            const val = input ? input.value : "";
            if (val !== "") headers[p.name] = val;
        });

        if (key) {
            if (auth.queryName) query.set(auth.queryName, key);
            if (auth.headerName) headers[auth.headerName] = key;
        }

        let body = undefined;
        const bodyEl = document.getElementById("try-body");
        if (bodyEl && ["POST", "PUT", "PATCH", "DELETE"].includes(endpoint.method)) {
            const raw = bodyEl.value.trim();
            if (raw) {
                try {
                    JSON.parse(raw);
                } catch (e) {
                    showTryResult(0, "Invalid JSON body", String(e), 0);
                    return;
                }
                body = raw;
                headers["Content-Type"] = "application/json";
            }
        }

        const url = path + (query.toString() ? "?" + query.toString() : "");
        const t0 = performance.now();
        const sendBtn = document.getElementById("try-send");
        if (sendBtn) sendBtn.disabled = true;
        try {
            const res = await fetch(url, { method: endpoint.method, headers, body });
            const ms = Math.round(performance.now() - t0);
            const text = await res.text();
            let pretty = text;
            try {
                pretty = JSON.stringify(JSON.parse(text), null, 2);
            } catch (_) { /* leave as text */ }
            showTryResult(res.status, res.statusText, pretty, ms);
        } catch (err) {
            const ms = Math.round(performance.now() - t0);
            showTryResult(0, "Network error", String(err), ms);
        } finally {
            if (sendBtn) sendBtn.disabled = false;
        }
    }

    function showTryResult(status, statusText, body, ms) {
        const wrap = document.getElementById("try-result");
        const meta = document.getElementById("try-meta");
        const pre = document.getElementById("try-response");
        if (!wrap || !meta || !pre) return;
        wrap.hidden = false;
        const badgeClass = status >= 200 && status < 300 ? "sp-badge-green"
            : status >= 400 ? "sp-badge-red" : "sp-badge-grey";
        meta.innerHTML =
            '<span class="sp-badge ' + badgeClass + '">' + escapeHtml(String(status || "—")) + "</span>" +
            "<span>" + escapeHtml(statusText || "") + "</span>" +
            "<span>" + escapeHtml(String(ms)) + " ms</span>";
        pre.textContent = body;
    }

    function selectEndpoint(id) {
        state.selectedId = id;
        renderNav();
        const ep = findEndpoint(id);
        if (!ep) {
            el.main.innerHTML = '<div class="docs-empty">Endpoint not found</div>';
            return;
        }
        const url = new URL(window.location.href);
        url.searchParams.set("op", id);
        if (cfg.versions.length > 1) url.searchParams.set("v", state.versionId);
        history.replaceState(null, "", url.pathname + url.search + url.hash);
        renderEndpoint(ep);
    }

    function showWelcome() {
        const info = state.spec && state.spec.info ? state.spec.info : {};
        el.main.innerHTML =
            '<div class="docs-page-header">' +
            "<h1>" + escapeHtml(info.title || cfg.title) + "</h1>" +
            "<p>" + escapeHtml(info.description || "Select an endpoint from the sidebar.") + "</p>" +
            "</div>" +
            '<div class="sp-card"><div class="sp-card-body">' +
            '<div class="docs-meta-row">' +
            '<span class="sp-badge sp-badge-accent">version ' + escapeHtml(info.version || "") + "</span>" +
            (info.contact && info.contact.email
                ? "<span>" + escapeHtml(info.contact.email) + "</span>"
                : "") +
            "</div>" +
            '<p class="docs-desc" style="margin:0;">Use the search box to filter endpoints. Paste an API key in <strong>Try it</strong> to send live requests against this host.</p>' +
            "</div></div>";
    }

    async function loadVersion(versionId) {
        const v = cfg.versions.find((x) => x.id === versionId) || cfg.versions[0];
        state.versionId = v.id;
        state.loading = true;
        state.selectedId = null;
        renderVersionToggle();
        el.nav.innerHTML = '<div class="docs-loading">Loading OpenAPI schema…</div>';
        el.main.innerHTML = '<div class="docs-loading">Loading…</div>';

        try {
            const res = await fetch(v.openapiUrl, { headers: { Accept: "application/json" } });
            if (!res.ok) throw new Error("HTTP " + res.status + " loading " + v.openapiUrl);
            state.spec = await res.json();
            state.catalog = SpecterOpenAPI.collectEndpoints(state.spec);
            state.loading = false;
            renderNav();

            const params = new URLSearchParams(window.location.search);
            const op = params.get("op");
            if (op && findEndpoint(op)) {
                selectEndpoint(op);
            } else {
                const url = new URL(window.location.href);
                if (cfg.versions.length > 1) url.searchParams.set("v", state.versionId);
                url.searchParams.delete("op");
                history.replaceState(null, "", url.pathname + url.search);
                showWelcome();
            }
        } catch (err) {
            state.loading = false;
            el.nav.innerHTML = "";
            el.main.innerHTML =
                '<div class="sp-alert sp-alert-danger">Failed to load OpenAPI schema: ' +
                escapeHtml(String(err)) + "</div>";
        }
    }

    function init() {
        if (el.brandTitle) el.brandTitle.textContent = cfg.title;
        if (el.subtitle) el.subtitle.textContent = cfg.subtitle;
        if (el.banner && cfg.bannerHtml) {
            el.banner.hidden = false;
            el.banner.innerHTML = cfg.bannerHtml;
        }
        if (el.apiKey) {
            el.apiKey.value = loadKey();
            el.apiKey.addEventListener("change", () => saveKey(el.apiKey.value.trim()));
        }

        el.search.addEventListener("input", () => renderNav());
        el.hamburger.addEventListener("click", () => setSidebarOpen(!el.sidebar.classList.contains("is-open")));
        el.overlay.addEventListener("click", () => setSidebarOpen(false));

        const params = new URLSearchParams(window.location.search);
        let initial = params.get("v") || cfg.defaultVersion;
        // Path-based default: /v2/docs → v2, /docs or /v1/docs → prefer query or default
        if (!params.get("v")) {
            if (window.location.pathname.indexOf("/v2/") !== -1) initial = "v2";
            else if (window.location.pathname.indexOf("/v1/") !== -1) initial = "v1";
        }
        if (!cfg.versions.find((v) => v.id === initial)) {
            initial = cfg.defaultVersion || cfg.versions[0].id;
        }
        loadVersion(initial);
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
