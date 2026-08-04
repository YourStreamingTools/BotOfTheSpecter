/* OpenAPI 3 helpers for the themed docs UI. Keep in sync with bot/bots_api/docs_ui/js/openapi.js */

(function (global) {
    "use strict";

    function methodClass(method) {
        const m = String(method || "").toLowerCase();
        return "docs-method-" + (["get", "post", "put", "patch", "delete", "head", "options"].includes(m) ? m : "get");
    }

    function resolveRef(schema, root, depth) {
        if (!schema || depth > 12) return schema || {};
        if (schema.$ref) {
            const ref = schema.$ref;
            if (ref.startsWith("#/")) {
                const parts = ref.slice(2).split("/");
                let cur = root;
                for (const p of parts) {
                    if (!cur || typeof cur !== "object") return schema;
                    cur = cur[p];
                }
                return resolveRef(cur || schema, root, depth + 1);
            }
        }
        return schema;
    }

    function schemaTypeLabel(schema, root) {
        if (!schema) return "any";
        const s = resolveRef(schema, root, 0);
        if (s.oneOf) return "oneOf";
        if (s.anyOf) return "anyOf";
        if (s.allOf) return "allOf";
        if (s.type === "array") {
            const item = schemaTypeLabel(s.items, root);
            return "array<" + item + ">";
        }
        if (s.enum) return "enum";
        if (s.format) return (s.type || "string") + " (" + s.format + ")";
        return s.type || (s.properties ? "object" : "any");
    }

    function collectEndpoints(spec) {
        const tagsOrder = (spec.tags || []).map((t) => t.name);
        const tagMeta = {};
        (spec.tags || []).forEach((t) => {
            tagMeta[t.name] = t.description || "";
        });

        const byTag = {};
        const paths = spec.paths || {};
        const httpMethods = ["get", "post", "put", "patch", "delete", "head", "options"];

        Object.keys(paths).sort().forEach((path) => {
            const item = paths[path] || {};
            httpMethods.forEach((method) => {
                const op = item[method];
                if (!op) return;
                const tags = (op.tags && op.tags.length) ? op.tags : ["Other"];
                tags.forEach((tag) => {
                    if (!byTag[tag]) byTag[tag] = [];
                    byTag[tag].push({
                        id: method.toUpperCase() + " " + path,
                        method: method.toUpperCase(),
                        path: path,
                        summary: op.summary || op.operationId || path,
                        description: op.description || "",
                        operationId: op.operationId || "",
                        tags: tags,
                        parameters: op.parameters || [],
                        requestBody: op.requestBody || null,
                        responses: op.responses || {},
                        security: op.security,
                        deprecated: !!op.deprecated,
                    });
                });
            });
        });

        const orderedTags = [];
        tagsOrder.forEach((t) => {
            if (byTag[t]) orderedTags.push(t);
        });
        Object.keys(byTag).sort().forEach((t) => {
            if (!orderedTags.includes(t)) orderedTags.push(t);
        });

        return { byTag, orderedTags, tagMeta };
    }

    function mergeParameters(pathItemParams, opParams) {
        const map = new Map();
        (pathItemParams || []).forEach((p) => {
            map.set(p.in + ":" + p.name, p);
        });
        (opParams || []).forEach((p) => {
            map.set(p.in + ":" + p.name, p);
        });
        return Array.from(map.values());
    }

    function getSecuritySchemes(spec) {
        return (spec.components && spec.components.securitySchemes) || {};
    }

    function endpointRequiresAuth(endpoint, spec) {
        if (endpoint.security === undefined) {
            return Array.isArray(spec.security) && spec.security.length > 0;
        }
        return Array.isArray(endpoint.security) && endpoint.security.length > 0;
    }

    function filterEndpoints(byTag, query) {
        const q = String(query || "").trim().toLowerCase();
        if (!q) return byTag;
        const out = {};
        Object.keys(byTag).forEach((tag) => {
            const items = byTag[tag].filter((ep) => {
                const hay = [ep.method, ep.path, ep.summary, ep.description, ep.operationId, tag]
                    .join(" ")
                    .toLowerCase();
                return hay.includes(q);
            });
            if (items.length) out[tag] = items;
        });
        return out;
    }

    global.SpecterOpenAPI = {
        methodClass,
        resolveRef,
        schemaTypeLabel,
        collectEndpoints,
        mergeParameters,
        getSecuritySchemes,
        endpointRequiresAuth,
        filterEndpoints,
    };
})(window);
