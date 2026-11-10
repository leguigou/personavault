/* PersonaVault — interactions légères, sans dépendance */
(() => {
    "use strict";

    /* ------------------------------------------ clavier mobile (chat)
       iOS ne réduit pas 100dvh quand le clavier s'ouvre : la page défile et
       l'en-tête sort de l'écran. On cale la hauteur du chat sur la zone
       réellement visible (visualViewport) et on garde la page en haut. */
    if (document.body.classList.contains("chat-room") && window.visualViewport) {
        const vv = window.visualViewport;
        const root = document.documentElement;
        let raf = 0;
        const fit = () => {
            raf = 0;
            const h = Math.round(vv.height);
            root.style.setProperty("--app-h", h + "px");
            root.classList.toggle("kb-open", window.innerHeight - h > 120);
            if (window.scrollY !== 0 || vv.offsetTop !== 0) window.scrollTo(0, 0);
        };
        const queue = () => { if (!raf) raf = requestAnimationFrame(fit); };
        vv.addEventListener("resize", queue);
        vv.addEventListener("scroll", queue);
        fit();
    }

    /* --------------------------------------------------- traductions
       Textes « js.* » des fichiers lang/, fournis par la page (#pv-i18n).
       tr("modal.cancel") ; tr("clé", { name: "…" }) ; tr("clé", 3) pour un pluriel {n}.
       Clé absente : le texte de repli (3e argument) ou la clé elle-même. */
    const I18N = (() => {
        try {
            return JSON.parse(document.getElementById("pv-i18n")?.textContent || "{}");
        } catch (e) {
            return {};
        }
    })();
    const LANG = (document.documentElement.lang || "fr").slice(0, 2);
    const pluralForm = (n) => (["fr", "pt"].includes(LANG) ? Math.abs(n) < 2 : Math.abs(n) === 1) ? "one" : "other";
    const tr = (key, params = {}, fallback) => {
        if (typeof params === "number") params = { n: params };
        let text = I18N[key] ?? fallback ?? key;
        if (text && typeof text === "object") text = text[pluralForm(Number(params.n) || 0)] ?? text.other ?? "";
        return String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? String(params[k]) : m));
    };
    window.pvTr = tr;

    /* Texte traduit contenant des éléments : withNodes(tr("clé"), { name: <b> })
       remplace les {name} laissés par tr() par les éléments donnés ; renvoie les morceaux
       dans l'ordre, pour append()/replaceChildren(). */
    const withNodes = (text, nodes) => text
        .split(/\{(\w+)\}/)
        .map((part, i) => (i % 2 ? nodes[part] ?? "{" + part + "}" : part))
        .filter((part) => part !== "");

    /* nombres au format de la langue de la page */
    const LOCALE = document.documentElement.lang || "fr";
    const DECIMAL = (1.5).toLocaleString(LOCALE).replace(/\d/g, "") || ".";
    const fmt = (n) => Number(n || 0).toLocaleString(LOCALE);
    const fmt1 = (n) => Number(n).toFixed(1).replace(".", DECIMAL); // une décimale

    /* ------------------------------------- photos : compression avant envoi */
    /* Une photo de téléphone pèse souvent 3 à 10 Mo, au-delà de ce que le serveur accepte
       (2 Mo par fichier avec PHP par défaut). Au-dessus de SHRINK_FROM, on la redimensionne
       (2400 px au plus) et on la réencode en JPEG dans le navigateur, en baissant la qualité
       jusqu'à passer sous SHRINK_TO. Le serveur la recompresse ensuite (~500 Ko). GIF animés
       et fichiers illisibles : envoyés tels quels. */
    const SHRINK_FROM = 1.2 * 1024 * 1024;
    const SHRINK_TO = 1.5 * 1024 * 1024;
    const shrinkImage = async (file) => {
        if (!file || !/^image\//.test(file.type) || file.type === "image/gif" || file.size <= SHRINK_FROM) return file;
        let bmp;
        try {
            bmp = await createImageBitmap(file, { imageOrientation: "from-image" });
        } catch {
            return file; // format que le navigateur ne sait pas lire : le serveur décidera
        }
        const toBlob = (canvas, q) => new Promise((res) => canvas.toBlob(res, "image/jpeg", q));
        let best = null;
        for (const side of [2400, 1800, 1400]) {
            const r = Math.min(1, side / Math.max(bmp.width, bmp.height));
            const canvas = document.createElement("canvas");
            canvas.width = Math.max(1, Math.round(bmp.width * r));
            canvas.height = Math.max(1, Math.round(bmp.height * r));
            const ctx = canvas.getContext("2d");
            ctx.fillStyle = "#fff"; // transparence (PNG) → fond blanc en JPEG
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
            for (const q of [0.9, 0.82, 0.74]) {
                const blob = await toBlob(canvas, q);
                if (blob && (!best || blob.size < best.size)) best = blob;
                if (blob && blob.size <= SHRINK_TO) {
                    bmp.close?.();
                    return new File([blob], (file.name || "photo").replace(/\.\w+$/, "") + ".jpg", { type: "image/jpeg" });
                }
            }
        }
        bmp.close?.();
        return best && best.size < file.size
            ? new File([best], (file.name || "photo").replace(/\.\w+$/, "") + ".jpg", { type: "image/jpeg" })
            : file;
    };
    /* remplace le contenu d'un <input type=file> par des fichiers compressés */
    const shrinkInput = async (input) => {
        const files = Array.from(input.files || []);
        if (!files.some((f) => f.size > SHRINK_FROM)) return;
        const out = await Promise.all(files.map(shrinkImage));
        try {
            const dt = new DataTransfer();
            out.forEach((f) => dt.items.add(f));
            input.files = dt.files;
        } catch {
            // navigateur trop ancien : les fichiers partent tels quels
        }
    };

    /* ------------------------------------------------ toasts éphémères */
    /* toast(texte) ou toast({ title, text, code }) : carton d'information en bas de l'écran,
       icône ✓ (ou ! en cas d'erreur), titre en gras, détail dessous ; code = détail en police
       à chasse fixe (nom de modèle, fichier…) */
    const TOAST_OK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5.5 12.5 4 4 9-9" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const TOAST_ERR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v6m0 3.5v.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>';
    const toast = (msg, isError = false) => {
        let el = document.querySelector(".toast");
        if (!el) {
            el = document.createElement("div");
            el.className = "toast";
            el.setAttribute("role", "status");
            el.setAttribute("aria-live", "polite");
            el.innerHTML = '<span class="toast-ico"></span><span class="toast-body"><strong class="toast-title"></strong><span class="toast-text"></span></span>';
            document.body.appendChild(el);
        }
        const m = typeof msg === "object" && msg ? msg : { title: String(msg ?? "") };
        el.querySelector(".toast-ico").innerHTML = isError ? TOAST_ERR : TOAST_OK;
        el.querySelector(".toast-title").textContent = m.title || "";
        const text = el.querySelector(".toast-text");
        text.textContent = m.text || "";
        text.hidden = !m.text;
        text.classList.toggle("is-code", Boolean(m.code));
        el.classList.toggle("is-error", Boolean(isError));
        requestAnimationFrame(() => el.classList.add("is-on"));
        clearTimeout(toast._t);
        // un peu plus long quand il y a un détail à lire
        toast._t = setTimeout(() => el.classList.remove("is-on"), m.text ? 3200 : 2200);
    };

    /* ------------------------------- formulaires : ne jamais bloquer en silence */
    document.addEventListener(
        "submit",
        (e) => {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (!form.checkValidity()) {
                e.preventDefault();
                form.reportValidity();
            }
        },
        true
    );

    /* ------------------------------------------- fenêtre modale commune
       Remplace confirm()/alert() du navigateur : même style que l'app,
       fermeture par Échap, clic à côté ou bouton, focus gardé dans la fenêtre. */
    const pvModal = (() => {
        let root = null;
        let resolver = null;
        let lastFocus = null;

        const focusables = () => [...root.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        )].filter((el) => !el.disabled && el.offsetParent !== null);

        const close = (value) => {
            if (!root || root.hidden) return;
            root.hidden = true;
            document.documentElement.classList.remove("modal-open");
            const r = resolver;
            resolver = null;
            if (lastFocus && document.contains(lastFocus)) lastFocus.focus({ preventScroll: true });
            if (r) r(value);
        };

        const build = () => {
            root = document.createElement("div");
            root.className = "modal pv-dialog";
            root.hidden = true;
            root.innerHTML =
                '<div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="pv-dialog-title">' +
                '<header class="modal-head"><h2 id="pv-dialog-title"></h2>' +
                '<button type="button" class="icon-btn btn-close" data-dialog-close aria-label="' + tr("modal.close") + '">&times;</button></header>' +
                '<div class="pv-dialog-body"></div>' +
                '<div class="form-actions pv-dialog-actions"></div></div>';
            document.body.appendChild(root);
            root.addEventListener("click", (e) => {
                if (e.target === root || e.target.closest("[data-dialog-close]")) close(false);
            });
            document.addEventListener("keydown", (e) => {
                if (!root || root.hidden) return;
                if (e.key === "Escape") {
                    e.preventDefault();
                    close(false);
                } else if (e.key === "Tab") {
                    const f = focusables();
                    if (!f.length) return;
                    const first = f[0];
                    const last = f[f.length - 1];
                    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            });
        };

        /**
         * @param {{title:string, text?:string, node?:Node, actions:Array<{label:string, value:any, kind?:string}>}} o
         * @returns {Promise<any>} valeur du bouton choisi (false si fermée)
         */
        const open = (o) => new Promise((resolve) => {
            if (!root) build();
            if (resolver) close(false);
            lastFocus = document.activeElement;
            resolver = resolve;
            root.querySelector("h2").textContent = o.title || "";
            const body = root.querySelector(".pv-dialog-body");
            body.replaceChildren();
            if (o.node) body.appendChild(o.node);
            if (o.text) {
                const p = document.createElement("p");
                p.textContent = o.text;
                body.appendChild(p);
            }
            const actions = root.querySelector(".pv-dialog-actions");
            actions.replaceChildren();
            let main = null;
            (o.actions || [{ label: tr("modal.ok"), value: true, kind: "primary" }]).forEach((a) => {
                const b = document.createElement("button");
                b.type = "button";
                b.className = "btn " + (a.kind === "danger" ? "btn-danger" : a.kind === "primary" ? "btn-primary" : "btn-ghost");
                b.textContent = a.label;
                b.addEventListener("click", () => close(a.value));
                actions.appendChild(b);
                if (a.kind === "primary" || a.kind === "danger") main = b;
            });
            root.classList.toggle("is-danger", (o.actions || []).some((a) => a.kind === "danger"));
            root.hidden = false;
            document.documentElement.classList.add("modal-open");
            (main || actions.lastElementChild)?.focus({ preventScroll: true });
        });

        return {
            open,
            close,
            confirm: (text, opts = {}) => open({
                title: opts.title || tr("modal.confirm_title"),
                text,
                actions: [
                    { label: opts.cancel || tr("modal.cancel"), value: false },
                    { label: opts.ok || tr("modal.confirm"), value: true, kind: opts.danger ? "danger" : "primary" },
                ],
            }),
            alert: (text, title = tr("modal.info")) => open({ title, text, actions: [{ label: tr("modal.ok"), value: true, kind: "primary" }] }),
        };
    })();
    window.pvModal = pvModal;

    /* interrupteurs de la conversation : enregistrés dès qu'ils changent */
    document.querySelectorAll(".sheet-form").forEach((form) => {
        form.querySelector("[data-autosave-fallback]")?.setAttribute("hidden", "");
        form.addEventListener("change", async (e) => {
            const input = e.target;
            if (!(input instanceof HTMLInputElement) || input.type !== "checkbox") return;
            // valeurs lues AVANT de désactiver l'interrupteur (un champ désactivé n'est pas envoyé)
            const body = new FormData(form);
            input.disabled = true;
            try {
                const r = await fetch(form.action, {
                    method: "POST",
                    body,
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                });
                const d = await r.json().catch(() => ({ ok: false }));
                if (!r.ok || !d.ok) throw new Error(d.error || "");
                toast(d.message || tr("chat.toggle_saved"));
            } catch (err) {
                input.checked = !input.checked; // on revient à l'état réellement enregistré
                toast(tr("chat.toggle_failed"), true);
            } finally {
                input.disabled = false;
            }
        });
    });

    /* consigne de la conversation : enregistrée sans recharger la page */
    document.querySelectorAll("[data-directive-form]").forEach((form) => {
        form.addEventListener("submit", async (e) => {
            e.preventDefault();
            const btn = form.querySelector("button[type=submit]");
            const body = new FormData(form);
            if (btn) btn.disabled = true;
            try {
                const r = await fetch(form.action, { method: "POST", body, headers: { "X-Requested-With": "XMLHttpRequest" } });
                const d = await r.json().catch(() => ({ ok: false }));
                if (!r.ok || !d.ok) throw new Error(d.error || "");
                toast(d.message || tr("chat.toggle_saved"));
            } catch (err) {
                toast(tr("chat.toggle_failed"), true);
            } finally {
                if (btn) btn.disabled = false;
            }
        });
    });

    /* cases à cocher présentées en interrupteur : annoncées comme telles */
    document.querySelectorAll(".check input[type=checkbox]").forEach((c) => c.setAttribute("role", "switch"));

    /* --------------------------------------------------- confirmations */
    document.addEventListener("submit", (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const msg = form.dataset.confirm;
        if (!msg) return;
        if (form.dataset.confirmed === "1") {
            delete form.dataset.confirmed;
            return;
        }
        e.preventDefault();
        const submitter = e.submitter || null;
        const isDelete = /\/delete$/.test(new URL(form.action, location.href).pathname);
        pvModal.confirm(msg, {
            title: form.dataset.confirmTitle || (isDelete ? tr("modal.delete_title") : tr("modal.confirm_title")),
            ok: form.dataset.confirmOk || (isDelete ? tr("modal.delete") : tr("modal.confirm")),
            danger: isDelete,
        }).then((ok) => {
            if (!ok) return;
            form.dataset.confirmed = "1";
            if (form.requestSubmit) form.requestSubmit(submitter && form.contains(submitter) ? submitter : undefined);
            else form.submit();
        });
    });

    /* ----------------------------------- bulles d'information (réglages) */
    document.addEventListener("click", (e) => {
        const btn = e.target.closest("[data-info]");
        if (!btn) return;
        e.preventDefault(); // dans un <summary> : ne pas replier la section
        e.stopPropagation();
        const tpl = document.querySelector('template[data-info-body="' + btn.dataset.info + '"]');
        if (!tpl) return;
        pvModal.open({
            title: tpl.dataset.title || "",
            node: tpl.content.cloneNode(true),
            actions: [{ label: tr("modal.got_it"), value: true, kind: "primary" }],
        });
    });

    /* ------------------------------ consigne de jeu proposée par l'IA */
    document.querySelectorAll("[data-ai-directive]").forEach((btn) => {
        btn.addEventListener("click", async () => {
            if (btn.disabled) return;
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = tr("directive.generating");
            try {
                const fd = new FormData();
                fd.append("_token", btn.dataset.token);
                const res = await fetch(btn.dataset.url, {
                    method: "POST",
                    body: fd,
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                });
                const d = await res.json().catch(() => ({ ok: false, error: tr("directive.unreadable") }));
                if (!d.ok) throw new Error(d.error || tr("directive.failed"));

                const area = document.querySelector("[data-directive]");
                if (area) {
                    area.value = d.directive;
                    area.focus();
                }
                const temp = document.querySelector('input[name="temperature"]');
                if (temp && d.temperature) temp.value = Number(d.temperature).toFixed(2);

                toast(tr("directive.done"));
            } catch (e) {
                toast(tr("common.error", { message: e.message }));
            } finally {
                btn.disabled = false;
                btn.textContent = original;
            }
        });
    });

    /* copie dans le presse-papiers + retour visuel : un bouton icône montre ✓ un instant,
       un bouton texte affiche « Copié » */
    const CHECK_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 13 4.5 4.5L19 6.5" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const copyText = async (text, btn) => {
        text = String(text).trim();
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const ta = document.createElement("textarea");
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand("copy");
            ta.remove();
        }
        toast(tr("copy.toast"));
        if (!btn || btn.dataset.copying === "1") return;
        btn.dataset.copying = "1";
        const icon = btn.querySelector("svg");
        const old = btn.innerHTML;
        if (icon) {
            btn.innerHTML = CHECK_SVG;
            btn.classList.add("is-done");
        } else {
            btn.textContent = tr("copy.copied");
        }
        setTimeout(() => {
            btn.innerHTML = old;
            btn.classList.remove("is-done");
            delete btn.dataset.copying;
        }, 1200);
    };

    /* ------------------------------------------------- copier un prompt */
    document.addEventListener("click", async (e) => {
        const btn = e.target.closest("[data-copy]");
        if (!btn) return;
        const target = document.querySelector(btn.dataset.copy);
        if (!target) return;
        copyText(target.value ?? target.textContent ?? "", btn);
    });

    /* ------------------------------------- éditeur de prompt (grande fenêtre, piste G) */
    const pe = document.getElementById("prompt-editor");
    if (pe) {
        const form = pe.querySelector("[data-pe-form]");
        const title = form.querySelector("[name=title]");
        const body = form.querySelector("[name=body]");
        const count = pe.querySelector("[data-pe-count]");
        const heading = pe.querySelector("[data-pe-heading]");
        const delBtn = pe.querySelector("[data-pe-delete]");
        let current = null; // identifiant du prompt modifié (null : nouveau)
        let initial = "";
        const snapshot = () => [title.value, body.value, form.querySelector("[name=kind]:checked")?.value].join("\u0001");
        const paint = () => {
            form.querySelectorAll(".pe-kind").forEach((l) => l.classList.toggle("is-on", l.querySelector("input").checked));
            const n = [...body.value].length;
            count.textContent = tr("prompt.chars", n) + (snapshot() !== initial ? " · " + tr("prompt.modified") : "");
        };
        const open = (card) => {
            current = card ? card.dataset.promptId : null;
            form.action = current ? "/prompts/" + current : pe.dataset.newAction;
            title.value = card ? card.dataset.promptTitle || "" : "";
            body.value = card ? card.querySelector(".prompt-body").textContent : "";
            const kind = card ? card.dataset.promptKind : "image";
            form.querySelectorAll("[name=kind]").forEach((r) => (r.checked = r.value === kind));
            heading.textContent = current ? pe.dataset.labelEdit : pe.dataset.labelNew;
            delBtn.hidden = !current;
            initial = snapshot();
            paint();
            modalOpen(pe);
            // au doigt, le clavier ne s'ouvre pas tout seul : on lit d'abord ; à la souris, on écrit
            if (!matchMedia("(pointer: coarse)").matches || !current) body.focus({ preventScroll: true });
        };
        document.addEventListener("click", (e) => {
            const edit = e.target.closest("[data-prompt-edit]");
            if (edit && !pe.contains(edit)) {
                if (window.getSelection && String(getSelection()).length > 0 && edit.tagName === "PRE") return; // sélection de texte
                open(edit.closest("[data-prompt-id]"));
                return;
            }
            if (e.target.closest("[data-prompt-new]")) open(null);
        });
        form.addEventListener("input", paint);
        form.addEventListener("change", paint);
        pe.querySelector("[data-pe-copy]").addEventListener("click", (e) => copyText(body.value, e.currentTarget));
        delBtn.addEventListener("click", () => {
            if (!current) return;
            const f = document.createElement("form");
            f.method = "post";
            f.action = "/prompts/" + current + "/delete";
            f.hidden = true;
            f.dataset.confirm = pe.dataset.deleteConfirm;
            const t = document.createElement("input");
            t.type = "hidden";
            t.name = "_token";
            t.value = form.querySelector("[name=_token]").value;
            f.appendChild(t);
            document.body.appendChild(f);
            if (f.requestSubmit) f.requestSubmit();
            else f.submit();
        });
    }

    /* ------------------------- zones de texte qui s'adaptent au contenu */
    const autosize = (ta) => {
        if (!ta || !ta.offsetParent) return; // onglet masqué : recalculé à l'ouverture
        ta.style.height = "auto";
        ta.style.height = ta.scrollHeight + 2 + "px";
    };
    function autosizeIn(scope) {
        scope?.querySelectorAll("textarea[data-autosize]").forEach(autosize);
    }
    document.querySelectorAll(".prompt-area").forEach((ta) => {
        ta.dataset.autosize = "1";
        ta.addEventListener("input", () => autosize(ta));
    });
    window.addEventListener("resize", () => autosizeIn(document));
    autosizeIn(document);

    /* ------------------------ réglages : modifications non enregistrées */
    const guarded = [...document.querySelectorAll("form[data-dirty-guard]")];
    if (guarded.length) {
        const snap = (f) => {
            const parts = [];
            new FormData(f).forEach((v, k) => {
                if (k === "_token") return;
                if (typeof v === "string") parts.push(k + "=" + v);
                else if (v && v.name) parts.push(k + "=fichier:" + v.name + ":" + v.size); // portrait choisi

            });
            return parts.join("&");
        };
        let submitting = false;
        guarded.forEach((f) => {
            f.dataset.initial = snap(f);
            const check = () => f.classList.toggle("is-dirty", snap(f) !== f.dataset.initial);
            f.addEventListener("input", check);
            f.addEventListener("change", check);
            f.addEventListener("submit", () => { submitting = true; });
        });
        const isDirty = () => guarded.some((f) => f.classList.contains("is-dirty"));
        // liens internes : fenêtre de l'app plutôt que la boîte du navigateur
        document.addEventListener("click", (e) => {
            const a = e.target.closest("a[href]");
            if (!a || submitting || !isDirty()) return;
            if (a.target === "_blank" || e.metaKey || e.ctrlKey || e.shiftKey || a.hasAttribute("download")) return;
            const url = new URL(a.href, location.href);
            if (url.origin !== location.origin || (url.pathname === location.pathname && url.hash)) return;
            e.preventDefault();
            pvModal.open({
                title: tr("unsaved.title"),
                text: tr("unsaved.text"),
                actions: [
                    { label: tr("unsaved.stay"), value: false },
                    { label: tr("unsaved.leave"), value: true, kind: "danger" },
                ],
            }).then((leave) => {
                if (!leave) return;
                submitting = true;
                location.href = url.href;
            });
        });
        // fermeture de l'onglet / rechargement : seul le navigateur peut demander
        window.addEventListener("beforeunload", (e) => {
            if (submitting || !isDirty()) return;
            e.preventDefault();
            e.returnValue = "";
        });
    }

    /* -------------- réglages : champs affichés selon un autre réglage
       data-show-if="nom=valeur" ou "nom!=valeur" */
    const condEls = document.querySelectorAll("[data-show-if]");
    if (condEls.length) {
        const applyConds = () => {
            // plusieurs conditions possibles, séparées par « & »
            condEls.forEach((el) => {
                el.hidden = !(el.dataset.showIf || "").split("&").every((cond) => {
                    const m = /^([\w-]+)(!?=)(.*)$/.exec(cond.trim());
                    if (!m) return true;
                    const field = document.querySelector('[name="' + m[1] + '"]');
                    const v = field ? field.value : "";
                    return m[2] === "=" ? v === m[3] : v !== m[3];
                });
            });
        };
        document.addEventListener("change", applyConds);
        applyConds();
    }

    /* ------------------- réglages : résumé des sections repliables */
    const foldSums = document.querySelectorAll("[data-fold-sum]");
    if (foldSums.length) {
        const val = (n) => document.querySelector('[name="' + n + '"]');
        const paintSums = () => {
            foldSums.forEach((el) => {
                if (el.dataset.foldSum === "split") {
                    const on = val("chat_split_enabled")?.checked;
                    const max = +(val("chat_split_max_bubbles")?.value || 1);
                    el.textContent = !on || max <= 1 ? tr("settings.sum_split_off")
                        : tr("settings.sum_split", { max, words: val("chat_split_words")?.value || "" });
                } else if (el.dataset.foldSum === "facts") {
                    const f = val("facts_frequency")?.value || "each";
                    const t = val("facts_timing")?.value === "idle" && f !== "explicit" ? [tr("settings.sum_facts_idle")] : [];
                    const m = val("facts_model")?.value === "remote" ? tr("settings.sum_facts_remote") : tr("settings.sum_facts_local");
                    el.textContent = f === "off" ? tr("settings.sum_facts_off")
                        : [f === "explicit" ? tr("settings.sum_facts_explicit")
                            : f === "every" ? tr("settings.sum_facts_every", { n: val("facts_every")?.value || "6" })
                            : tr("settings.sum_facts_each"), ...t, m].join(" · ");
                } else if (el.dataset.foldSum === "titles") {
                    const t = val("chat_title_model")?.value || "remote";
                    el.textContent = t === "off" ? tr("settings.sum_titles_off")
                        : t === "chat" ? tr("settings.sum_titles_local") : tr("settings.sum_titles_remote");
                } else if (el.dataset.foldSum === "followup") {
                    const on = val("chat_followup_enabled")?.checked;
                    el.textContent = on
                        ? tr("settings.sum_followup", { idle: val("chat_followup_idle_hours")?.value || "", from: val("chat_followup_from")?.value || "", to: val("chat_followup_to")?.value || "" })
                        : tr("settings.sum_followup_off");
                }
            });
        };
        document.addEventListener("input", paintSums);
        document.addEventListener("change", paintSums);
        paintSums();
    }

    /* ------------------------------ sauvegarde : choix du fichier ZIP */
    document.querySelectorAll("[data-file-pick]").forEach((pick) => {
        const input = pick.querySelector('input[type="file"]');
        const name = pick.querySelector(".file-pick-name");
        const submit = pick.closest("form")?.querySelector("[data-file-submit]");
        input?.addEventListener("change", () => {
            const f = input.files && input.files[0];
            name.textContent = f ? f.name : name.dataset.empty;
            pick.classList.toggle("has-file", !!f);
            if (submit) submit.disabled = !f;
        });
    });

    /* ------------------------------------------------- réglages : onglets */
    const tabsNav = document.querySelector("[data-tabs]");
    if (tabsNav) {
        const panels = document.querySelectorAll("[data-panel]");
        const activate = (id) => {
            tabsNav.querySelectorAll(".tab").forEach((t) => {
                const on = t.dataset.tab === id;
                t.classList.toggle("is-on", on);
                if (on) {
                    // garde l'onglet actif visible quand la barre défile
                    t.scrollIntoView({ inline: "center", block: "nearest", behavior: "smooth" });
                }
            });
            panels.forEach((p) => {
                p.hidden = p.dataset.panel !== id;
            });
            try { sessionStorage.setItem("pv_settings_tab", id); } catch (e) { /* stockage indisponible */ }
            if (location.hash.replace("#", "") !== id) history.replaceState(null, "", "#" + id);
            autosizeIn(document.querySelector('[data-panel="' + id + '"]'));
        };

        // la barre d'onglets se défile horizontalement : le dégradé du bord
        // disparaît une fois arrivé au bout
        const syncTabsFade = () => {
            const max = tabsNav.scrollWidth - tabsNav.clientWidth;
            tabsNav.classList.toggle("is-end", tabsNav.scrollLeft >= max - 4);
        };
        tabsNav.addEventListener("scroll", syncTabsFade, { passive: true });
        window.addEventListener("resize", syncTabsFade);
        syncTabsFade();
        tabsNav.addEventListener("click", (e) => {
            const t = e.target.closest(".tab");
            if (t) activate(t.dataset.tab);
        });
        const fromHash = () => {
            let h = (location.hash || "").replace("#", "");
            // après un enregistrement, le serveur renvoie sur /settings sans
            // ancre : on rouvre l'onglet sur lequel on était
            if (!h) {
                try { h = sessionStorage.getItem("pv_settings_tab") || ""; } catch (e) { h = ""; }
            }
            // ancre d'une section repliable (ex. #followup depuis une conversation)
            const fold = h ? document.getElementById(h) : null;
            if (fold && fold.tagName === "DETAILS") {
                const panel = fold.closest("[data-panel]");
                if (panel) activate(panel.dataset.panel);
                fold.open = true;
                // après l'animation des onglets ; décalage = en-tête + barre d'onglets collés en haut
                setTimeout(() => {
                    const offset = (document.querySelector(".topbar")?.offsetHeight || 0) + (tabsNav.offsetHeight || 0) + 16;
                    window.scrollTo({ top: fold.getBoundingClientRect().top + window.scrollY - offset, behavior: "smooth" });
                }, 350);
                return;
            }
            if (h && document.querySelector('[data-panel="' + h + '"]')) activate(h);
        };
        fromHash();
        window.addEventListener("hashchange", fromHash);
    }

    /* ------------------------------------------- curseurs des réglages */
    document.querySelectorAll("input.range[data-out]").forEach((input) => {
        const out = document.querySelector('[data-out="' + input.dataset.out + '"]');
        const suffix = input.dataset.suffix || "";
        const paint = () => {
            if (!out) return;
            const v = parseFloat(input.value);
            out.textContent = suffix ? fmt1(v) + suffix : String(v);
        };
        input.addEventListener("input", paint);
        paint();
    });

    /* curseur à deux poignées (pause min / max entre deux bulles) :
       chaque poignée reste de son côté, la bande entre les deux est colorée */
    document.querySelectorAll("[data-dual-range]").forEach((box) => {
        const [lo, hi] = box.querySelectorAll('input[type="range"]');
        const out = box.closest(".range-field")?.querySelector("[data-pause-out]");
        if (!lo || !hi) return;
        const span = (+lo.max - +lo.min) || 1;
        const paint = () => {
            const a = (+lo.value - +lo.min) / span;
            const b = (+hi.value - +lo.min) / span;
            box.style.setProperty("--lo", a);
            box.style.setProperty("--hi", b);
            if (out) out.textContent = tr("settings.pause_range", { min: fmt1(+lo.value), max: fmt1(+hi.value) });
        };
        lo.addEventListener("input", () => {
            if (+lo.value > +hi.value) lo.value = hi.value;
            paint();
        });
        hi.addEventListener("input", () => {
            if (+hi.value < +lo.value) hi.value = lo.value;
            paint();
        });

        // souris et doigt gérés ici (le clavier reste sur les curseurs natifs) :
        // la poignée la plus proche suit ; si les deux sont au même endroit,
        // le premier mouvement décide (gauche = minimum, droite = maximum)
        const step = +lo.step || 0.1;
        const valueAt = (clientX) => {
            const r = box.getBoundingClientRect();
            const half = 9; // demi-poignée
            const f = Math.min(1, Math.max(0, (clientX - r.left - half) / (r.width - 2 * half)));
            return Math.round((+lo.min + f * span) / step) * step;
        };
        const move = (input, v) => {
            input.value = v.toFixed(1);
            input.dispatchEvent(new Event("input", { bubbles: true }));
        };
        let active = null;
        let startX = 0;
        box.addEventListener("pointerdown", (e) => {
            const v = valueAt(e.clientX);
            const dLo = Math.abs(v - +lo.value);
            const dHi = Math.abs(v - +hi.value);
            active = dLo < dHi ? lo : dHi < dLo ? hi : v < +lo.value ? lo : v > +hi.value ? hi : null;
            startX = e.clientX;
            box.setPointerCapture(e.pointerId);
            if (active) {
                active.focus({ preventScroll: true });
                move(active, v);
            }
            e.preventDefault();
        });
        box.addEventListener("pointermove", (e) => {
            if (!box.hasPointerCapture(e.pointerId)) return;
            if (!active) {
                if (Math.abs(e.clientX - startX) < 3) return;
                active = e.clientX < startX ? lo : hi;
                active.focus({ preventScroll: true });
            }
            move(active, valueAt(e.clientX));
        });
        const stop = (e) => {
            if (box.hasPointerCapture(e.pointerId)) box.releasePointerCapture(e.pointerId);
            active = null;
        };
        box.addEventListener("pointerup", stop);
        box.addEventListener("pointercancel", stop);
        paint();
    });

    /* ------------------------------------------ sauvegarde : option conv */
    const backupLink = document.querySelector("[data-backup-link]");
    const backupConv = document.querySelector("[data-backup-conv]");
    if (backupLink && backupConv) {
        const syncHref = () => {
            backupLink.href = "/backup/export.zip" + (backupConv.checked ? "?conv=1" : "");
        };
        backupConv.addEventListener("change", syncHref);
        syncHref();
    }

    /* ------------------------------------ prompts internes : réinitialiser */
    document.querySelectorAll("[data-reset-prompt]").forEach((btn) => {
        btn.addEventListener("click", () => {
            const area = btn.closest(".prompt-block")?.querySelector(".prompt-area");
            if (!area) return;
            area.value = area.dataset.default || "";
            area.dispatchEvent(new Event("input", { bubbles: true }));
            area.focus();
        });
    });

    /* ------------------------ style de la barre du bas : aperçu immédiat */
    document.querySelector("[data-tabbar-select]")?.addEventListener("change", (e) => {
        document.documentElement.dataset.tabbar = e.target.value;
    });

    /* ------------------- thème (couleur principale, mode) : aperçu immédiat */
    document.querySelector("[data-accent-select]")?.addEventListener("change", (e) => {
        document.documentElement.dataset.accent = e.target.value;
    });
    document.querySelector("[data-mode-select]")?.addEventListener("change", (e) => {
        const mode = e.target.value;
        document.documentElement.dataset.mode = mode;
        // barre du navigateur : une balise par mode affiché (deux en « auto », avec media)
        let colors = {};
        try { colors = JSON.parse(e.target.dataset.themeColors || "{}"); } catch (err) {}
        document.querySelectorAll('meta[name="theme-color"]').forEach((m) => m.remove());
        (mode === "auto" ? ["dark", "light"] : [mode]).forEach((m) => {
            const meta = document.createElement("meta");
            meta.name = "theme-color";
            meta.content = colors[m] || "";
            if (mode === "auto") meta.media = "(prefers-color-scheme: " + m + ")";
            document.head.appendChild(meta);
        });
        const scheme = document.querySelector('meta[name="color-scheme"]');
        if (scheme) scheme.content = mode === "auto" ? "dark light" : mode;
    });

    /* -------------------------------------- aperçu live de l'apparence */
    const uiForm = document.querySelector("[data-ui-form]");
    const uiPreview = document.querySelector("[data-ui-preview]");
    if (uiForm && uiPreview) {
        const fontSel = uiForm.querySelector('[name="ui_font_size"]');
        const bubbleSel = uiForm.querySelector('[name="ui_bubble_style"]');
        const densSel = uiForm.querySelector('[name="ui_density"]');
        const widthSel = uiForm.querySelector('[name="ui_width"]');
        const avatarsBox = uiForm.querySelector('[name="ui_avatars"]');

        const paint = () => {
            uiPreview.style.setProperty("--pv-font", (fontSel?.value || "14.5") + "px");
            uiPreview.dataset.bubble = bubbleSel?.value || "bulle";
            uiPreview.dataset.density = densSel?.value || "confort";
            uiPreview.dataset.avatars = avatarsBox?.checked ? "1" : "0";
            uiPreview.style.setProperty("--pv-width", widthSel?.value === "large" ? "96%" : "86%");
        };

        ["change", "input"].forEach((ev) => uiForm.addEventListener(ev, paint));
        paint();
    }

    /* ------------------------------------------------- panneaux à bascule */
    document.addEventListener("click", (e) => {
        const btn = e.target.closest("[data-toggle]");
        if (!btn) return;
        let panel = null;
        try {
            panel = document.querySelector(btn.dataset.toggle);
        } catch (err) {
            panel = null;
        }
        if (!panel) return;
        panel.hidden = !panel.hidden;
        if (!panel.hidden) {
            const first = panel.querySelector("input, textarea, select");
            if (first) setTimeout(() => first.focus({ preventScroll: true }), 60);
            panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
    });

    /* ----------------------------------------- auto-submit fichiers photo */
    document.addEventListener("change", (e) => {
        const input = e.target;
        if (!(input instanceof HTMLInputElement)) return;
        // la galerie de la fiche envoie elle-même ses photos (compressées), voir plus bas
        if (input.matches("[data-autosubmit]") && input.files && input.files.length && !input.closest("[data-photo-upload]")) {
            input.closest("form")?.submit();
        }
        /* aperçu de l'avatar avant envoi */
        if (input.matches("[data-preview]") && input.files && input.files[0]) {
            // portrait : compressé pendant qu'on regarde l'aperçu ; l'envoi attend la fin
            const pForm = input.closest("form");
            if (input.files[0].size > SHRINK_FROM && pForm) {
                pForm._shrinking = shrinkInput(input).finally(() => { pForm._shrinking = null; });
            }
            const box = document.querySelector(input.dataset.preview);
            if (!box) return;
            const url = URL.createObjectURL(input.files[0]);
            const old = box.querySelector("img, .avatar-fallback");
            const img = document.createElement("img");
            img.src = url;
            img.alt = tr("persona.preview_alt");
            if (old) old.replaceWith(img);
            else box.appendChild(img);
        }
    });

    document.addEventListener("submit", (e) => {
        const f = e.target;
        if (!(f instanceof HTMLFormElement) || !f._shrinking) return;
        e.preventDefault();
        const submitter = e.submitter || null;
        f._shrinking.then(() => (f.requestSubmit ? f.requestSubmit(submitter && f.contains(submitter) ? submitter : undefined) : f.submit()));
    }, true);

    /* ------------------------------------------------------- lightbox */
    /* Visionneuse : les photos d'un même ensemble ([data-gallery]) défilent au doigt
       (défilement horizontal aimanté), aux flèches du clavier ou avec ‹ › ; compteur
       « 3 / 9 », points, et bouton rond de téléchargement de la photo affichée. */
    const lb = document.getElementById("lightbox");
    if (lb) {
        const track = lb.querySelector("[data-lb-track]");
        const count = lb.querySelector("[data-lb-count]");
        const dots = lb.querySelector("[data-lb-dots]");
        const dl = lb.querySelector("[data-lb-download]");
        let items = [];
        let index = 0;
        let baseName = "photo";
        let sources = [];   // les vignettes d'origine (identifiant de photo, portrait…)
        let actions = null; // la galerie, si ses photos ont des actions (fiche persona)
        const btnPortrait = lb.querySelector("[data-lb-portrait]");
        const btnDelete = lb.querySelector("[data-lb-delete]");

        const paint = () => {
            const n = items.length;
            count.textContent = n > 1 ? (index + 1) + " / " + n : "";
            lb.classList.toggle("is-single", n <= 1);
            const url = items[index] || "";
            dl.href = url;
            const ext = (url.split("?")[0].match(/\.(\w+)$/) || [, "webp"])[1];
            dl.setAttribute("download", baseName + (n > 1 ? "-" + (index + 1) : "") + "." + ext);
            [...dots.children].forEach((d, k) => d.classList.toggle("is-on", k === index));
            // portrait / supprimer : seulement pour une photo de fiche
            const src = sources[index];
            const has = !!(actions && src?.dataset.photoId);
            btnPortrait.hidden = btnDelete.hidden = !has;
            if (has) {
                const isPortrait = src.dataset.portrait === "1";
                const label = isPortrait ? btnPortrait.dataset.labelIs : btnPortrait.dataset.labelSet;
                btnPortrait.classList.toggle("is-on", isPortrait);
                btnPortrait.setAttribute("aria-pressed", isPortrait ? "true" : "false");
                btnPortrait.setAttribute("aria-label", label);
                btnPortrait.title = label;
            }
            lb.querySelector(".lb-prev").disabled = index <= 0;
            lb.querySelector(".lb-next").disabled = index >= n - 1;
        };
        const goTo = (k, smooth = true) => {
            index = Math.max(0, Math.min(items.length - 1, k));
            track.scrollTo({ left: index * track.clientWidth, behavior: smooth ? "smooth" : "auto" });
            paint();
        };
        const open = (img) => {
            // une image hors galerie peut s'y rattacher (couverture de la fiche → photos de la fiche)
            const group = img.closest("[data-gallery]") || (img.dataset.galleryOf ? document.querySelector(img.dataset.galleryOf) : null);
            let imgs = group ? [...group.querySelectorAll("[data-full]")] : [img];
            // l'image touchée : elle-même, ou la même photo dans la galerie ; sinon en tête de liste
            let start = imgs.indexOf(img);
            if (start < 0) start = imgs.findIndex((x) => x.dataset.full === img.dataset.full);
            if (start < 0) {
                imgs = [img, ...imgs];
                start = 0;
            }
            items = imgs.map((x) => x.dataset.full);
            sources = imgs;
            actions = group?.hasAttribute("data-photo-actions") ? group : null;
            baseName = group?.dataset.galleryName || "photo";
            track.replaceChildren(...items.map((src, k) => {
                const slide = document.createElement("div");
                slide.className = "lb-slide";
                const im = document.createElement("img");
                im.alt = imgs[k].alt || "";
                im.decoding = "async";
                // la photo touchée et ses voisines tout de suite, les autres à l'approche
                im.loading = Math.abs(k - start) <= 1 ? "eager" : "lazy";
                im.src = src;
                slide.appendChild(im);
                return slide;
            }));
            dots.replaceChildren(...(items.length > 1 && items.length <= 20
                ? items.map(() => document.createElement("i")) : []));
            lb.hidden = false;
            document.body.style.overflow = "hidden";
            requestAnimationFrame(() => goTo(start, false));
        };
        const close = () => {
            lb.hidden = true;
            document.body.style.overflow = "";
            track.replaceChildren();
        };

        // compteur à jour pendant le glissement du doigt
        let raf = 0;
        track.addEventListener("scroll", () => {
            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(() => {
                const k = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
                if (k !== index) {
                    index = k;
                    paint();
                }
            });
        }, { passive: true });
        window.addEventListener("resize", () => {
            if (!lb.hidden) goTo(index, false);
        });

        document.addEventListener("click", (e) => {
            const img = e.target.closest("[data-full]");
            if (img && !lb.contains(img)) open(img);
        });
        document.addEventListener("keydown", (e) => {
            const img = e.target.closest?.("[data-full]");
            if (img && lb.hidden && (e.key === "Enter" || e.key === " ")) {
                e.preventDefault();
                open(img);
                return;
            }
            if (lb.hidden) return;
            if (document.querySelector(".pv-dialog:not([hidden])")) return; // une confirmation est ouverte
            if (e.key === "Escape") close();
            else if (e.key === "ArrowRight") goTo(index + 1);
            else if (e.key === "ArrowLeft") goTo(index - 1);
        });
        // envoie un formulaire comme ceux de la page (jeton CSRF ; confirmation si data-confirm)
        const postForm = (action, confirmText) => {
            const f = document.createElement("form");
            f.method = "post";
            f.action = action;
            f.hidden = true;
            const t = document.createElement("input");
            t.type = "hidden";
            t.name = "_token";
            t.value = actions.dataset.token;
            f.appendChild(t);
            if (confirmText) f.dataset.confirm = confirmText;
            document.body.appendChild(f);
            if (f.requestSubmit) f.requestSubmit();
            else f.submit();
        };
        btnPortrait.addEventListener("click", () => {
            const src = sources[index];
            if (!actions || !src?.dataset.photoId || src.dataset.portrait === "1") return;
            postForm("/photos/" + src.dataset.photoId + "/avatar");
        });
        btnDelete.addEventListener("click", () => {
            const src = sources[index];
            if (!actions || !src?.dataset.photoId) return;
            postForm("/photos/" + src.dataset.photoId + "/delete", actions.dataset.deleteConfirm);
        });

        lb.addEventListener("click", (e) => {
            const step = e.target.closest("[data-lb-step]");
            if (step) return goTo(index + Number(step.dataset.lbStep));
            // fermer : la croix, ou un toucher hors de la photo et des boutons
            if (e.target.closest(".lightbox-close") || e.target === lb || e.target.classList.contains("lb-slide")) close();
        });
    }

    /* --------------------------------------------------- fermer flash */
    document.addEventListener("click", (e) => {
        if (e.target.closest(".flash-close")) {
            e.target.closest(".flash")?.remove();
        }
    });
    document.querySelectorAll(".flash").forEach((f) => {
        setTimeout(() => {
            f.style.transition = "opacity .4s, transform .4s";
            f.style.opacity = "0";
            f.style.transform = "translateY(-6px)";
            setTimeout(() => f.remove(), 420);
        }, 5200);
    });

    /* --------------------------------------------- sélecteur custom (listbox) */
    document.querySelectorAll("[data-sel]").forEach((root) => {
        const native = root.querySelector("select");
        const btn = root.querySelector(".sel-btn");
        const valueEl = root.querySelector(".sel-value");
        if (!native || !btn || !valueEl) return;

        const menu = document.createElement("ul");
        menu.className = "sel-menu";
        menu.setAttribute("role", "listbox");
        menu.hidden = true;
        root.appendChild(menu);

        const sync = () => {
            const opt = native.options[native.selectedIndex];
            valueEl.textContent = opt ? opt.textContent.trim() : "";
            [...menu.children].forEach((li) =>
                li.setAttribute("aria-selected", String(li.dataset.value === native.value))
            );
        };

        const rebuild = () => {
            menu.innerHTML = "";
            [...native.options].forEach((opt) => {
                const li = document.createElement("li");
                li.setAttribute("role", "option");
                li.dataset.value = opt.value;
                li.textContent = opt.textContent.trim();
                menu.appendChild(li);
            });
            sync();
        };

        const open = () => {
            menu.hidden = false;
            btn.setAttribute("aria-expanded", "true");
            const sel = menu.querySelector('[aria-selected="true"]') || menu.firstElementChild;
            if (sel) sel.classList.add("is-focus");
        };
        const close = () => {
            menu.hidden = true;
            btn.setAttribute("aria-expanded", "false");
            menu.querySelectorAll(".is-focus").forEach((el) => el.classList.remove("is-focus"));
        };

        btn.addEventListener("click", () => (menu.hidden ? open() : close()));

        menu.addEventListener("click", (e) => {
            const li = e.target.closest("li[data-value]");
            if (!li) return;
            native.value = li.dataset.value;
            native.dispatchEvent(new Event("change", { bubbles: true }));
            sync();
            close();
            btn.focus();
        });

        document.addEventListener("click", (e) => {
            if (!root.contains(e.target)) close();
        });

        root.addEventListener("keydown", (e) => {
            if (e.key === "Escape") {
                close();
                return;
            }
            if (e.key === "Tab") {
                close();
                return;
            }
            if (e.key === "ArrowDown" || e.key === "ArrowUp") {
                e.preventDefault();
                if (menu.hidden) {
                    open();
                    return;
                }
                const items = [...menu.children];
                const idx = items.findIndex((i) => i.classList.contains("is-focus"));
                const next = Math.min(items.length - 1, Math.max(0, (idx === -1 ? 0 : idx) + (e.key === "ArrowDown" ? 1 : -1)));
                items.forEach((i) => i.classList.remove("is-focus"));
                items[next]?.classList.add("is-focus");
                items[next]?.scrollIntoView({ block: "nearest" });
                return;
            }
            if ((e.key === "Enter" || e.key === " ") && !menu.hidden) {
                const cur = menu.querySelector(".is-focus");
                if (cur) {
                    e.preventDefault();
                    cur.click();
                }
            }
        });

        native.addEventListener("change", sync);
        rebuild();
    });

    /* ------------------------------------------------- complétion par l'IA */
    document.querySelectorAll("[data-ai-fill]").forEach((btn) => {
        btn.addEventListener("click", async () => {
            if (btn.classList.contains("is-busy")) return;
            const original = btn.innerHTML;
            btn.classList.add("is-busy");
            btn.disabled = true;
            // bouton rond : le libellé est à côté (aria-describedby) ; on ne change que lui
            const lbl = document.getElementById(btn.getAttribute("aria-describedby") || "");
            const lblText = lbl ? lbl.textContent : "";
            const restore = () => {
                btn.innerHTML = original;
                if (lbl) lbl.textContent = lblText;
                btn.disabled = false;
                btn.classList.remove("is-busy");
            };
            (lbl || btn).textContent = tr("persona.ai_fill_busy");

            try {
                const fd = new FormData();
                fd.append("_token", btn.dataset.token || "");
                const res = await fetch(btn.dataset.aiFill, {
                    method: "POST",
                    body: fd,
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                });
                const data = await res.json().catch(() => ({ ok: false, error: tr("common.unreadable") }));
                if (!res.ok || !data.ok) throw new Error(data.error || tr("persona.ai_fill_failed"));

                if (!data.changed || data.changed.length === 0) {
                    toast(data.message || tr("persona.ai_fill_nothing"));
                    restore();
                    return;
                }
                toast(data.message || tr("persona.ai_fill_done"));
                setTimeout(() => window.location.reload(), 800);
            } catch (err) {
                toast(tr("common.error", { message: err.message }), true);
                restore();
            }
        });
    });

    /* ------------------------------------------------------ réglages du LLM */
    const settingsForm = document.querySelector("[data-settings-form]");
    if (settingsForm) {
        const PRESETS = {
            deepseek: {
                url: "https://api.deepseek.com/v1",
                model: "deepseek-chat",
                models: ["deepseek-chat", "deepseek-reasoner"],
            },
            openai: {
                url: "https://api.openai.com/v1",
                model: "gpt-4o-mini",
                models: ["gpt-4o-mini", "gpt-4o", "gpt-4.1-mini", "o4-mini"],
            },
            openrouter: {
                url: "https://openrouter.ai/api/v1",
                model: "deepseek/deepseek-chat",
                models: ["deepseek/deepseek-chat", "google/gemini-2.0-flash-001", "anthropic/claude-3.5-sonnet"],
            },
            lmstudio: {
                url: "http://localhost:1234/v1",
                model: "qwen3.5-9b",
                models: ["qwen3.5-9b", "gemma-4-12b", "qwen3-vl-8b"],
            },
            custom: { models: [] },
        };

        const providerSel = settingsForm.querySelector('select[name="provider"]');
        const urlInput = settingsForm.querySelector('input[name="base_url"]');
        const modelInput = settingsForm.querySelector('input[name="model"]');

        const applyPreset = (force) => {
            const preset = PRESETS[providerSel?.value] || {};
            if (preset.url && (force || !urlInput.value)) urlInput.value = preset.url;
            if (preset.model && (force || !modelInput.value)) modelInput.value = preset.model;
        };
        providerSel?.addEventListener("change", () => applyPreset(true));
        applyPreset(false);

        const testBtn = settingsForm.querySelector("[data-llm-test]");
        const status = settingsForm.querySelector("[data-llm-status]");
        testBtn?.addEventListener("click", async () => {
            const original = testBtn.textContent;
            testBtn.classList.add("is-busy");
            testBtn.disabled = true;
            testBtn.textContent = tr("settings.test_running");
            status.className = "llm-status";
            status.textContent = tr("settings.test_connecting");

            try {
                const fd = new FormData(settingsForm);
                const res = await fetch("/settings/test", { method: "POST", body: fd });
                const data = await res.json().catch(() => ({ ok: false, error: tr("common.unreadable") }));
                if (!res.ok || !data.ok) throw new Error(data.error || tr("settings.test_failed"));
                status.className = "llm-status is-ok";
                status.innerHTML = `<span class="dot dot-ok"></span><span></span>`;
                status.lastElementChild.textContent = tr("settings.test_ok", { model: data.modele, ms: data.ms });
            } catch (err) {
                status.className = "llm-status is-ko";
                status.innerHTML = `<span class="dot dot-ko"></span><span></span>`;
                status.lastElementChild.textContent = err.message;
            } finally {
                testBtn.classList.remove("is-busy");
                testBtn.disabled = false;
                testBtn.textContent = original;
            }
        });
    }


    /* -------------------------------------- recherche + autocomplétion tags */
    const searchForm = document.querySelector("[data-search]");
    if (searchForm) {
        const input = searchForm.querySelector("[data-search-input]");
        const box = searchForm.querySelector("[data-suggest]");
        let tags = [];
        try {
            tags = JSON.parse(searchForm.dataset.tags || "[]");
        } catch {
            tags = [];
        }

        let items = [];
        let active = -1;

        const hideSuggest = () => {
            box.hidden = true;
            box.innerHTML = "";
            items = [];
            active = -1;
        };

        const goTag = (tag) => {
            const params = new URLSearchParams();
            searchForm.querySelectorAll('input[type="hidden"]').forEach((h) => {
                if (h.name && h.name !== "tag" && h.value) params.set(h.name, h.value);
            });
            params.set("tag", tag);
            window.location.href = "/?" + params.toString();
        };

        const renderSuggest = () => {
            const q = (input.value || "").trim().toLowerCase();
            const matches = tags.filter((t) => t.t.toLowerCase().includes(q)).slice(0, 7);
            box.innerHTML = "";
            items = [];

            if (q !== "") {
                const free = document.createElement("button");
                free.type = "button";
                free.className = "sug-free";
                const label = document.createElement("span");
                const typed = document.createElement("strong");
                typed.textContent = input.value.trim();
                label.append(...withNodes(tr("search.free"), { q: typed }));
                free.appendChild(label);
                free.addEventListener("click", () => {
                    hideSuggest();
                    searchForm.submit();
                });
                box.appendChild(free);
                items.push(free);
            }

            matches.forEach((t) => {
                const b = document.createElement("button");
                b.type = "button";
                b.innerHTML = '<span class="hash">#</span><span class="sug-label"></span><em></em>';
                b.querySelector(".sug-label").textContent = t.t;
                b.querySelector("em").textContent = t.n;
                b.addEventListener("click", () => goTag(t.t));
                box.appendChild(b);
                items.push(b);
            });

            box.hidden = items.length === 0;
        };

        const setActive = (i) => {
            items.forEach((b) => b.classList.remove("is-active"));
            if (i >= 0 && items[i]) {
                active = i;
                items[i].classList.add("is-active");
                items[i].scrollIntoView({ block: "nearest" });
            } else {
                active = -1;
            }
        };

        input.addEventListener("input", () => {
            renderSuggest();
            setActive(-1);
        });
        input.addEventListener("focus", renderSuggest);
        input.addEventListener("keydown", (e) => {
            if (box.hidden) return;
            if (e.key === "ArrowDown") {
                e.preventDefault();
                setActive(active + 1 >= items.length ? 0 : active + 1);
            } else if (e.key === "ArrowUp") {
                e.preventDefault();
                setActive(active - 1 < 0 ? items.length - 1 : active - 1);
            } else if (e.key === "Escape") {
                hideSuggest();
            } else if (e.key === "Enter" && active >= 0) {
                e.preventDefault();
                items[active].click();
            }
        });
        document.addEventListener("click", (e) => {
            if (!searchForm.contains(e.target)) hideSuggest();
        });
        searchForm.addEventListener("submit", hideSuggest);
    }


    /* ------------------------------------------------- vue grille / liste */
    const viewButtons = document.querySelectorAll("[data-view-btn]");
    if (viewButtons.length) {
        const applyView = (view) => {
            document.documentElement.dataset.view = view;
            viewButtons.forEach((b) => {
                const on = b.dataset.viewBtn === view;
                b.classList.toggle("is-on", on);
                b.setAttribute("aria-pressed", String(on));
            });
            try {
                localStorage.setItem("pv_view", view);
            } catch (e) {
                /* stockage indisponible : on garde juste la session */
            }
        };
        applyView(document.documentElement.dataset.view === "list" ? "list" : "grid");
        viewButtons.forEach((b) => b.addEventListener("click", () => applyView(b.dataset.viewBtn)));
    }


    /* ------------------------------- collage / dépôt d'images sur une fiche */
    const photoForm = document.querySelector("[data-photo-upload]");
    if (photoForm) {
        const tokenInput = photoForm.querySelector('input[name="_token"]');
        let sending = false;

        const sendImage = async (input) => {
            const list = (Array.isArray(input) ? input : [input]).filter((f) => f && f.type && f.type.startsWith("image/"));
            if (!list.length || sending) return false;
            sending = true;
            toast(tr("persona.photo_adding"));
            const files = [];
            for (const f of list) files.push(await shrinkImage(f));

            // par lots de ~6 Mo : PHP refuse par défaut un envoi de plus de 8 Mo au total
            const batches = [[]];
            let size = 0;
            for (const f of files) {
                if (batches[batches.length - 1].length && size + f.size > 6 * 1024 * 1024) {
                    batches.push([]);
                    size = 0;
                }
                batches[batches.length - 1].push(f);
                size += f.size;
            }

            try {
                for (const batch of batches) {
                    const fd = new FormData();
                    fd.append("_token", tokenInput ? tokenInput.value : "");
                    batch.forEach((file) => fd.append("photos[]", file, file.name || "collage.png"));
                    const res = await fetch(photoForm.action, {
                        method: "POST",
                        body: fd,
                        headers: { "X-Requested-With": "XMLHttpRequest" },
                    });
                    const data = await res.json().catch(() => ({ ok: false, error: tr("common.unreadable") }));
                    if (!res.ok || !data.ok) throw new Error(data.error || tr("common.send_failed"));
                }
                toast(tr("persona.photo_added"));
                setTimeout(() => window.location.reload(), 450);
                return true;
            } catch (err) {
                toast(tr("common.error", { message: err.message }), true);
                sending = false;
                return false;
            }
        };

        document.addEventListener("paste", (e) => {
            const items = Array.from((e.clipboardData && e.clipboardData.items) || []);
            const item = items.find((i) => i.kind === "file" && i.type.startsWith("image/"));
            if (!item) return;
            const file = item.getAsFile();
            if (!file) return;
            e.preventDefault();
            sendImage(file);
        });

        photoForm.addEventListener("dragover", (e) => {
            e.preventDefault();
            photoForm.classList.add("is-drop");
        });
        photoForm.addEventListener("dragleave", () => photoForm.classList.remove("is-drop"));
        photoForm.addEventListener("drop", (e) => {
            e.preventDefault();
            photoForm.classList.remove("is-drop");
            const files = Array.from((e.dataTransfer && e.dataTransfer.files) || []);
            const imgs = files.filter((f) => f.type.startsWith("image/"));
            if (imgs.length) sendImage(imgs);
        });
        // bouton « Ajouter » : les photos choisies partent compressées, sans recharger la page avant
        photoForm.querySelector('input[type="file"]')?.addEventListener("change", (e) => {
            const files = Array.from(e.target.files || []);
            if (files.length) sendImage(files);
            e.target.value = "";
        });
    }


    /* --------------------------------------------------------- chat personas */
    const modalOpen = (el) => {
        if (!el) return;
        el.hidden = false;
        document.body.style.overflow = "hidden";
    };
    const modalClose = (el) => {
        if (!el) return;
        el.hidden = true;
        document.body.style.overflow = "";
    };

    const picker = document.getElementById("persona-picker");
    if (picker) {
        const search = picker.querySelector("[data-picker-search]");
        document.querySelectorAll("[data-open-picker]").forEach((b) =>
            b.addEventListener("click", () => {
                modalOpen(picker);
                search?.focus();
            })
        );
        picker.querySelectorAll("[data-close-picker]").forEach((b) =>
            b.addEventListener("click", () => modalClose(picker))
        );
        picker.addEventListener("click", (e) => {
            if (e.target === picker) modalClose(picker);
        });
        search?.addEventListener("input", () => {
            const q = search.value.trim().toLowerCase();
            picker.querySelectorAll(".picker-item").forEach((item) => {
                item.hidden = q !== "" && !(item.dataset.name || "").includes(q);
            });
        });
    }

    /* liste des discussions : appui long (mobile), clic droit ou « ⋯ » → menu épingler / supprimer */
    const convSheet = document.getElementById("conv-actions");
    // posées dans <main>, ces fenêtres passeraient sous la barre du bas : on les sort dans <body>
    [convSheet, ...document.querySelectorAll(".modal[data-modal]")].forEach((m) => m && document.body.appendChild(m));
    if (convSheet) {
        let current = null;
        // tant que le doigt reste posé après l'appui long, le navigateur poursuit son propre
        // geste (sélection de texte) : on l'interdit jusqu'à ce que le doigt se lève
        let pressing = false;
        const clearSelection = () => window.getSelection?.()?.removeAllRanges();
        document.addEventListener("selectstart", (e) => {
            if (pressing) e.preventDefault();
        });
        const release = () => {
            if (!pressing) return;
            setTimeout(() => {
                pressing = false;
                clearSelection();
            }, 50);
        };
        ["touchend", "touchcancel", "pointerup", "pointercancel"].forEach((ev) => document.addEventListener(ev, release, true));
        let openedAt = 0;
        const titleEl = convSheet.querySelector("[data-act-title]");
        const pinLabel = convSheet.querySelector("[data-act=pin] span");
        const openMenu = (item, byTouch = false) => {
            current = item;
            openedAt = Date.now();
            titleEl.textContent = item.dataset.convTitle || "";
            pinLabel.textContent = item.dataset.pinned === "1" ? pinLabel.dataset.labelUnpin : pinLabel.dataset.labelPin;
            modalOpen(convSheet);
            // au doigt, pas de surbrillance « sélectionné » sur la première ligne
            if (!byTouch) convSheet.querySelector("[data-act]")?.focus({ preventScroll: true });
        };
        const closeMenu = () => modalClose(convSheet);
        const post = (url, fields) => {
            const f = document.createElement("form");
            f.method = "post";
            f.action = url;
            Object.entries({ _token: convSheet.dataset.token, ...fields }).forEach(([k, v]) => {
                const i = document.createElement("input");
                i.type = "hidden";
                i.name = k;
                i.value = v;
                f.appendChild(i);
            });
            document.body.appendChild(f);
            f.submit();
        };

        document.querySelectorAll(".conv-item").forEach((item) => {
            let timer = null;
            let fired = false;
            let x0 = 0;
            let y0 = 0;
            const cancel = () => {
                clearTimeout(timer);
                timer = null;
            };
            item.addEventListener("pointerdown", (e) => {
                if (e.pointerType === "mouse" || e.target.closest("[data-conv-more]")) return;
                fired = false;
                pressing = true;
                x0 = e.clientX;
                y0 = e.clientY;
                cancel();
                timer = setTimeout(() => {
                    timer = null;
                    fired = true;
                    navigator.vibrate?.(12);
                    clearSelection();
                    openMenu(item, true);
                }, 480);
            });
            item.addEventListener("pointermove", (e) => {
                if (timer && Math.hypot(e.clientX - x0, e.clientY - y0) > 10) {
                    cancel(); // c'est un défilement
                    pressing = false;
                }
            });
            ["pointerup", "pointercancel", "pointerleave"].forEach((ev) => item.addEventListener(ev, cancel));
            // le doigt relevé après l'appui long ne doit ni ouvrir la conversation, ni « toucher »
            // le menu qui vient d'apparaître sous lui : on annule le clic synthétique
            item.addEventListener("touchend", (e) => {
                if (fired && e.cancelable) e.preventDefault();
            }, { passive: false });
            item.addEventListener("click", (e) => {
                if (!fired) return;
                e.preventDefault();
                fired = false;
            }, true);
            item.addEventListener("contextmenu", (e) => {
                e.preventDefault();
                const touchy = timer !== null || fired;
                cancel();
                if (fired) return;
                fired = touchy; // appui long natif (Android) : le clic qui suit n'ouvre pas la conversation
                openMenu(item, touchy);
            });
            item.querySelector("[data-conv-more]")?.addEventListener("click", () => openMenu(item));
        });

        convSheet.addEventListener("click", async (e) => {
            if (Date.now() - openedAt < 450) return; // clic fantôme de fin d'appui long
            if (e.target === convSheet || e.target.closest("[data-act-close]")) return closeMenu();
            const btn = e.target.closest("[data-act]");
            if (!btn || !current) return;
            const id = current.dataset.convId;
            if (btn.dataset.act === "pin") {
                post("/chat/" + id + "/pin", { pinned: current.dataset.pinned === "1" ? "0" : "1" });
            } else if (btn.dataset.act === "delete") {
                closeMenu();
                const ok = await pvModal.confirm(
                    btn.dataset.confirm.replace("{title}", current.dataset.convTitle || ""),
                    { danger: true, ok: tr("modal.delete") }
                );
                if (ok) post("/chat/" + id + "/delete", {});
            }
        });
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && !convSheet.hidden) closeMenu();
        });
    }

    /* fenêtres simples : [data-open-modal="#id"] ouvre, [data-close-modal] ou le fond ferme */
    document.querySelectorAll("[data-open-modal]").forEach((b) =>
        b.addEventListener("click", () => {
            const m = document.querySelector(b.dataset.openModal);
            if (!m) return;
            // ouvert depuis une autre fenêtre (menu « Plus ») : celle-ci se ferme d'abord
            const from = b.closest(".modal");
            if (from && from !== m) modalClose(from);
            modalOpen(m);
        })
    );
    document.querySelectorAll(".modal[data-modal]").forEach((m) => {
        m.querySelectorAll("[data-close-modal]").forEach((b) => b.addEventListener("click", () => modalClose(m)));
        m.addEventListener("click", (e) => {
            if (e.target === m) modalClose(m);
        });
    });

    const chatSheet = document.getElementById("chat-settings");
    /* un seul panneau, deux sujets : l'anneau ouvre « Contexte et mémoire » (memory),
       la roue « Conversation » (conversation) ; seules les sections du sujet sont visibles */
    let sheetGroup = "";
    const setSheet = (open, group) => {
        if (!chatSheet) return;
        if (open && group) {
            sheetGroup = group;
            chatSheet.querySelectorAll("[data-sheet-group]").forEach((s) => {
                s.hidden = s.dataset.sheetGroup !== group;
            });
            const eyebrow = chatSheet.querySelector("[data-sheet-eyebrow]");
            if (eyebrow) eyebrow.textContent = tr(group === "memory" ? "chat.sheet_memory" : "chat.sheet_conversation");
            chatSheet.querySelector(".sheet-body")?.scrollTo(0, 0);
        }
        chatSheet.classList.toggle("is-open", open);
        chatSheet.setAttribute("aria-hidden", String(!open));
        document.querySelectorAll("[data-open-settings]").forEach((b) =>
            b.setAttribute("aria-expanded", String(open && b.dataset.openSettings === sheetGroup))
        );
        document.body.classList.toggle("sheet-open", open);
        chatSheet.style.removeProperty("--drag");
        document.body.style.removeProperty("--backdrop-o");
        // mobile : feuille qui recouvre la conversation → la page derrière ne défile plus
        if (isSheetMobile()) document.body.style.overflow = open ? "hidden" : "";
    };
    const isSheetMobile = () => window.matchMedia("(max-width: 899px)").matches;

    /* mobile : fond assombri + tirer la feuille vers le bas pour la refermer
       (par la poignée, l'en-tête, ou le contenu quand il est déjà tout en haut) */
    if (chatSheet) {
        const backdrop = document.createElement("div");
        backdrop.className = "chat-sheet-backdrop";
        chatSheet.before(backdrop);

        const body = chatSheet.querySelector(".sheet-body");
        let startY = 0;
        let lastY = 0;
        let lastT = 0;
        let speed = 0;
        let dragging = false;
        let fromBody = false;
        const move = (dy) => {
            const d = Math.max(0, dy);
            chatSheet.style.setProperty("--drag", d + "px");
            document.body.style.setProperty("--backdrop-o", String(Math.max(0, 1 - d / Math.max(1, chatSheet.offsetHeight))));
        };
        const begin = (y) => {
            startY = lastY = y;
            lastT = performance.now();
            speed = 0;
        };
        const track = (y) => {
            const now = performance.now();
            speed = (y - lastY) / Math.max(1, now - lastT); // px/ms, positif vers le bas
            lastY = y;
            lastT = now;
            if (!dragging) {
                dragging = true;
                chatSheet.classList.add("is-dragging");
                document.body.classList.add("sheet-dragging");
            }
            move(y - startY);
        };
        const end = () => {
            if (!dragging) return;
            dragging = false;
            chatSheet.classList.remove("is-dragging");
            document.body.classList.remove("sheet-dragging");
            const dy = lastY - startY;
            // assez tiré (un tiers de la feuille, ou 120 px) ou lancé vers le bas : on ferme
            if (dy > Math.min(120, chatSheet.offsetHeight / 3) || (speed > 0.6 && dy > 24)) setSheet(false);
            else move(0);
            setTimeout(() => {
                if (!chatSheet.classList.contains("is-open")) chatSheet.style.removeProperty("--drag");
            }, 400);
        };

        // poignée et en-tête : on suit le doigt tout de suite
        chatSheet.querySelectorAll("[data-sheet-grab], [data-sheet-drag]").forEach((zone) => {
            zone.addEventListener("pointerdown", (e) => {
                if (!isSheetMobile() || e.button > 0 || e.target.closest("button, a, input, select, textarea")) return;
                zone.setPointerCapture(e.pointerId);
                begin(e.clientY);
            });
            zone.addEventListener("pointermove", (e) => {
                if (!zone.hasPointerCapture(e.pointerId)) return;
                if (dragging || Math.abs(e.clientY - startY) > 4) track(e.clientY);
            });
            zone.addEventListener("pointerup", end);
            zone.addEventListener("pointercancel", end);
        });

        // contenu : seulement quand il est déjà en haut et qu'on tire vers le bas
        body?.addEventListener("touchstart", (e) => {
            if (!isSheetMobile() || e.touches.length !== 1) return;
            fromBody = body.scrollTop <= 0 && !e.target.closest("input, select, textarea, [data-dual-range]");
            begin(e.touches[0].clientY);
        }, { passive: true });
        body?.addEventListener("touchmove", (e) => {
            if (!fromBody) return;
            const y = e.touches[0].clientY;
            if (!dragging && (y - startY < 6 || body.scrollTop > 0)) {
                if (y < startY) fromBody = false; // on fait défiler vers le haut : défilement normal
                return;
            }
            e.preventDefault(); // la feuille descend au lieu de faire défiler
            track(y);
        }, { passive: false });
        body?.addEventListener("touchend", () => {
            fromBody = false;
            end();
        });
        body?.addEventListener("touchcancel", () => {
            fromBody = false;
            end();
        });
        window.addEventListener("resize", () => {
            if (!isSheetMobile()) document.body.style.overflow = "";
        });
    }

    if (chatSheet) {
        document.querySelectorAll("[data-open-settings]").forEach((b) =>
            b.addEventListener("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
                const group = b.dataset.openSettings || "conversation";
                // même bouton : on referme ; autre bouton : on change de sujet
                const isOpen = chatSheet.classList.contains("is-open");
                setSheet(!(isOpen && sheetGroup === group), group);
            })
        );
        document.querySelectorAll("[data-close-settings]").forEach((b) =>
            b.addEventListener("click", () => setSheet(false))
        );
        // clic à l'extérieur = fermeture (comportement popover)
        document.addEventListener("click", (e) => {
            if (!chatSheet.classList.contains("is-open")) return;
            if (chatSheet.contains(e.target)) return;
            if (e.target.closest("[data-open-settings]")) return;
            setSheet(false);
        });
    }

    document.addEventListener("keydown", (e) => {
        if (e.key !== "Escape") return;
        if (chatSheet && chatSheet.classList.contains("is-open")) setSheet(false);
        if (picker && !picker.hidden) modalClose(picker);
        document.querySelectorAll(".modal[data-modal]:not([hidden])").forEach(modalClose);
    });

    const chat = document.getElementById("chat");
    if (chat) {
        const form = chat.querySelector("[data-chat-form]");
        const input = chat.querySelector("[data-chat-input]");
        const scroll = chat.querySelector("[data-scroll]");
        const sendBtn = form.querySelector(".chat-send");
        const avatarHTML = chat.querySelector(".chat-avatar")?.innerHTML || "";
        const endpoint = chat.dataset.send;
        const token = chat.dataset.token;
        let busy = false;

        /* ---------- suivi du bas de la discussion (comme Claude)
           - en bas : la conversation suit les nouveaux messages (« collé ») ;
           - dès qu'on remonte (molette, doigt, clavier) : plus rien ne nous
             ramène de force, une bulle ↓ apparaît au-dessus de la saisie ;
           - toucher la bulle, envoyer un message ou redescendre à la main :
             on se recolle au bas. */
        const NEAR_BOTTOM = 80;
        let stick = true;
        let touching = false;
        let lastUserScroll = 0;
        const distance = () => scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight;

        const jump = document.createElement("button");
        jump.type = "button";
        jump.className = "chat-jump";
        jump.hidden = true;
        jump.setAttribute("aria-label", tr("chat.jump"));
        jump.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14m0 0-6-6m6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        chat.appendChild(jump);

        const placeJump = () => {
            // juste au-dessus de la zone de saisie, quelle que soit sa hauteur
            const gap = chat.getBoundingClientRect().bottom - scroll.getBoundingClientRect().bottom;
            jump.style.bottom = Math.round(gap + 14) + "px";
        };
        const syncJump = () => {
            const show = !stick && distance() > NEAR_BOTTOM;
            if (show && jump.hidden) placeJump();
            jump.hidden = !show;
            if (!show) jump.classList.remove("has-new");
        };

        const scrollBottom = (smooth = true, force = false) => {
            if (force) stick = true;
            if (!stick || (touching && !force)) {
                // l'utilisateur lit plus haut : on signale qu'il y a du nouveau
                if (!stick) jump.classList.add("has-new");
                syncJump();
                return;
            }
            scroll.scrollTo({ top: scroll.scrollHeight, behavior: smooth ? "smooth" : "instant" });
            syncJump();
        };

        // intention de l'utilisateur (le défilement automatique ne passe pas par là)
        const userScrolled = () => { lastUserScroll = Date.now(); };
        scroll.addEventListener("wheel", (e) => {
            userScrolled();
            if (e.deltaY < 0) { stick = false; syncJump(); }
        }, { passive: true });
        scroll.addEventListener("touchstart", () => { touching = true; userScrolled(); }, { passive: true });
        scroll.addEventListener("touchmove", userScrolled, { passive: true });
        scroll.addEventListener("touchend", () => { touching = false; userScrolled(); }, { passive: true });
        scroll.addEventListener("pointerdown", userScrolled, { passive: true }); // barre de défilement
        scroll.addEventListener("keydown", userScrolled);
        scroll.addEventListener("scroll", () => {
            const d = distance();
            if (d <= NEAR_BOTTOM) stick = true;
            else if (Date.now() - lastUserScroll < 1200) stick = false;
            syncJump();
        }, { passive: true });

        jump.addEventListener("click", () => {
            scrollBottom(true, true);
            jump.hidden = true;
        });
        window.addEventListener("resize", () => { if (!jump.hidden) placeJump(); });
        input?.addEventListener("input", () => { if (!jump.hidden) requestAnimationFrame(placeJump); });

        scrollBottom(false, true);

        /* quand le clavier s'ouvre, garder le dernier message visible
           (si on suivait déjà le bas de la discussion) */
        if (window.visualViewport) {
            let lastH = window.visualViewport.height;
            window.visualViewport.addEventListener("resize", () => {
                const h = window.visualViewport.height;
                if (h < lastH && stick) requestAnimationFrame(() => scrollBottom(false));
                if (!jump.hidden) placeJump();
                lastH = h;
            });
        }

        const clearIntro = () => chat.querySelector(".chat-intro")?.remove();

        const bubble = (text, role) => {
            const wrap = document.createElement("div");
            wrap.className = "msg msg-" + (role === "user" ? "user" : "bot");
            wrap.dataset.at = new Date().toISOString(); // message qui vient d'apparaître
            if (role !== "user") {
                const av = document.createElement("span");
                av.className = "msg-avatar";
                av.innerHTML = avatarHTML;
                wrap.appendChild(av);
            }
            const b = document.createElement("div");
            b.className = "bubble";
            b.textContent = text;
            wrap.appendChild(b);
            scroll.appendChild(wrap);
            wrap.dataset.live = "1";
            return wrap;
        };

        const renderMsg = (m) => {
            if (m.role === "memory") {
                const line = document.createElement("div");
                line.className = "msg-memory";
                line.dataset.msgId = m.id;
                line.innerHTML =
                    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a4 4 0 0 0-4 4 3 3 0 0 0 0 6 3.5 3.5 0 0 0 4 4V5Zm0 0a4 4 0 0 1 4 4 3 3 0 0 1 0 6 3.5 3.5 0 0 1-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg><span></span>';
                line.querySelector("span").textContent = m.content;
                return line;
            }
            if (m.role === "notice") {
                const n = document.createElement("div");
                n.className = "msg-notice";
                n.dataset.msgId = m.id;
                n.textContent = m.content;
                return n;
            }
            const wrap = document.createElement("div");
            wrap.className = "msg msg-" + (m.role === "user" ? "user" : "bot");
            wrap.dataset.msgId = m.id;
            if (m.at) wrap.dataset.at = m.at;
            if (m.role !== "user") {
                const av = document.createElement("span");
                av.className = "msg-avatar";
                av.innerHTML = avatarHTML;
                wrap.appendChild(av);
            }
            if (m.image) {
                const img = document.createElement("img");
                img.className = "bubble-img";
                img.alt = tr("chat.photo_alt");
                img.src = "/media/" + m.image + ".thumb.webp";
                img.dataset.full = "/media/" + m.image + ".webp";
                wrap.appendChild(img);
            }

            const chars = Array.from(String(m.content || ""));
            const pts = (Array.isArray(m.split_points) ? m.split_points : [])
                .map(Number)
                .filter((n) => n > 0 && n < chars.length);
            if (pts.length) {
                const holder = document.createElement("div");
                holder.className = "bubbles";
                const bounds = [0, ...pts, chars.length];
                for (let i = 0; i < bounds.length - 1; i++) {
                    const b = document.createElement("div");
                    b.className = "bubble";
                    b.textContent = chars.slice(bounds[i], bounds[i + 1]).join("").trim();
                    holder.appendChild(b);
                }
                wrap.appendChild(holder);
            } else {
                const b = document.createElement("div");
                b.className = "bubble";
                b.textContent = m.content;
                wrap.appendChild(b);
            }
            return wrap;
        };

        /* séparateurs de jour (« Aujourd'hui », « Hier », « lun. 3 nov. ») entre les messages,
           dans la langue et le fuseau du téléphone ; recalculés à chaque ajout de messages */
        const dayLabel = (d) => {
            const now = new Date();
            const start = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
            const diff = Math.round((start(now) - start(d)) / 864e5);
            if (diff === 0 || diff === 1) {
                const rel = new Intl.RelativeTimeFormat(LOCALE, { numeric: "auto" }).format(-diff, "day");
                return rel.charAt(0).toUpperCase() + rel.slice(1);
            }
            const opts = { weekday: "short", day: "numeric", month: "short" };
            if (d.getFullYear() !== now.getFullYear()) opts.year = "numeric";
            const txt = d.toLocaleDateString(LOCALE, opts);
            return txt.charAt(0).toUpperCase() + txt.slice(1);
        };
        let dayObserver = null;
        const paintDays = () => {
            dayObserver?.disconnect();
            scroll.querySelectorAll(".msg-day").forEach((n) => n.remove());
            let last = "";
            scroll.querySelectorAll(".msg[data-at]").forEach((m) => {
                const d = new Date(m.dataset.at);
                if (Number.isNaN(d.getTime())) return;
                const key = d.toDateString();
                if (key === last) return;
                last = key;
                const sep = document.createElement("div");
                sep.className = "msg-day";
                sep.setAttribute("role", "separator");
                sep.innerHTML = "<span></span>";
                sep.firstChild.textContent = dayLabel(d);
                m.before(sep);
            });
            dayObserver?.observe(scroll, { childList: true });
        };
        let dayRaf = 0;
        dayObserver = new MutationObserver(() => {
            cancelAnimationFrame(dayRaf);
            dayRaf = requestAnimationFrame(() => {
                // garder sous les yeux le même message malgré les séparateurs ajoutés au-dessus
                const atBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 40;
                const box = scroll.getBoundingClientRect();
                const anchor = [...scroll.querySelectorAll(".msg")].find((m) => m.getBoundingClientRect().bottom > box.top);
                const before = anchor ? anchor.getBoundingClientRect().top : 0;
                paintDays();
                if (atBottom) scroll.scrollTop = scroll.scrollHeight;
                else if (anchor) scroll.scrollTop += anchor.getBoundingClientRect().top - before;
            });
        });
        paintDays();

        const olderBtn = chat.querySelector("[data-load-older]");
        olderBtn?.addEventListener("click", async () => {
            if (olderBtn.disabled) return;
            olderBtn.disabled = true;
            const before = parseInt(olderBtn.dataset.before, 10) || 0;
            try {
                const r = await fetch(olderBtn.dataset.url + "?before=" + before + "&limit=50", {
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                });
                const d = await r.json();
                if (!d.ok) throw new Error(tr("chat.load_failed"));
                if (!d.messages.length) {
                    olderBtn.remove();
                    return;
                }
                const prevH = scroll.scrollHeight;
                const prevTop = scroll.scrollTop;
                const first = scroll.querySelector(".msg, .msg-notice");
                const frag = document.createDocumentFragment();
                d.messages.forEach((m) => frag.appendChild(renderMsg(m)));
                scroll.insertBefore(frag, first || null);
                scroll.scrollTop = prevTop + (scroll.scrollHeight - prevH);
                olderBtn.dataset.before = d.messages[0].id;
                if (d.left <= 0) {
                    olderBtn.remove();
                } else {
                    olderBtn.textContent = tr("chat.load_older", d.left);
                    olderBtn.disabled = false;
                }
            } catch (e) {
                olderBtn.disabled = false;
                notice(tr("common.error", { message: e.message }));
            }
        });

        const notice = (text) => {
            const n = document.createElement("div");
            n.className = "msg-notice";
            n.textContent = text;
            scroll.appendChild(n);
        };

        const typing = () => {
            const t = document.createElement("div");
            t.className = "msg msg-bot typing";
            t.id = "chat-typing";
            t.innerHTML =
                '<span class="msg-avatar">' + avatarHTML + '</span><div class="bubble"><i></i><i></i><i></i></div>';
            scroll.appendChild(t);
            scrollBottom();
        };


        const loadingLabel = (txt) => {
            let el = document.getElementById("chat-loading");
            if (!txt) {
                el?.remove();
                return;
            }
            if (!el) {
                el = document.createElement("div");
                el.id = "chat-loading";
                el.className = "msg-loading";
                scroll.appendChild(el);
            }
            if (el.textContent !== txt) el.textContent = txt;
            scrollBottom(false);
        };

        const foot = document.querySelector("[data-chat-foot]");
        const ringSvgs = document.querySelectorAll("[data-ctx-ring] svg, [data-ctx-ring-big] svg");
        const updateRing = (m) => {
            if (!m) return;
            const c = 2 * Math.PI * 15.5;
            const cIn = 2 * Math.PI * 11.5;
            const pct = Math.max(0, Math.min(100, Number(m.percent) || 0));
            // fond clair : teintes plus foncées pour rester lisibles (contraste)
            const mode = document.documentElement.dataset.mode;
            const light = mode === "light" || (mode === "auto" && matchMedia("(prefers-color-scheme: light)").matches);
            const color = light
                ? (pct >= 80 ? "#dc2626" : pct >= 50 ? "#b45309" : "#059669")
                : (pct >= 80 ? "#f87171" : pct >= 50 ? "#fbbf24" : "#34d399");
            const memPct = m.window ? Math.min(100, (Number(m.summary_tokens) || 0) / m.window * 100) : 0;

            ringSvgs.forEach((svg) => {
                const fill = svg.querySelector(".ring-fill");
                if (!fill) return;
                fill.setAttribute("stroke-dasharray", (c * pct / 100).toFixed(2) + " " + c.toFixed(2));
                fill.setAttribute("stroke", color);
                const val = svg.querySelector(".ring-val");
                if (val) {
                    val.textContent = pct;
                    val.setAttribute("fill", color);
                }
                const mem = svg.querySelector(".ring-mem");
                if (mem && memPct > 0) {
                    mem.setAttribute("stroke-dasharray", (cIn * memPct / 100).toFixed(2) + " " + cIn.toFixed(2));
                }
            });

            const lg = document.querySelector(".mem-legend");
            if (lg) {
                const strongs = lg.querySelectorAll("strong");
                if (strongs[0]) strongs[0].textContent = tr("chat.percent", { n: pct });
                if (strongs[1]) strongs[1].textContent = tr("chat.percent", { n: Math.round(memPct) });
                const dot = lg.querySelector(".dot-ctx");
                if (dot) dot.style.background = color;
            }
        };

        // le pied de page ne sert plus qu'à prévenir quand aucun modèle n'est configuré :
        // modèle, contexte et compressions sont dans l'anneau et le panneau « Contexte et mémoire »
        const updateFoot = () => {};


        /* --- état du serveur / du modèle chargé --- */
        const statusBox = document.querySelector("[data-lms-status]");
        const statusUrl = chat.dataset.status;
        const dot = document.querySelector("[data-model-dot]");
        let statusTimer = null;
        let lastStatus = null;

        const setDot = (state, title) => {
            if (!dot) return;
            dot.dataset.state = state;
            dot.title = title || "";
        };

        const fetchStatus = async () => {
            try {
                const r = await fetch(statusUrl, { headers: { "X-Requested-With": "XMLHttpRequest" } });
                return await r.json();
            } catch (e) {
                return null;
            }
        };

        const paintStatus = (d) => {
            lastStatus = d;
            if (!d || d.ok === false) {
                setDot("ko", d && d.url ? tr("lms.down_url", { url: d.url }) : tr("lms.down"));
            } else if (d.is_loaded) {
                setDot("ok", tr("lms.loaded", { model: d.wanted }));
            } else {
                setDot("ko", tr("lms.not_loaded_title", { model: d.wanted }));
            }

            if (attachBtn) {
                attachBtn.hidden = !(d && d.vision === true);
                // 👁 devant les modèles de vision dans la liste du panneau (une seule fois)
                if (d && Array.isArray(d.vision_models)) {
                    const vis = new Set(d.vision_models);
                    document.querySelectorAll("[data-model-override] option[value]").forEach((o) => {
                        if (o.value === "" || o.dataset.vision === "1" || !vis.has(o.value)) return;
                        o.dataset.vision = "1";
                        o.textContent += " 👁";
                        o.title = tr("chat.vision_model");
                    });
                    const hint = document.querySelector("[data-model-vision-hint]");
                    if (hint) hint.hidden = vis.size === 0;
                }
                if (attachBtn.hidden && pendingFile) clearAttach();
            }

            if (!statusBox) return;
            const text = statusBox.querySelector(".lms-text");
            if (!d || d.ok === false) {
                statusBox.dataset.state = "ko";
                text.textContent = d && d.url ? tr("lms.down_no_answer", { url: d.url }) : tr("lms.down");
                return;
            }
            if (d.is_loaded) {
                const l = (d.loaded || []).find((x) => x.key === d.wanted || x.id === d.wanted) || (d.loaded || [])[0];
                statusBox.dataset.state = "ok";
                const b = document.createElement("b");
                b.textContent = String(l ? l.key : d.wanted);
                text.replaceChildren(...withNodes(l && l.ctx ? tr("lms.loaded_ctx", { n: fmt(l.ctx) }) : tr("lms.loaded"), { model: b }));
            } else {
                statusBox.dataset.state = "idle";
                const b = document.createElement("b");
                b.textContent = String(d.wanted);
                text.replaceChildren(...withNodes(tr("lms.not_loaded"), { model: b }));
            }
        };

        const loadStatus = async (pollMs = 0) => {
            if (!statusBox || !statusUrl) return;
            clearTimeout(statusTimer);
            try {
                const res = await fetch(statusUrl, { headers: { "X-Requested-With": "XMLHttpRequest" } });
                const d = await res.json();
                paintStatus(d);
                if (d.ok && !d.is_loaded && pollMs) {
                    statusTimer = setTimeout(() => loadStatus(pollMs), pollMs);
                }
            } catch (e) {
                paintStatus(null);
            }
        };

        /* modèle de cette conversation (test) : enregistré dès le choix, sans toucher au persona */
        const mForm = document.querySelector("[data-model-override]");
        if (mForm) {
            const sel = mForm.querySelector("select[name=model]");
            const reset = mForm.querySelector("[data-model-reset]");
            const hint = mForm.querySelector("[data-model-hint]");
            const hintOff = hint?.textContent || "";
            const paintOverride = (on) => {
                mForm.classList.toggle("is-override", on);
                if (reset) reset.hidden = !on;
                document.querySelector("[data-model-test]")?.toggleAttribute("hidden", !on);
                if (hint) hint.textContent = on ? tr("chat.model_hint_on") : hintOff;
            };
            const save = async () => {
                sel.disabled = true;
                try {
                    const fd = new FormData(mForm);
                    fd.set("model", sel.value);
                    const r = await fetch(mForm.action, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
                    const d = await r.json().catch(() => ({ ok: false }));
                    if (!d.ok) throw new Error(d.error || tr("common.send_failed"));
                    chat.dataset.model = d.model;
                    const name = document.querySelector("[data-model-name]");
                    if (name) name.textContent = d.model || tr("chat.model_default");
                    paintOverride(!!d.override);
                    toast({ title: d.override ? tr("chat.model_changed") : tr("chat.model_back"), text: d.model, code: true });
                    loadStatus(2500); // nouveau modèle : chargé ou non dans LM Studio ?
                } catch (e) {
                    toast(tr("common.error", { message: e.message }), true);
                } finally {
                    sel.disabled = false;
                }
            };
            paintOverride(sel.value !== "");
            if (sel.value !== "" && hint) hint.textContent = tr("chat.model_hint_on");
            sel.addEventListener("change", save);
            reset?.addEventListener("click", () => {
                sel.value = "";
                save();
            });
        }

        document.querySelector("[data-lms-refresh]")?.addEventListener("click", () => loadStatus(2000));
        loadStatus();
        setInterval(() => {
            if (!document.hidden) loadStatus();
        }, 8000);
        document.querySelectorAll("[data-open-settings]").forEach((b) =>
            b.addEventListener("click", () => loadStatus(2500))
        );

        /* --- souvenirs partagés (mémoire profonde) --- */
        const factBox = document.querySelector("[data-fact-box]");

        const renderFacts = (list) => {
            if (!factBox) return;
            const ul = factBox.querySelector("[data-fact-list]");
            if (!ul) return;
            ul.innerHTML = "";
            if (!list || !list.length) {
                const li = document.createElement("li");
                li.className = "fact-empty";
                li.textContent = tr("facts.empty");
                ul.appendChild(li);
                return;
            }
            list.forEach((f) => {
                const li = document.createElement("li");
                li.dataset.fid = f.id;
                const s = document.createElement("span");
                s.className = "fact-txt";
                s.textContent = f.content;
                const b = document.createElement("button");
                b.type = "button";
                b.className = "fact-del";
                b.dataset.factDel = f.id;
                b.setAttribute("aria-label", tr("facts.delete"));
                b.innerHTML = "&times;";
                li.append(s, b);
                ul.appendChild(li);
            });
        };

        if (factBox) {
            const fToken = factBox.dataset.token;

            factBox.querySelector("[data-fact-form]")?.addEventListener("submit", async (e) => {
                e.preventDefault();
                const input = e.target.querySelector("input[name=content]");
                const v = (input.value || "").trim();
                if (!v) return;
                const fd = new FormData();
                fd.append("_token", fToken);
                fd.append("content", v);
                try {
                    const r = await fetch(factBox.dataset.add, { method: "POST", body: fd });
                    const d = await r.json();
                    if (!d.ok) throw new Error(d.error || tr("facts.add_failed"));
                    input.value = "";
                    renderFacts(d.memories);
                    notice(tr("facts.added"));
                } catch (err) {
                    notice(tr("common.error", { message: err.message }));
                }
            });

            factBox.addEventListener("click", async (e) => {
                const b = e.target.closest("[data-fact-del]");
                if (!b) return;
                const fd = new FormData();
                fd.append("_token", fToken);
                fd.append("fid", b.dataset.factDel);
                try {
                    const r = await fetch(factBox.dataset.del, { method: "POST", body: fd });
                    const d = await r.json();
                    if (!d.ok) throw new Error(d.error || tr("common.delete_failed"));
                    renderFacts(d.memories);
                } catch (err) {
                    notice(tr("common.error", { message: err.message }));
                }
            });

            factBox.querySelector("[data-facts-toggle]")?.addEventListener("change", async (e) => {
                const fd = new FormData();
                fd.append("_token", fToken);
                fd.append("enabled", e.target.checked ? "1" : "0");
                try {
                    const r = await fetch(factBox.dataset.factsUrl, { method: "POST", body: fd });
                    const d = await r.json();
                    if (!d.ok) throw new Error(d.error || tr("common.impossible"));
                    notice(d.enabled ? tr("facts.enabled") : tr("facts.disabled"));
                } catch (err) {
                    e.target.checked = !e.target.checked;
                    notice(tr("common.error", { message: err.message }));
                }
            });
        }

        /* --- recherche dans les autres conversations du persona --- */
        const recallBox = document.querySelector("[data-recall-box]");
        const recallList = document.querySelector("[data-recall-list]");

        const recallItem = (x) => {
            const li = document.createElement("li");
            li.className = "recall-item";

            const body = document.createElement("div");
            body.className = "recall-body";
            const meta = document.createElement("span");
            meta.className = "recall-meta";
            meta.textContent = x.date + " · ";
            const link = document.createElement("a");
            link.href = "/chat/" + x.conversation_id;
            link.className = "recall-link";
            link.textContent = x.title;
            meta.appendChild(link);
            const p = document.createElement("p");
            p.className = "recall-txt";
            p.textContent = x.excerpt;
            body.append(meta, p);
            li.appendChild(body);

            if (factBox) {
                const save = document.createElement("button");
                save.type = "button";
                save.className = "msg-tool";
                save.textContent = tr("recall.memorize");
                save.addEventListener("click", async () => {
                    const fd = new FormData();
                    fd.append("_token", factBox.dataset.token);
                    fd.append("content", "Rappel : " + (x.content || x.excerpt).replace(/…/g, ""));
                    try {
                        const r = await fetch(factBox.dataset.add, { method: "POST", body: fd });
                        const d = await r.json();
                        if (!d.ok) throw new Error(d.error || tr("common.impossible"));
                        renderFacts(d.memories);
                        notice(tr("recall.memorized"));
                    } catch (err) {
                        notice(tr("common.error", { message: err.message }));
                    }
                });
                li.appendChild(save);
            }
            return li;
        };

        if (recallBox && recallList) {
            const empty = (txt) => {
                recallList.innerHTML = "";
                const li = document.createElement("li");
                li.className = "fact-empty";
                li.textContent = txt;
                recallList.appendChild(li);
            };

            recallBox.querySelector("[data-recall-form]")?.addEventListener("submit", async (e) => {
                e.preventDefault();
                const input = e.target.querySelector("input[name=q]");
                const q = (input.value || "").trim();
                if (q.length < 2) return;
                empty(tr("recall.searching"));
                try {
                    const r = await fetch(recallBox.dataset.url + "?q=" + encodeURIComponent(q), {
                        headers: { "X-Requested-With": "XMLHttpRequest" },
                    });
                    const d = await r.json();
                    if (!d.results || !d.results.length) {
                        empty(tr("recall.none"));
                        return;
                    }
                    recallList.innerHTML = "";
                    d.results.forEach((x) => recallList.appendChild(recallItem(x)));
                } catch (err) {
                    empty(tr("recall.failed"));
                }
            });
        }

        const updateMemPanel = (m, summary) => {
            if (!m) return;
            const box = document.querySelector(".mem-box");
            if (box) {
                const vals = box.querySelectorAll(".mem-stats strong");
                if (vals[0]) vals[0].textContent = m.messages;
                if (vals[1]) vals[1].textContent = m.unsummarized;
                if (vals[2]) vals[2].textContent = m.compressions;
                if (vals[3]) vals[3].textContent = fmt(m.context_tokens);
                const p = box.querySelector(".mem-pct");
                if (p) {
                    p.textContent = tr("chat.percent", { n: m.percent || 0 });
                    p.classList.toggle("is-hot", (m.percent || 0) > 80);
                }
            }
            const ctx = document.querySelector(".sheet-ctx-text");
            if (ctx) {
                const strong = ctx.querySelector("strong");
                if (strong) strong.textContent = tr("chat.ctx_percent", { n: m.percent || 0 });
                const sp = ctx.querySelectorAll("span");
                if (sp[0]) sp[0].textContent = tr("chat.ctx_tokens", { used: fmt(m.context_tokens), window: fmt(m.window) });
                if (sp[1] && typeof m.compressions === "number") {
                    sp[1].textContent = tr("chat.ctx_auto_summary", { n: m.compressions, threshold: sp[1].dataset.threshold || "" });
                }
            }
            if (typeof summary === "string" && summary.trim() !== "") {
                const ta = document.querySelector('.mem-form textarea[name="summary"]');
                if (ta) ta.value = summary;
            }
        };

        const compressMemory = async (force = false) => {
            try {
                const fd = new FormData();
                fd.append("_token", token);
                if (force) fd.append("force", "1");
                const res = await fetch(chat.dataset.compress, { method: "POST", body: fd });
                const data = await res.json();
                if (!data.ok) return null;
                updateFoot();
                updateRing(data.memory);
                updateMemPanel(data.memory, data.summary);
                return data;
            } catch (e) {
                return null;
            }
        };

        const rememberFacts = async () => {
            try {
                const fd = new FormData();
                fd.append("_token", token);
                const res = await fetch(chat.dataset.remember, { method: "POST", body: fd });
                const data = await res.json();
                if (data && Array.isArray(data.memories)) renderFacts(data.memories);
                if (data && data.memory_msg) {
                    scroll.appendChild(renderMsg({
                        id: data.memory_msg.id,
                        role: "memory",
                        content: data.memory_msg.content,
                    }));
                    scrollBottom();
                } else if (data.ok && Array.isArray(data.added) && data.added.length) {
                    notice(tr("facts.added_one", { fact: data.added[0] }));
                    scrollBottom();
                }
            } catch (e) {
                /* silencieux : la mémoire longue ne bloque jamais la conversation */
            }
        };

        /* souvenirs durables : dès la réponse, ou une fois la discussion calme
           (réglage « Moment de l'extraction ») pour laisser le modèle local libre
           pendant qu'on écrit */
        const factsTiming = chat.dataset.factsTiming || "now";
        const factsIdleMs = Math.max(10, Number(chat.dataset.factsIdle) || 45) * 1000;
        let factsTimer = 0;
        const runFacts = () => { factsTimer = 0; rememberFacts(); };
        const scheduleFacts = () => {
            if (factsTiming === "off") return Promise.resolve();
            if (factsTiming !== "idle") return rememberFacts();
            clearTimeout(factsTimer);
            factsTimer = setTimeout(runFacts, factsIdleMs);
            return Promise.resolve();
        };
        if (factsTiming === "idle") {
            // l'utilisateur écrit : on repousse l'extraction
            const delay = () => {
                if (!factsTimer) return;
                clearTimeout(factsTimer);
                factsTimer = setTimeout(runFacts, factsIdleMs);
            };
            document.addEventListener("input", (e) => { if (e.target.closest?.(".chat-composer")) delay(); });
        }

        // titre généré à partir des premiers échanges (tant qu'il n'a pas été renommé à la main)
        const autoTitle = async () => {
            if (chat.dataset.autoTitle !== "1") return;
            try {
                const fd = new FormData();
                fd.append("_token", token);
                const r = await fetch(endpoint.replace(/\/send$/, "/autotitle"), { method: "POST", body: fd });
                const d = await r.json();
                if (!d.ok || !d.title) return;

                chat.dataset.autoTitle = "0";
                const row = document.querySelector(".chat-side-row.is-on .chat-side-name");
                if (row) row.textContent = d.title;
                const head = document.querySelector("#chat-settings .sheet-head h2");
                if (head) head.textContent = d.title;
            } catch (e) {
                /* silencieux */
            }
        };

        const compressBtn = document.querySelector("[data-compress-now]");
        compressBtn?.addEventListener("click", async () => {
            const original = compressBtn.textContent;
            compressBtn.disabled = true;
            compressBtn.textContent = tr("chat.compressing");
            const data = await compressMemory(true);
            if (data && data.compressed > 0) {
                toast(tr("chat.compressed", data.compressed));
                setTimeout(() => window.location.reload(), 700);
            } else {
                toast(data ? (data.reason || tr("chat.nothing_to_compress")) : tr("chat.compress_failed"), true);
                compressBtn.disabled = false;
                compressBtn.textContent = original;
            }
        });

        const grow = () => {
            input.style.height = "auto";
            input.style.height = Math.min(input.scrollHeight, 132) + "px";
        };

        const syncSend = () => {
            // pendant une génération le bouton reste actif : il sert à arrêter
            sendBtn.disabled = !busy && input.value.trim() === "" && !pendingFile;
        };

        const streamUrl = endpoint.replace(/\/send$/, "/stream");

        const splitOn    = chat.dataset.split === "1";
        const splitWords = Math.max(8, parseInt(chat.dataset.splitWords || "50", 10));
        const splitMax   = Math.max(1, parseInt(chat.dataset.splitMax || "2", 10));
        const splitDmin  = Math.max(0.2, parseFloat(chat.dataset.splitDelayMin || "1"));
        const splitDmax  = Math.max(splitDmin, parseFloat(chat.dataset.splitDelayMax || "2"));
        const loadUrl = statusUrl.replace(/\/status$/, "/load");

        const startLiveBubble = () => {
            document.getElementById("chat-typing")?.remove();
            const wrap = document.createElement("div");
            wrap.className = "msg msg-bot";
            wrap.dataset.live = "1";
            const av = document.createElement("span");
            av.className = "msg-avatar";
            av.innerHTML = avatarHTML;
            const b = document.createElement("div");
            b.className = "bubble";
            wrap.append(av, b);
            scroll.appendChild(wrap);
            return b;
        };

        /* --- chargement manuel du modèle, en 4 étapes --- */
        const loadBtn = document.querySelector("[data-load-model]");
        const progressBox = document.querySelector("[data-lms-progress]");
        const stepsEl = document.querySelector("[data-lms-steps]");
        const timerEl = document.querySelector("[data-lms-timer]");

        const setStep = (name, state) => {
            const li = stepsEl && stepsEl.querySelector('[data-step="' + name + '"]');
            if (li) li.dataset.state = state;
        };
        const resetSteps = () => ["server", "request", "loading", "ready"].forEach((n) => setStep(n, ""));
        const errBox = document.querySelector("[data-lms-error]");
        const showLoadError = (hint, detail) => {
            if (!errBox) return;
            errBox.querySelector("[data-lms-error-hint]").textContent = hint || "";
            const more = errBox.querySelector(".lms-error-more");
            errBox.querySelector("[data-lms-error-detail]").textContent = detail || "";
            if (more) more.hidden = !detail;
            // modèle de test en cause : retour au modèle du persona en un geste
            const resetBtn = errBox.querySelector("[data-lms-error-reset]");
            const testModel = document.querySelector("[data-model-override] select")?.value || "";
            if (resetBtn) resetBtn.hidden = testModel === "";
            errBox.hidden = false;
        };
        errBox?.querySelector("[data-lms-error-reset]")?.addEventListener("click", () => {
            errBox.hidden = true;
            document.querySelector("[data-model-reset]")?.click();
        });
        const loadResultUrl = statusUrl.replace(/\/status$/, "/load-result");

        const loadModel = async () => {
            if (!loadBtn || loadBtn.disabled) return;
            loadBtn.disabled = true;
            if (errBox) errBox.hidden = true;
            if (progressBox) progressBox.hidden = false;
            if (timerEl) timerEl.hidden = false;
            resetSteps();

            const t0 = Date.now();
            const tick = setInterval(() => {
                if (timerEl) timerEl.textContent = tr("lms.seconds", { n: fmt1((Date.now() - t0) / 1000) });
            }, 100);

            const label = loadBtn.querySelector("[data-load-label]") || loadBtn;
            const done = (txt) => {
                clearInterval(tick);
                loadBtn.disabled = false;
                label.textContent = txt;
                setTimeout(() => (label.textContent = tr("lms.load_now")), 4000);
            };

            // 1. le serveur répond ?
            setStep("server", "active");
            let st = await fetchStatus();
            paintStatus(st);
            if (!st || st.reachable === false) {
                setStep("server", "fail");
                done(tr("lms.unreachable"));
                return;
            }
            setStep("server", "done");

            if (st.is_loaded) {
                setStep("request", "done");
                setStep("loading", "done");
                setStep("ready", "done");
                if (timerEl) timerEl.textContent = tr("lms.already_loaded_timer");
                done(tr("lms.already_loaded"));
                return;
            }

            // 2. demande de chargement
            setStep("request", "active");
            let asked = null;
            try {
                const fd = new FormData();
                fd.append("_token", token);
                const r = await fetch(loadUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
                asked = await r.json();
            } catch (e) {
                asked = { ok: false, error: tr("lms.request_failed") };
            }
            if (!asked || !asked.ok) {
                setStep("request", "fail");
                done(tr("lms.load_failed"));
                showLoadError((asked && asked.error) || tr("lms.unknown_error"), "");
                return;
            }
            setStep("request", "done");

            // 3. chargement en cours -> 4. prêt
            setStep("loading", "active");
            const deadline = Date.now() + 180000;
            for (;;) {
                await new Promise((r) => setTimeout(r, 700));
                st = await fetchStatus();
                paintStatus(st);
                // LM Studio a répondu à la demande de chargement : échec → on le dit tout de suite
                const res = await fetch(loadResultUrl, { headers: { "X-Requested-With": "XMLHttpRequest" } })
                    .then((r) => r.json()).catch(() => null);
                if (res && res.done && !res.ok && !(st && st.is_loaded)) {
                    setStep("loading", "fail");
                    done(tr("lms.load_failed"));
                    showLoadError(res.hint, res.error);
                    return;
                }
                if (st && st.is_loaded) {
                    setStep("loading", "done");
                    setStep("ready", "done");
                    done(tr("lms.loaded_short"));
                    return;
                }
                if (Date.now() > deadline) {
                    setStep("loading", "fail");
                    done(tr("lms.too_long"));
                    return;
                }
            }
        };

        loadBtn?.addEventListener("click", loadModel);

        /* --- bascule manuelle vers DeepSeek --- */
        const fbModal = document.getElementById("fb-modal");
        let lastText = "";

        const askFallback = (text, msg, kind) => {
            lastText = text;
            if (!fbModal) {
                notice(tr("common.error", { message: msg }));
                return;
            }
            const h = fbModal.querySelector("h2");
            if (h) {
                h.textContent =
                    kind === "down" ? tr("lms.down")
                    : kind === "not_loaded" ? tr("chat.fb_not_loaded")
                    : tr("chat.fb_no_reply");
            }
            const p = fbModal.querySelector("[data-fb-msg]");
            if (p) p.textContent = msg;
            fbModal.hidden = false;
        };

        fbModal?.querySelectorAll("[data-fb-wait]").forEach((b) =>
            b.addEventListener("click", () => {
                fbModal.hidden = true;
                if (lastText) {
                    input.value = lastText;
                    grow();
                    syncSend();
                    input.focus();
                }
                scroll.querySelectorAll(".msg-notice[data-fb-kept]").forEach((n) => n.remove());
                notice(tr("chat.fb_kept"));
                scroll.lastElementChild?.setAttribute("data-fb-kept", "1");
                scrollBottom();
            })
        );

        fbModal?.querySelector("[data-fb-use]")?.addEventListener("click", () => {
            fbModal.hidden = true;
            const t = lastText;
            if (t) {
                input.value = "";
                grow();
                send(t, "fallback", true);
            }
        });

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && fbModal && !fbModal.hidden) fbModal.hidden = true;
        });

        /* --- outils par message (modifier / supprimer / régénérer) --- */
        const deleteUrl = endpoint.replace(/\/send$/, "/msg-delete");
        const editUrl   = endpoint.replace(/\/send$/, "/msg-edit");

        const ICONS = {
            regen: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.4-5.7M20 4v5h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            edit:  '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9a2.5 2.5 0 0 0-3.5-3.5L4.5 16.5 4 20Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
            del:   '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M9.5 7V5h5v2M7.5 7l.8 12h7.4l.8-12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            ok:    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 13 4.5 4.5L19 6.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            no:    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        };

        const toolBtn = (kind, icon, label) => {
            const b = document.createElement("button");
            b.type = "button";
            b.className = "msg-tool msg-tool-" + kind;
            b.setAttribute("data-" + kind, "1");
            b.setAttribute("aria-label", label);
            b.title = label;
            b.innerHTML = icon;
            return b;
        };

        /* date et heure d'un message, dans la langue et le fuseau du téléphone :
           « Aujourd'hui à 14:05 », « Hier à 21:10 », « 8 nov. à 09:30 » (année si différente) */
        const fmtMsgTime = (iso) => {
            const d = new Date(iso || "");
            if (Number.isNaN(d.getTime())) return "";
            const now = new Date();
            const dayStart = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
            const diff = Math.round((dayStart(now) - dayStart(d)) / 864e5);
            const time = d.toLocaleTimeString(LOCALE, { hour: "2-digit", minute: "2-digit" });
            let date;
            if (diff === 0 || diff === 1) {
                const rel = new Intl.RelativeTimeFormat(LOCALE, { numeric: "auto" }).format(-diff, "day");
                date = rel.charAt(0).toUpperCase() + rel.slice(1);
            } else {
                date = d.toLocaleDateString(LOCALE, d.getFullYear() === now.getFullYear()
                    ? { day: "numeric", month: "short" }
                    : { day: "numeric", month: "short", year: "numeric" });
            }
            return tr("chat.msg_time", { date, time });
        };

        const buildTools = (el) => {
            if (!el.dataset.msgId) return null;
            const isBot = el.classList.contains("msg-bot");
            const bots = [...scroll.querySelectorAll(".msg-bot")];
            const isLastBot = isBot && bots[bots.length - 1] === el;

            const tools = document.createElement("div");
            tools.className = "msg-tools";

            const when = fmtMsgTime(el.dataset.at);
            if (when) {
                const t = document.createElement("time");
                t.className = "msg-time";
                t.dateTime = el.dataset.at;
                t.textContent = when;
                tools.appendChild(t);
            }

            if (isLastBot) tools.appendChild(toolBtn("regen", ICONS.regen, tr("chat.regen")));
            // seuls mes messages sont modifiables : ceux du personnage se régénèrent ou se suppriment
            if (!isBot) tools.appendChild(toolBtn("edit", ICONS.edit, tr("chat.edit")));
            tools.appendChild(toolBtn("del", ICONS.del, tr("chat.delete")));
            return tools;
        };

        /* idées de premier message : remplit la zone de saisie (sans envoyer) */
        document.querySelectorAll("[data-starter]").forEach((b) => {
            b.addEventListener("click", () => {
                input.value = b.dataset.starter;
                input.dispatchEvent(new Event("input", { bubbles: true }));
                input.focus();
                input.setSelectionRange(input.value.length, input.value.length);
            });
        });

        /* ‹ 1/3 › sous la dernière réponse : passer d'une version régénérée à l'autre */
        const versionUrl = endpoint.replace(/\/send$/, "/version");
        const paintVersions = () => {
            scroll.querySelectorAll(".msg-versions").forEach((n) => n.remove());
            const bots = [...scroll.querySelectorAll(".msg-bot")];
            const last = bots[bots.length - 1];
            const total = Number(last?.dataset.vers || 0);
            const idx = Number(last?.dataset.ver || 0);
            if (!last || total < 2 || !last.dataset.msgId) return;
            const box = document.createElement("div");
            box.className = "msg-versions";
            box.setAttribute("role", "group");
            box.setAttribute("aria-label", tr("chat.versions_label"));
            const arrow = (dir, path, label, off) => {
                const b = document.createElement("button");
                b.type = "button";
                b.dataset.verDir = String(dir);
                b.setAttribute("aria-label", label);
                b.title = label;
                b.disabled = off;
                b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + path + '" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                return b;
            };
            const count = document.createElement("span");
            count.textContent = tr("chat.versions_count", { index: idx, total });
            box.append(
                arrow(-1, "m14.5 6-6 6 6 6", tr("chat.version_prev"), idx <= 1),
                count,
                arrow(1, "m9.5 6 6 6-6 6", tr("chat.version_next"), idx >= total),
            );
            last.appendChild(box);
        };

        const switchVersion = async (el, dir) => {
            if (busy || el.dataset.switching === "1") return;
            el.dataset.switching = "1";
            try {
                const fd = new FormData();
                fd.append("_token", token);
                fd.append("mid", el.dataset.msgId);
                fd.append("dir", String(dir));
                const r = await fetch(versionUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
                const d = await r.json().catch(() => ({ ok: false }));
                if (!d.ok) throw new Error(d.error || tr("chat.version_error"));
                const fresh = renderMsg({
                    id: el.dataset.msgId,
                    role: "assistant",
                    content: d.content,
                    split_points: d.split_points || [],
                    at: el.dataset.at,
                });
                fresh.dataset.ver = String(d.versions.index);
                fresh.dataset.vers = String(d.versions.total);
                el.replaceWith(fresh);
                paintVersions();
            } catch (e) {
                delete el.dataset.switching;
                notice(tr("chat.version_error") + " " + e.message);
            }
        };
        paintVersions();

        const removeMsg = async (id) => {
            try {
                const fd = new FormData();
                fd.append("_token", token);
                fd.append("mid", id);
                const r = await fetch(deleteUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
                const d = await r.json().catch(() => ({ ok: false }));
                if (!d.ok) throw new Error(d.error || tr("common.delete_failed"));
                (d.deleted || []).forEach((mid) => {
                    scroll.querySelector('[data-msg-id="' + mid + '"]')?.remove();
                });
                scroll.querySelectorAll(".msg-tools").forEach((t) => t.remove());
                paintVersions();
                loadStatus();
            } catch (e) {
                notice(tr("chat.erase_error", { message: e.message }));
            }
        };

        const startEdit = (el) => {
            if (el.dataset.editing === "1") return;
            const id = el.dataset.msgId;
            const block = el.querySelector(".bubbles") || el.querySelector(".bubble");
            if (!id || !block) return;

            const full = [...el.querySelectorAll(".bubble")]
                .map((b) => b.textContent.trim())
                .join("\n\n");

            const editor = document.createElement("div");
            editor.className = "msg-edit";

            const ta = document.createElement("textarea");
            ta.className = "input";
            ta.value = full;
            ta.rows = Math.min(9, Math.max(3, Math.ceil(full.length / 42)));

            const actions = document.createElement("div");
            actions.className = "msg-edit-actions";
            const ok = toolBtn("save", ICONS.ok, tr("chat.save"));
            const no = toolBtn("cancel", ICONS.no, tr("modal.cancel"));
            ok.classList.add("is-ok");
            actions.append(ok, no);

            editor.append(ta, actions);
            el._editBackup = block;
            block.replaceWith(editor);
            el.dataset.editing = "1";
            el.querySelectorAll(".msg-tools").forEach((t) => t.remove());
            ta.focus();
            ta.setSelectionRange(ta.value.length, ta.value.length);
        };

        const saveEdit = async (el) => {
            const id = el.dataset.msgId;
            const ta = el.querySelector(".msg-edit textarea");
            if (!id || !ta) return;
            const content = ta.value.trim();
            if (content === "") {
                notice(tr("chat.empty_message"));
                return;
            }
            try {
                const fd = new FormData();
                fd.append("_token", token);
                fd.append("mid", id);
                fd.append("content", content);
                const r = await fetch(editUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
                const d = await r.json().catch(() => ({ ok: false }));
                if (!d.ok) throw new Error(d.error || tr("chat.edit_failed"));

                const isUser = el.classList.contains("msg-user");
                const fresh = renderMsg({
                    id,
                    role: isUser ? "user" : "assistant",
                    content: d.content,
                    split_points: d.split_points || [],
                });
                fresh.dataset.msgId = id;
                el.replaceWith(fresh);

                if (d.replay) {
                    // la suite de la conversation a été supprimée côté serveur : on rejoue la réponse
                    let next = fresh.nextElementSibling;
                    while (next) {
                        const after = next.nextElementSibling;
                        next.remove();
                        next = after;
                    }
                    fresh.classList.remove("is-active");
                    notice(tr("chat.edited_replay"));
                    send(null, "auto", false, true, Number(id));
                } else {
                    notice(tr("chat.edited"));
                }
            } catch (e) {
                notice(tr("chat.edit_error", { message: e.message }));
            }
        };

        const regenerate = () => {
            const bots = [...scroll.querySelectorAll(".msg-bot")];
            const last = bots[bots.length - 1];
            if (!last) return;
            if (last.querySelector(".bubble")?.textContent === "") return;
            last.remove();
            scroll.querySelectorAll(".msg-tools").forEach((t) => t.remove());
            paintVersions();
            send(null, "auto", false, true);
        };

        scroll.addEventListener("click", (e) => {
            const del = e.target.closest("[data-del]");
            if (del) {
                e.stopPropagation();
                const el = del.closest(".msg");
                if (el) removeMsg(el.dataset.msgId);
                return;
            }
            const verBtn = e.target.closest("[data-ver-dir]");
            if (verBtn) {
                e.stopPropagation();
                const el = verBtn.closest(".msg");
                if (el) switchVersion(el, Number(verBtn.dataset.verDir));
                return;
            }
            if (e.target.closest("[data-regen]")) {
                e.stopPropagation();
                regenerate();
                return;
            }
            const editBtn = e.target.closest("[data-edit]");
            if (editBtn) {
                e.stopPropagation();
                const el = editBtn.closest(".msg");
                if (el) startEdit(el);
                return;
            }
            const saveBtn = e.target.closest("[data-save]");
            if (saveBtn) {
                e.stopPropagation();
                const el = saveBtn.closest(".msg");
                if (el) saveEdit(el);
                return;
            }
            if (e.target.closest("[data-cancel]")) {
                e.stopPropagation();
                const el = e.target.closest(".msg");
                const editor = el && el.querySelector(".msg-edit");
                if (el && editor && el._editBackup) {
                    editor.replaceWith(el._editBackup);
                    el.dataset.editing = "0";
                    delete el._editBackup;
                }
                return;
            }

            const msg = e.target.closest(".msg");
            if (!msg) return;
            if (msg.dataset.editing === "1") return;

            if (msg.querySelector(".msg-tools")) {
                msg.querySelector(".msg-tools").remove();
                return;
            }
            scroll.querySelectorAll(".msg-tools").forEach((t) => t.remove());
            const tools = buildTools(msg);
            if (tools) {
                msg.appendChild(tools);
                setTimeout(() => tools.scrollIntoView({ block: "nearest", behavior: "smooth" }), 40);
            }
        });

        const SEND_SVG = sendBtn.innerHTML;
        const STOP_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6.4" y="6.4" width="11.2" height="11.2" rx="2.6" fill="currentColor"/></svg>';
        let controller = null;

        const setStreaming = (on) => {
            sendBtn.classList.toggle("is-stop", on);
            sendBtn.innerHTML = on ? STOP_SVG : SEND_SVG;
            sendBtn.setAttribute("aria-label", on ? tr("chat.stop_label") : tr("chat.send"));
            sendBtn.title = on ? tr("chat.stop") : tr("chat.send");
        };

        sendBtn.addEventListener("click", (e) => {
            if (busy && controller) {
                e.preventDefault();
                controller.abort();
            }
        });

        /* --- photo jointe (uniquement si le modèle voit les images) --- */
        const attachBtn = form.querySelector("[data-attach]");
        const attachInput = form.querySelector("[data-attach-input]");
        const attachPreview = document.querySelector("[data-attach-preview]");
        let pendingFile = null;
        let pendingShrink = null;

        const clearAttach = () => {
            pendingFile = null;
            pendingShrink = null;
            if (attachInput) attachInput.value = "";
            if (attachPreview) {
                attachPreview.hidden = true;
                const img = attachPreview.querySelector("img");
                if (img) img.removeAttribute("src");
            }
            syncSend();
        };

        attachBtn?.addEventListener("click", () => attachInput?.click());
        attachInput?.addEventListener("change", () => {
            const f = attachInput.files && attachInput.files[0];
            if (!f) return;
            if (!/^image\//.test(f.type)) {
                notice(tr("chat.choose_image"));
                return;
            }
            pendingFile = f;
            pendingShrink = shrinkImage(f); // compressée pendant qu'on écrit le message
            const img = attachPreview?.querySelector("img");
            if (img) img.src = URL.createObjectURL(f);
            if (attachPreview) attachPreview.hidden = false;
            syncSend();
        });
        attachPreview?.querySelector("[data-attach-remove]")?.addEventListener("click", clearAttach);

        const send = async (overrideText = null, mode = "auto", echo = true, regen = false, regenMid = null) => {
            const text = regen ? "" : (overrideText || input.value.trim());
            if (!regen && text === "" && !pendingFile) return;
            if (busy) return;

            busy = true;
            syncSend();
            setStreaming(true);
            if (!overrideText && !regen) {
                input.value = "";
                grow();
            }

            let userEl = null;
            // pas de réponse du tout : le serveur n'a pas gardé le message (sauf photo jointe),
            // on retire donc sa bulle de l'écran pour ne pas afficher un message fantôme
            const dropGhost = (el) => {
                if (el && !el.querySelector(".bubble-img")) el.remove();
            };
            if (!regen) {
                clearIntro();
                if (echo) userEl = bubble(text, "user");
                scrollBottom(true, true);
            }
            typing();

            if (mode === "auto" && lastStatus && lastStatus.reachable === false) {
                document.getElementById("chat-typing")?.remove();
                setStreaming(false);
                dropGhost(userEl);
                askFallback(text, tr("chat.lms_no_answer", { url: lastStatus.url || tr("chat.configured_address") }), "down");
                busy = false;
                syncSend();
                input.value = text;
                grow();
                return;
            }

            if (lastStatus && lastStatus.reachable && !lastStatus.is_loaded) {
                setDot("load", tr("lms.loading"));
            }

            let live = null;
            let streamed = "";
            let cuts = [];
            let bubbleEls = [];
            let splitTimer = null;

            const countWords = (s) => (s.trim() === "" ? 0 : s.trim().split(/\s+/).length);

            // coupe une portion à la dernière fin de phrase avant la limite de mots
            const cutInSegment = (seg) => {
                const words = [];
                const reW = /\S+/g;
                let wm;
                while ((wm = reW.exec(seg))) words.push(wm.index);
                if (words.length <= splitWords) return 0;

                const ends = [];
                const reEnd = /[.!?…]+(?=\s|$)|\n/g;
                let mm;
                while ((mm = reEnd.exec(seg))) ends.push(mm.index + mm[0].length);
                if (!ends.length) return 0;

                const bounds = ends.map((at) => ({ at, n: words.filter((w) => w < at).length }));

                let chosen = null;
                for (const b of bounds) if (b.n <= splitWords) chosen = b;
                if (chosen && chosen.n < 6) chosen = null;
                if (!chosen) {
                    for (const b of bounds) {
                        if (b.n > splitWords) {
                            chosen = b;
                            break;
                        }
                    }
                }
                if (!chosen) chosen = bounds[bounds.length - 1];

                let cut = chosen.at;
                while (cut < seg.length && /\s/.test(seg[cut])) cut++;
                return cut;
            };

            const paintParts = () => {
                const bounds = [0, ...cuts, streamed.length];
                bubbleEls.forEach((el, i) => {
                    if (!el) return;
                    const s = bounds[i] ?? 0;
                    const e = bounds[i + 1] ?? streamed.length;
                    el.textContent = streamed.slice(s, e).trim();
                });
            };

            const maybeSplit = () => {
                if (!splitOn || bubbleEls.length >= splitMax) return;
                if (splitTimer || cuts.length >= bubbleEls.length) return;
                const start = cuts.length ? cuts[cuts.length - 1] : 0;
                const seg = streamed.slice(start);
                if (countWords(seg) < splitWords) return;

                const cut = cutInSegment(seg);
                if (cut <= 0) return;

                cuts.push(start + cut);
                paintParts();

                if (!splitTimer) {
                    const delay = splitDmin + Math.random() * (splitDmax - splitDmin);

                    // le personnage « écrit » la suite : trois points pendant la pause
                    typing();

                    splitTimer = setTimeout(() => {
                        bubbleEls.push(startLiveBubble());
                        paintParts();
                        splitTimer = null;
                        scrollBottom(false);
                    }, delay * 1000);
                }
            };

            const paintStream = () => {
                if (!live) return;
                if (!bubbleEls.length) bubbleEls.push(live);
                maybeSplit();
                paintParts();
                scrollBottom(false);
            };

            let probed = 0;
            const probe = setInterval(async () => {
                if (live || probed++ > 40) return;
                const d = await fetchStatus();
                paintStatus(d);
                if (!d || d.reachable === false) return;
                // on n'affiche une étiquette que si le modèle doit VRAIMENT être chargé
                // (les trois points suffisent à dire qu'il réfléchit)
                if (!d.is_loaded) loadingLabel(tr("lms.loading"));
            }, 1200);

            controller = new AbortController();

            try {
                const fd = new FormData();
                fd.append("_token", token);
                fd.append("message", text);
                if (mode === "fallback") fd.append("fallback", "1");
                if (regen) fd.append("regen", "1");
                if (regen && regenMid) fd.append("regen_mid", String(regenMid));
                if (pendingFile) {
                    const file = (await pendingShrink?.catch(() => null)) || pendingFile;
                    fd.append("image", file, file.name || "photo.jpg");
                    clearAttach();
                }

                const res = await fetch(streamUrl, {
                    method: "POST",
                    body: fd,
                    headers: { "Accept": "text/event-stream", "X-Requested-With": "XMLHttpRequest" },
                    signal: controller.signal,
                });

                const isSSE = res.ok && res.body && (res.headers.get("content-type") || "").includes("text/event-stream");

                if (!isSSE) {
                    const data = await res.json().catch(() => ({ ok: false, error: tr("common.unreadable") }));
                    document.getElementById("chat-typing")?.remove();
                    if (!res.ok || !data.ok) throw new Error(data.error || tr("common.send_failed"));
                    scroll.appendChild(renderMsg({
                        role: "assistant",
                        content: data.reply.content,
                        split_at: data.reply.split_at || 0,
                    }));
                    if (data.notice) notice(data.notice.content);
                } else {
                    const reader = res.body.getReader();
                    const dec = new TextDecoder();
                    let buf = "";
                    let finalId = null;
                    let finalVersions = null;
                    let failed = "";
                    let failedKind = "error";

                    for (;;) {
                        const { value, done } = await reader.read();
                        if (done) break;
                        buf += dec.decode(value, { stream: true });

                        let cut;
                        while ((cut = buf.indexOf("\n\n")) >= 0) {
                            const frame = buf.slice(0, cut);
                            buf = buf.slice(cut + 2);
                            const line = frame.split("\n").find((l) => l.startsWith("data:"));
                            if (!line) continue;
                            let evt;
                            try {
                                evt = JSON.parse(line.slice(5).trim());
                            } catch {
                                continue;
                            }

                            if (typeof evt.delta === "string" && evt.delta !== "") {
                                if (!live) {
                                    clearInterval(probe);
                                    loadingLabel("");
                                }
                                if (!live) live = startLiveBubble();
                                streamed += evt.delta;
                                paintStream();
                            } else if (evt.phase === "memory") {
                                if (!live) loadingLabel(tr("chat.memory_phase"));
                            } else if (evt.start) {
                                if (evt.user && userEl) {
                                    userEl.dataset.msgId = evt.user.id;
                                    if (evt.user.image) {
                                        const img = document.createElement("img");
                                        img.className = "bubble-img";
                                        img.alt = tr("chat.photo_alt");
                                        img.src = "/media/" + evt.user.image + ".thumb.webp";
                                        img.dataset.full = "/media/" + evt.user.image + ".webp";
                                        userEl.insertBefore(img, userEl.querySelector(".bubble"));
                                    }
                                }
                            } else if (evt.done) {
                                finalId = (evt.reply && evt.reply.id) || null;
                                finalVersions = (evt.reply && evt.reply.versions) || null;

                                // les coupes officielles viennent du serveur (stockées en base)
                                const pts = Array.isArray(evt.reply && evt.reply.split_points)
                                    ? evt.reply.split_points
                                    : [];
                                if (pts.length && live) {
                                    const cps = Array.from(streamed);
                                    const units = pts
                                        .map((n) => cps.slice(0, Number(n)).join("").length)
                                        .filter((n) => n > 0 && n < streamed.length);
                                    if (units.length) {
                                        cuts = units;
                                        if (splitTimer) {
                                            clearTimeout(splitTimer);
                                            splitTimer = null;
                                        }
                                        while (bubbleEls.length < cuts.length + 1) {
                                            bubbleEls.push(startLiveBubble());
                                        }
                                        paintParts();
                                    }
                                }

                                if (evt.notice) notice(evt.notice.content);
                            } else if (evt.error) {
                                failed = evt.error;
                                failedKind = evt.kind || "error";
                            }
                        }
                    }

                    document.getElementById("chat-typing")?.remove();
                    if (live) {
                        if (finalId) live.parentElement.dataset.msgId = finalId;
                        const liveMsg = live.closest(".msg");
                        if (liveMsg && finalVersions) {
                            liveMsg.dataset.ver = String(finalVersions.index);
                            liveMsg.dataset.vers = String(finalVersions.total);
                        }
                        paintVersions();
                        live.closest(".msg")?.setAttribute("data-at", new Date().toISOString());
                        live.parentElement.removeAttribute("data-live");
                    }

                    if (failed) {
                        if (!live) {
                            clearInterval(probe);
                            dropGhost(userEl);
                            askFallback(text, failed, failedKind);
                            busy = false;
                            setStreaming(false);
                            syncSend();
                            return;
                        }
                        notice(tr("chat.interrupted", { message: failed }));
                    } else if (!live) {
                        throw new Error(tr("chat.empty_reply"));
                    }
                }

                scrollBottom();
                compressMemory()
                    .then((d) => {
                        if (d && d.compressed > 0) {
                            notice(tr("chat.memory_updated", d.compressed));
                            scrollBottom();
                        }
                    })
                    .then(() => scheduleFacts())
                    .then(() => autoTitle());
            } catch (err) {
                document.getElementById("chat-typing")?.remove();
                if (err.name === "AbortError") {
                    if (live) live.parentElement.removeAttribute("data-live");
                    notice(tr("chat.aborted"));
                } else {
                    notice(tr("common.error", { message: err.message }));
                }
                scrollBottom();
            } finally {
                controller = null;
                clearInterval(probe);
                loadingLabel("");
                loadStatus();
                busy = false;
                setStreaming(false);
                syncSend();
                input.focus();
            }
        };

        form.addEventListener("submit", (e) => {
            e.preventDefault();
            send();
        });
        input.addEventListener("keydown", (e) => {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                send();
            }
        });
        input.addEventListener("input", () => {
            grow();
            syncSend();
        });
        syncSend();
    }


    /* ------------------------------------------------- purge mémoire LM Studio */
    const purgeBtn = document.querySelector("[data-purge-lms]");
    if (purgeBtn) {
        purgeBtn.addEventListener("click", async () => {
            const status = document.querySelector("[data-purge-status]");
            const original = purgeBtn.textContent;
            purgeBtn.disabled = true;
            purgeBtn.textContent = tr("settings.purging");

            try {
                const tokenInput = document.querySelector('input[name="_token"]');
                const fd = new FormData();
                fd.append("_token", tokenInput ? tokenInput.value : "");
                const res = await fetch("/settings/purge", { method: "POST", body: fd });
                const data = await res.json().catch(() => ({ ok: false, error: tr("common.unreadable") }));
                if (!res.ok || !data.ok) throw new Error(data.error || tr("settings.purge_failed"));

                const n = (data.unloaded || []).length;
                if (status) {
                    status.textContent = n
                        ? tr("settings.purge_unloaded", { n, list: data.unloaded.join(", ") })
                        : tr("settings.purge_none");
                }
                toast(n ? tr("settings.purge_done", { n }) : tr("settings.purge_nothing"));
            } catch (err) {
                toast(tr("common.error", { message: err.message }), true);
            } finally {
                purgeBtn.disabled = false;
                purgeBtn.textContent = original;
            }
        });
    }

})();
