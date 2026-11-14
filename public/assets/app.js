/* PersonaVault — interactions légères, sans dépendance */
(() => {
    "use strict";

    const unreadDots = document.querySelectorAll("[data-chat-unread]");
    if (unreadDots.length) {
        let checkingUnread = false;
        let unreadTimer = null;
        const updateUnreadTimer = () => {
            if (!document.hidden && unreadDots[0].hidden) {
                unreadTimer ??= setInterval(refreshUnread, 30000);
            } else if (unreadTimer !== null) {
                clearInterval(unreadTimer);
                unreadTimer = null;
            }
        };
        const refreshUnread = async () => {
            if (document.hidden || checkingUnread) return;
            checkingUnread = true;
            try {
                const res = await fetch("/chat/unread", { cache: "no-store" });
                if (!res.ok || res.redirected) return;
                const { unread } = await res.json();
                if (typeof unread === "boolean") {
                    unreadDots.forEach(dot => { dot.hidden = !unread; });
                    updateUnreadTimer();
                }
            } catch {
                // Conserver l'état affiché si la connexion est indisponible.
            } finally {
                checkingUnread = false;
            }
        };
        window.addEventListener("pageshow", event => { if (event.persisted) refreshUnread(); });
        document.addEventListener("visibilitychange", () => {
            updateUnreadTimer();
            refreshUnread();
        });
        updateUnreadTimer();
    }

    /* ------------------------------------------ clavier mobile (chat)
       iOS ne réduit pas 100dvh quand le clavier s'ouvre : la page défile et
       l'en-tête sort de l'écran. On cale la hauteur du chat sur la zone
       réellement visible (visualViewport) et on garde la page en haut. */
    if ((document.body.classList.contains("chat-room") || document.body.classList.contains("assist-room")) && window.visualViewport) {
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

    /* ------------------------------------- éditeur de prompt (v3, mobile d'abord)
       Le texte prend l'écran. L'IA est toujours en bas (actions du moment + consigne) ;
       ses propositions s'affichent dans le texte (ajouts en vert, retraits barrés) avec
       Refuser / Réessayer / Appliquer. Bilan, variantes, historique et options : une seule
       feuille qui monte du bas. */
    const pe = document.getElementById("prompt-editor");
    if (pe) {
        const form = pe.querySelector("[data-pe-form]");
        const title = form.querySelector("[name=title]");
        const body = form.querySelector("[name=body]");
        const kindSel = form.querySelector("[data-pe-kind]");
        const heading = pe.querySelector("[data-pe-heading]");
        const banner = pe.querySelector("[data-pe-banner]");
        const diffview = pe.querySelector("[data-pe-diffview]");
        const actions = pe.querySelector("[data-pe-actions]");
        const decide = pe.querySelector("[data-pe-decide]");
        const ai = pe.querySelector("[data-pe-ai]");
        const chips = pe.querySelector("[data-pe-chips]");
        const ask = pe.querySelector("[data-pe-ask]");
        const sendBtn = pe.querySelector("[data-pe-send]");
        const stopBtn = pe.querySelector("[data-pe-stop]");
        const busyEl = pe.querySelector("[data-pe-busy]");
        const sheet = pe.querySelector("[data-pe-sheet]");
        const sheetBack = pe.querySelector("[data-pe-sheet-back]");
        const sheetBody = pe.querySelector("[data-pe-sheet-body]");
        const sheetTitle = pe.querySelector("[data-pe-sheet-title]");
        const VISUAL = ["image", "negative", "video"];
        let current = null; // identifiant du prompt modifié (null : nouveau)
        let initial = "";
        let beforeAi = null; // texte avant la dernière proposition appliquée (annuler)
        let analysis = null; // { data, text }
        let pending = null; // proposition en revue { text, mode, instruction }
        let lastReq = null; // pour « Réessayer »
        let ctrl = null;
        const kindNow = () => kindSel.value || "image";
        const isVisual = () => VISUAL.includes(kindNow());
        const snapshot = () => [title.value, body.value, kindNow()].join("\u0001");
        const el = (tag, cls, text) => {
            const n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined) n.textContent = text;
            return n;
        };
        const button = (cls, text, onClick) => {
            const b = el("button", cls, text);
            b.type = "button";
            if (onClick) b.addEventListener("click", onClick);
            return b;
        };

        /* ---- différences mot à mot (propositions) et ligne à ligne (historique) */
        const lcs = (a, b) => {
            const n = a.length, m = b.length;
            const L = new Uint32Array((n + 1) * (m + 1));
            const at = (i, j) => i * (m + 1) + j;
            for (let i = n - 1; i >= 0; i--)
                for (let j = m - 1; j >= 0; j--)
                    L[at(i, j)] = a[i] === b[j] ? L[at(i + 1, j + 1)] + 1 : Math.max(L[at(i + 1, j)], L[at(i, j + 1)]);
            const ops = [];
            let i = 0, j = 0;
            while (i < n && j < m) {
                if (a[i] === b[j]) { ops.push(["=", a[i]]); i++; j++; }
                else if (L[at(i + 1, j)] >= L[at(i, j + 1)]) ops.push(["-", a[i++]]);
                else ops.push(["+", b[j++]]);
            }
            while (i < n) ops.push(["-", a[i++]]);
            while (j < m) ops.push(["+", b[j++]]);
            return ops;
        };
        const tokens = (s) => s.replace(/\r\n?/g, "\n").match(/\n|[^\S\n]+|[^\s,;.!?:]+|[,;.!?:]/g) || [];
        const wordDiff = (a, b) => {
            const ta = tokens(a), tb = tokens(b);
            return ta.length * tb.length > 2500000 ? [["-", a], ["+", b]] : lcs(ta, tb);
        };
        const isWord = (t) => /\S/.test(t) && !/^[,;.!?:]$/.test(t);
        const renderDiff = (ops) => {
            const frag = document.createDocumentFragment();
            const runs = [];
            ops.forEach(([o, t]) => {
                const last = runs[runs.length - 1];
                if (last && last.o === o) last.t += t;
                else runs.push({ o, t });
            });
            runs.forEach((r) => frag.appendChild(el("span", r.o === "+" ? "is-add" : r.o === "-" ? "is-del" : "", r.t)));
            return frag;
        };

        /* ---- feuille du bas (une seule, contenus variables) */
        const openSheet = (label, nodes) => {
            sheetTitle.textContent = label;
            sheetBody.replaceChildren(...nodes);
            sheet.hidden = false;
            sheetBack.hidden = false;
            sheetBody.scrollTop = 0;
            requestAnimationFrame(() => sheet.classList.add("is-on"));
        };
        const closeSheet = () => {
            sheet.classList.remove("is-on");
            sheet.hidden = true;
            sheetBack.hidden = true;
        };
        sheetBack.addEventListener("click", closeSheet);
        // tirer la poignée vers le bas pour fermer
        (() => {
            let y0 = null;
            const grab = pe.querySelector("[data-pe-sheet-grab]");
            grab.addEventListener("pointerdown", (e) => { y0 = e.clientY; grab.setPointerCapture(e.pointerId); });
            grab.addEventListener("pointermove", (e) => {
                if (y0 === null) return;
                sheet.style.transform = "translateY(" + Math.max(0, e.clientY - y0) + "px)";
            });
            const end = (e) => {
                if (y0 === null) return;
                const dy = e.clientY - y0;
                y0 = null;
                sheet.style.transform = "";
                if (dy > 80) closeSheet();
            };
            grab.addEventListener("pointerup", end);
            grab.addEventListener("pointercancel", end);
        })();
        pe.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && !sheet.hidden) {
                e.stopPropagation();
                closeSheet();
            }
        }, true);

        /* ---- état général */
        const paint = () => {
            pe.querySelector(".pe-head")?.setAttribute("data-kind", kindNow());
            kindSel.dataset.kind = kindNow();
            if (ai) paintAi();
        };
        const setBusy = (on, mode) => {
            pe.classList.toggle("is-ai-busy", on);
            if (!ai) return;
            busyEl.hidden = !on;
            ask.hidden = on;
            sendBtn.hidden = on;
            stopBtn.hidden = !on;
            body.readOnly = on;
            if (on) {
                const labels = {
                    analyze: tr("studio.busy_analyze"),
                    variants: tr("studio.busy_variants"),
                    complete: tr("studio.busy_complete"),
                    optimize: tr("studio.busy_optimize"),
                    instruct: tr("studio.busy_instruct"),
                };
                busyEl.textContent = labels[mode] || tr("studio.busy_create");
            }
            chips.querySelectorAll("button").forEach((b) => (b.disabled = on));
        };

        /* ---- actions du moment (une seule ligne qui défile) */
        const CHIPS = {
            image: () => tr("studio.chips_image"),
            video: () => tr("studio.chips_video"),
            negative: () => tr("studio.chips_negative"),
            system: () => tr("studio.chips_system"),
            other: () => tr("studio.chips_other"),
        };
        const STARTERS = {
            image: () => tr("studio.start_image"),
            video: () => tr("studio.start_video"),
            negative: () => tr("studio.start_negative"),
            system: () => tr("studio.start_system"),
            other: () => tr("studio.start_other"),
        };
        const split = (s) => s.split("|").map((x) => x.trim()).filter(Boolean);
        const chip = (label, onClick, cls = "") => button("pe-chip " + cls, label.replace(/^\+/, ""), onClick);
        const paintAi = () => {
            const empty = !body.value.trim();
            const kind = kindNow();
            const visual = isVisual();
            ask.placeholder = empty ? ask.dataset.phEmpty : ask.dataset.phFilled;
            const list = [];
            if (beforeAi !== null) list.push(chip(tr("studio.undo"), undo, "is-undo"));
            if (empty) {
                list.push(chip(tr("studio.create"), () => run("create", ""), "is-ai"));
                split((STARTERS[kind] || STARTERS.other)()).forEach((c) => list.push(chip(c, () => run("create", c))));
            } else {
                list.push(chip(tr("studio.bilan"), () => (analysis && analysis.text === body.value ? showBilan() : run("analyze", "")), "is-ai"));
                list.push(visual ? chip(tr("studio.optimize"), () => run("optimize", "")) : chip(tr("studio.complete"), () => run("complete", "")));
                if (visual && kind !== "negative") list.push(chip(tr("studio.variants"), () => run("variants", "")));
                const sugg = analysis && analysis.text === body.value ? analysis.data.suggestions || [] : [];
                sugg.forEach((c) => list.push(chip(c, () => run(visual ? "instruct" : "complete", c), "is-sugg")));
                split((CHIPS[kind] || CHIPS.other)()).forEach((c) =>
                    list.push(chip(c, () => run(c.startsWith("+") ? (visual ? "optimize" : "complete") : "instruct", c.replace(/^\+/, "")), c.startsWith("+") ? "is-add" : ""))
                );
            }
            const sig = list.map((b) => b.className + b.textContent).join("|");
            if (chips.dataset.sig !== sig) {
                chips.dataset.sig = sig;
                chips.replaceChildren(...list);
            }
        };

        /* ---- appel à l'IA */
        async function run(mode, instruction = "") {
            if (!ai || pe.classList.contains("is-ai-busy")) return;
            closeSheet();
            lastReq = { mode, instruction };
            ctrl = new AbortController();
            setBusy(true, mode);
            try {
                const fd = new FormData();
                fd.append("_token", form.querySelector("[name=_token]").value);
                fd.append("kind", kindNow());
                fd.append("title", title.value);
                fd.append("body", body.value);
                fd.append("instruction", instruction);
                fd.append("mode", mode);
                if (current) fd.append("prompt_id", current);
                const res = await fetch(ai.dataset.peAi, { method: "POST", body: fd, signal: ctrl.signal, headers: { "X-Requested-With": "XMLHttpRequest" } });
                const data = await res.json().catch(() => ({ ok: false, error: tr("common.unreadable") }));
                if (!res.ok || !data.ok) throw new Error(data.error || tr("prompt.ai_failed"));
                setBusy(false);
                if (data.image_note) toast({ title: tr("studio.note"), text: data.image_note });
                if (data.mode === "analyze") {
                    analysis = { data: data.analysis, text: body.value };
                    paintAi();
                    return showBilan();
                }
                if (data.mode === "variants") return showVariants(data.variants || []);
                if (data.mode === "complete" && data.nothing) return toast(tr("prompt.ai_nothing"));
                propose(data.body, data.mode, instruction);
            } catch (err) {
                setBusy(false);
                if (err.name === "AbortError") return toast(tr("studio.stopped"));
                toast({ title: tr("prompt.ai_failed"), text: err.message }, true);
            } finally {
                ctrl = null;
            }
        }
        stopBtn?.addEventListener("click", () => ctrl?.abort());
        const sendAsk = () => {
            const v = ask.value.trim();
            if (!body.value.trim()) return run("create", v);
            if (!v) return ask.focus();
            ask.value = "";
            run("instruct", v);
        };
        sendBtn?.addEventListener("click", sendAsk);
        ask?.addEventListener("keydown", (e) => {
            if (e.key === "Enter" && !e.isComposing) {
                e.preventDefault();
                sendAsk();
            }
        });

        /* ---- proposition : dans le texte, puis décider */
        const MODE_TITLE = {
            create: () => tr("studio.p_create"),
            optimize: () => tr("studio.p_optimize"),
            complete: () => tr("studio.p_complete"),
            instruct: () => tr("studio.p_instruct"),
            variants: () => tr("studio.p_variant"),
            history: () => tr("studio.p_history"),
        };
        function propose(text, mode, instruction = "") {
            pending = { text, mode, instruction };
            const old = body.value;
            const base = old.replace(/\s+$/, "");
            let add = 0, del = 0;
            diffview.replaceChildren();
            if (!old.trim()) {
                diffview.appendChild(el("span", "is-add", text));
                add = (text.match(/\S+/g) || []).length;
            } else if (mode === "complete" && text.startsWith(base)) {
                // complément : le texte existant estompé, puis l'ajout en vert
                const added = text.slice(base.length).replace(/^\s+/, "");
                diffview.append(el("span", "is-kept", base + "\n\n"), el("span", "is-add", added));
                add = (added.match(/\S+/g) || []).length;
            } else {
                const ops = wordDiff(old, text);
                add = ops.filter(([o, t]) => o === "+" && isWord(t)).length;
                del = ops.filter(([o, t]) => o === "-" && isWord(t)).length;
                diffview.appendChild(renderDiff(ops));
            }
            banner.replaceChildren(
                el("span", "pe-banner-ico", "✦"),
                el("strong", "", (MODE_TITLE[mode] || MODE_TITLE.instruct)()),
                el("span", "pe-banner-stats", "+" + add + (del ? " −" + del : ""))
            );
            if (instruction) banner.appendChild(el("small", "", "« " + instruction + " »"));
            if (mode === "complete") banner.appendChild(el("small", "is-safe", tr("studio.p_safe")));
            pe.classList.add("is-proposing");
            banner.hidden = false;
            diffview.hidden = false;
            body.hidden = true;
            actions.hidden = true;
            decide.hidden = false;
            diffview.scrollTop = 0;
            decide.querySelector("[data-pe-apply]").focus({ preventScroll: true });
        }
        const endProposal = () => {
            pending = null;
            pe.classList.remove("is-proposing");
            banner.hidden = true;
            diffview.hidden = true;
            body.hidden = false;
            actions.hidden = false;
            decide.hidden = true;
            paint();
        };
        decide.querySelector("[data-pe-refuse]").addEventListener("click", endProposal);
        decide.querySelector("[data-pe-retry]").addEventListener("click", () => {
            const r = lastReq;
            endProposal();
            if (r && r.mode !== "history") run(r.mode, r.instruction);
        });
        decide.querySelector("[data-pe-apply]").addEventListener("click", () => {
            if (!pending) return;
            beforeAi = body.value;
            body.value = pending.text;
            endProposal();
            body.classList.remove("is-flash");
            void body.offsetWidth;
            body.classList.add("is-flash");
            toast(tr("studio.applied"));
        });
        const undo = () => {
            if (beforeAi === null) return;
            body.value = beforeAi;
            beforeAi = null;
            paint();
        };

        /* ---- bilan */
        function showBilan() {
            const a = analysis?.data;
            if (!a) return;
            const visual = isVisual();
            const nodes = [];
            const head = el("div", "pe-score");
            const ringEl = el("div", "pe-ring is-" + a.level);
            ringEl.style.setProperty("--p", String(a.score));
            ringEl.appendChild(el("strong", "", String(a.score)));
            ringEl.setAttribute("role", "img");
            ringEl.setAttribute("aria-label", tr("studio.score_label", { n: a.score }));
            const txt = el("div", "pe-score-txt");
            txt.append(
                el("b", "pe-level is-" + a.level, a.level === "bon" ? tr("prompt.level_good") : a.level === "faible" ? tr("prompt.level_low") : tr("prompt.level_mid")),
                el("p", "", a.summary || "")
            );
            head.append(ringEl, txt);
            nodes.push(head);
            const ul = el("ul", "pe-crits");
            (a.criteria || []).forEach((c) => {
                const li = el("li", "is-" + c.status);
                const dot = el("span", "pe-dot");
                dot.title = c.status === "ok" ? tr("studio.crit_ok") : c.status === "partial" ? tr("studio.crit_partial") : tr("studio.crit_missing");
                const t = el("div", "pe-crit-txt");
                t.append(el("span", "", c.label));
                if (c.note) t.append(el("small", "", c.note));
                li.append(dot, t);
                if (c.status !== "ok") {
                    li.appendChild(button("pe-crit-add", tr("studio.crit_add"), () =>
                        run(visual ? "optimize" : "complete", tr("studio.focus", { label: c.label, note: c.note || "" }).replace(/\s*:\s*$/, ""))
                    ));
                }
                ul.appendChild(li);
            });
            nodes.push(ul);
            if (a.conflicts && a.conflicts.length) {
                const w = el("div", "pe-conflicts");
                w.appendChild(el("strong", "", tr("studio.conflicts")));
                const l = el("ul");
                a.conflicts.forEach((x) => l.appendChild(el("li", "", x)));
                w.appendChild(l);
                nodes.push(w);
            }
            const foot = el("div", "pe-sheet-foot");
            foot.append(
                button("btn btn-ghost", tr("studio.reanalyze"), () => run("analyze", "")),
                button("btn btn-primary", visual ? tr("studio.fix_visual") : tr("studio.fix_textual"), () => run(visual ? "optimize" : "complete", ""))
            );
            nodes.push(foot);
            openSheet(tr("studio.bilan"), nodes);
        }

        /* ---- variantes */
        function showVariants(vars) {
            const nodes = [el("p", "pe-sheet-lead", tr("studio.v_pick"))];
            vars.forEach((v, i) => {
                const b = button("pe-variant", "", () => {
                    closeSheet();
                    lastReq = { mode: "variants", instruction: "" };
                    propose(v.prompt, "variants");
                });
                b.append(el("span", "pe-v-n", String(i + 1)), el("strong", "", v.title || tr("studio.v_default", { n: i + 1 })), el("small", "", v.prompt));
                nodes.push(b);
            });
            const foot = el("div", "pe-sheet-foot");
            foot.append(button("btn btn-ghost", tr("studio.p_retry"), () => run("variants", "")));
            nodes.push(foot);
            openSheet(tr("studio.v_title"), nodes);
        }

        /* ---- historique et options (menu ⋯) */
        const lineView = (ops) => {
            const box = el("div", "pe-diff");
            const keep = ops.map((o, i) => o[0] !== "=" || ops.slice(Math.max(0, i - 2), i + 3).some((x) => x[0] !== "="));
            let hidden = 0;
            const flush = () => {
                if (hidden) box.appendChild(el("div", "pe-diff-skip", tr("prompt.history_same", hidden)));
                hidden = 0;
            };
            ops.forEach(([op, line], i) => {
                if (!keep[i]) return void hidden++;
                flush();
                box.appendChild(el("div", "pe-diff-line " + (op === "+" ? "is-add" : op === "-" ? "is-del" : "is-same"), (op === "=" ? "  " : op + " ") + (line || " ")));
            });
            flush();
            return box;
        };
        async function showHistory() {
            const list = el("div", "pe-hist-list");
            openSheet(tr("studio.history"), [el("p", "pe-sheet-lead", tr("studio.history_lead")), list]);
            if (!current) return void list.appendChild(el("p", "hint", tr("prompt.history_new")));
            list.appendChild(el("p", "hint", tr("prompt.history_loading")));
            try {
                const res = await fetch("/prompts/" + current + "/history", { headers: { "X-Requested-With": "XMLHttpRequest" } });
                const data = await res.json();
                if (!res.ok || !data.ok) throw new Error();
                list.replaceChildren();
                if (!data.versions.length) return void list.appendChild(el("p", "hint", tr("prompt.history_empty")));
                data.versions.forEach((v) => {
                    const item = el("details", "pe-hist");
                    const sum = el("summary");
                    const when = el("span", "pe-hist-when", v.when);
                    when.title = v.at;
                    const add = el("span", "pe-hist-add", "+" + v.added);
                    add.title = tr("prompt.history_added", v.added);
                    const del = el("span", "pe-hist-del", "−" + v.removed);
                    del.title = tr("prompt.history_removed", v.removed);
                    sum.append(when, add, del);
                    item.appendChild(sum);
                    item.addEventListener("toggle", () => {
                        if (!item.open || item.dataset.built) return;
                        item.dataset.built = "1";
                        item.appendChild(lineView(v.diff));
                        const tools = el("div", "pe-hist-tools");
                        // reprendre une version passe par la même revue qu'une proposition
                        const take = (text) => {
                            closeSheet();
                            lastReq = { mode: "history", instruction: "" };
                            propose(text, "history");
                        };
                        tools.append(
                            button("btn btn-ghost btn-sm", tr("prompt.history_take_old"), () => take(v.old_body)),
                            button("btn btn-ghost btn-sm", tr("prompt.history_take_new"), () => take(v.new_body))
                        );
                        item.appendChild(tools);
                    });
                    list.appendChild(item);
                });
            } catch {
                list.replaceChildren(el("p", "hint", tr("prompt.history_failed")));
            }
        }
        const menuItem = (icon, label, onClick, cls = "") => {
            const b = button("pe-menu-item " + cls, "", onClick);
            b.append(el("span", "pe-menu-ico", icon), el("span", "", label));
            return b;
        };
        pe.querySelector("[data-pe-more]").addEventListener("click", () => {
            const items = [
                menuItem("⧉", tr("studio.menu_copy"), (e) => {
                    copyText(body.value, e.currentTarget);
                    setTimeout(closeSheet, 500);
                }),
                menuItem("⟲", tr("studio.history"), showHistory),
            ];
            if (ai) items.push(el("p", "pe-menu-note", tr("studio.menu_model", { model: ai.dataset.model }) + " · " + (ai.dataset.vision === "1" ? tr("studio.menu_vision_on") : tr("studio.menu_vision_off"))));
            if (current) items.push(menuItem("🗑", tr("studio.menu_delete"), deletePrompt, "is-danger"));
            openSheet(tr("studio.menu"), items);
        });
        function deletePrompt() {
            if (!current) return;
            closeSheet();
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
        }

        /* ---- ouverture */
        const open = (card, withAi = false) => {
            ctrl?.abort();
            current = card ? card.dataset.promptId : null;
            form.action = current ? "/prompts/" + current : pe.dataset.newAction;
            title.value = card ? card.dataset.promptTitle || "" : "";
            body.value = card ? card.querySelector(".prompt-body").textContent : "";
            kindSel.value = card ? card.dataset.promptKind : "image";
            heading.textContent = current ? pe.dataset.labelEdit : pe.dataset.labelNew;
            beforeAi = null;
            analysis = null;
            setBusy(false);
            endProposal();
            closeSheet();
            if (ask) ask.value = "";
            initial = snapshot();
            paint();
            modalOpen(pe);
            if (withAi && ask) ask.focus({ preventScroll: true });
            // au doigt, le clavier ne s'ouvre pas tout seul : on lit d'abord ; à la souris, on écrit
            else if (!matchMedia("(pointer: coarse)").matches || !current) body.focus({ preventScroll: true });
        };
        document.addEventListener("click", (e) => {
            const edit = e.target.closest("[data-prompt-edit]");
            if (edit && !pe.contains(edit)) {
                if (window.getSelection && String(getSelection()).length > 0 && edit.tagName === "PRE") return; // sélection de texte
                open(edit.closest("[data-prompt-id]"), edit.hasAttribute("data-prompt-studio"));
                return;
            }
            if (e.target.closest("[data-prompt-new]")) open(null);
        });
        form.addEventListener("input", (e) => {
            if (e.target === ask) return;
            paint();
        });
        kindSel.addEventListener("change", paint);
        // fermer pendant une proposition : on la referme d'abord
        form.addEventListener("submit", () => pending && endProposal(), true);
    }

    /* ------------------------------------- assistant de création de persona
       Conversation : l'IA tient la fiche à jour et pose une question à la fois, avec des
       réponses toutes prêtes ; « Suggérer pour moi » la laisse inventer ; « Créer » rédige
       la fiche complète (prompt système et prompt image compris). La conversation est gardée
       dans l'onglet (sessionStorage) : un rechargement ne la perd pas. */
    const asRoot = document.querySelector("[data-assist]");
    if (asRoot && asRoot.querySelector("[data-as-thread]")) {
        const slug = asRoot.dataset.slug || "";
        const isNew = asRoot.dataset.new === "1";
        const KEY = "pv-assist:" + (slug || "new");
        const thread = asRoot.querySelector("[data-as-thread]");
        const chipsEl = asRoot.querySelector("[data-as-chips]");
        const formEl = asRoot.querySelector("[data-as-form]");
        const input = asRoot.querySelector("[data-as-input]");
        const sendBtn = asRoot.querySelector("[data-as-send]");
        const finalEl = asRoot.querySelector("[data-as-final]");
        const review = asRoot.querySelector("[data-as-review]");
        const reviewForm = asRoot.querySelector("[data-as-review-form]");
        const reviewFields = asRoot.querySelector("[data-as-review-fields]");
        const reviewScroll = asRoot.querySelector("[data-as-review-scroll]");
        const reviewBack = asRoot.querySelector("[data-as-review-back]");
        const reviewSave = asRoot.querySelector("[data-as-review-save]");
        const reviewError = asRoot.querySelector("[data-as-review-error]");
        const el = (tag, cls, text) => {
            const n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined) n.textContent = text;
            return n;
        };
        const parse = (s, d) => { try { return JSON.parse(s); } catch { return d; } };
        let state = {
            draft: parse(asRoot.dataset.draft, {}),
            progress: parse(asRoot.dataset.progress, { items: [], percent: 0 }),
            history: [], // [{role, content}] envoyé au serveur
            msgs: [], // [{role, text, kind}] affiché
            choices: [],
        };
        let busy = false;
        const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch {} };
        const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || "null"); } catch { return null; } };

        /* fiche en cours : jauge, étapes (la prochaine en surbrillance) et aperçu du texte */
        const FIELDS = [
            ["source", tr("assist.f.source")], ["name", tr("assist.f.name")], ["tagline", tr("assist.f.tagline")],
            ["description", tr("assist.f.description")], ["meta", tr("assist.f.meta")], ["looks", tr("assist.f.looks")],
            ["history", tr("assist.f.history")], ["family", tr("assist.f.family")],
            ["friends", tr("assist.f.friends")], ["work", tr("assist.f.work")], ["goals", tr("assist.f.goals")],
            ["style", tr("assist.f.style")], ["personality", tr("assist.f.personality")], ["voice", tr("assist.f.voice")],
            ["relation", tr("assist.f.relation")], ["rules", tr("assist.f.rules")], ["tags", tr("assist.f.tags")],
        ];
        const paintFiche = () => {
            const d = state.draft || {};
            const progress = state.progress;
            const pct = progress.percent || 0;
            asRoot.querySelector("[data-as-name]").textContent = d.name ? d.name + (d.tagline ? " · " + d.tagline : "") : tr("assist.unnamed");
            asRoot.querySelector("[data-as-pct]").textContent = pct + " %";
            asRoot.querySelector("[data-as-bar]").style.width = pct + "%";
            // étapes
            const items = progress.items || [];
            const next = items.findIndex((it) => it.key === state.topic && !it.done);
            const ol = asRoot.querySelector("[data-as-steps]");
            ol.replaceChildren(...items.map((it, i) => {
                const li = el("li", it.done ? "is-done" : i === next ? "is-now" : it.auto ? "is-auto" : "");
                li.append(el("span", "as-step-dot", it.done ? "✓" : it.auto ? "✦" : String(i + 1)), el("span", "", it.label));
                if (it.auto) li.title = tr("assist.auto_hint");
                if (i === next) li.setAttribute("aria-current", "step");
                return li;
            }));
            const cur = ol.querySelector(".is-now");
            if (cur) ol.scrollLeft = Math.max(0, ol.scrollLeft + cur.getBoundingClientRect().left - ol.getBoundingClientRect().left - 14);
            // aperçu de ce qui est déjà rédigé
            const dl = asRoot.querySelector("[data-as-preview]");
            const rows = [];
            FIELDS.forEach(([k, label]) => {
                let v = d[k];
                if (k === "meta") v = (Array.isArray(v) ? v : []).map((r) => r.k + " : " + r.v).join("\n");
                else if (k === "tags") v = (Array.isArray(v) ? v : []).map((x) => "#" + x).join(" ");
                v = String(v || "").trim();
                if (!v) return;
                const dd = el("dd", "", v);
                if (k === "source" && /^https?:\/\//i.test(d.source_url || "")) {
                    const a = el("a", "as-src", tr("assist.source_open"));
                    a.href = d.source_url;
                    a.target = "_blank";
                    a.rel = "noopener noreferrer";
                    dd.append(" ", a);
                }
                rows.push(el("dt", "", label), dd);
            });
            if (!rows.length) rows.push(el("dd", "as-preview-empty", tr("assist.preview_empty")));
            dl.replaceChildren(...rows);
        };

        /* conversation */
        const bubble = (m) => {
            const b = el("div", "as-msg is-" + m.role + (m.kind ? " is-" + m.kind : ""));
            if (m.kind === "suggest") b.textContent = "✦ " + m.text;
            else b.textContent = m.text;
            if (m.kind === "source" && /^https?:\/\//i.test(m.url || "")) {
                const a = el("a", "as-src", tr("assist.source_open"));
                a.href = m.url;
                a.target = "_blank";
                a.rel = "noopener noreferrer";
                b.append(" ", a);
            }
            return b;
        };
        const scrollEnd = () => requestAnimationFrame(() => { thread.scrollTop = thread.scrollHeight; });
        const paintThread = () => {
            thread.replaceChildren(...state.msgs.map(bubble));
            scrollEnd();
        };
        const pushMsg = (m) => {
            state.msgs.push(m);
            thread.appendChild(bubble(m));
            scrollEnd();
        };
        const typing = (on) => {
            thread.querySelector(".as-typing")?.remove();
            if (on) {
                const t = el("div", "as-msg is-assistant as-typing typing");
                const dots = el("div", "bubble");
                dots.append(el("i"), el("i"), el("i"));
                t.append(dots);
                t.setAttribute("aria-label", tr("assist.thinking"));
                thread.appendChild(t);
                scrollEnd();
            }
        };
        const paintChips = () => {
            const list = state.choices.map((c) => {
                const b = el("button", "as-chip", c);
                b.type = "button";
                b.addEventListener("click", () => answer(c));
                return b;
            });
            if (!state.ready && !state.paused) {
                const sug = el("button", "as-chip is-suggest", tr("assist.suggest"));
                sug.type = "button";
                sug.addEventListener("click", suggest);
                list.unshift(sug);
            }
            asRoot.querySelector("[data-as-create]").disabled = busy || Boolean(state.pendingCanon);
            if ((state.history.length > 1 || state.ready) && !state.pendingCanon) {
                const now = el("button", "as-chip is-now", isNew ? tr("assist.create_now") : tr("assist.apply_now"));
                now.type = "button";
                now.addEventListener("click", openReview);
                if (state.ready || state.paused) list.unshift(now);
                else list.push(now);
            }
            if (state.paused) {
                const resume = el("button", "as-chip", tr("assist.resume_questions"));
                resume.type = "button";
                resume.addEventListener("click", () => turn("start"));
                list.push(resume);
            }
            chipsEl.replaceChildren(...list);
            chipsEl.scrollLeft = 0;
        };
        const setBusy = (on) => {
            busy = on;
            asRoot.classList.toggle("is-busy", on);
            sendBtn.disabled = on;
            chipsEl.querySelectorAll("button").forEach((b) => (b.disabled = on));
            asRoot.querySelectorAll("[data-as-create], [data-as-restart]").forEach((b) => (b.disabled = on));
            typing(on);
        };

        const post = async (url, data) => {
            const fd = new FormData();
            fd.append("_token", asRoot.dataset.token);
            fd.append("slug", slug);
            Object.entries(data).forEach(([k, v]) => fd.append(k, typeof v === "string" ? v : JSON.stringify(v)));
            const res = await fetch(url, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
            const out = await res.json().catch(() => ({ ok: false, error: tr("common.unreadable") }));
            if (!res.ok || !out.ok) throw new Error(out.error || tr("assist.failed"));
            return out;
        };
        const turn = async (action, text = "") => {
            if (busy) return;
            setBusy(true);
            try {
                const r = await post("/assist/turn", {
                    draft: state.draft, history: state.history, action, answer: text,
                    pending_canon: state.pendingCanon || null,
                    focus: state.topic || "", stalls: String(state.stalls || 0),
                });
                if (action !== "start" || JSON.stringify(state.draft) !== JSON.stringify(r.draft)) {
                    state.review = null;
                    state.reviewProgress = null;
                }
                state.draft = r.draft;
                state.progress = r.progress;
                state.choices = r.choices || [];
                state.ready = r.ready === true;
                state.paused = r.paused === true;
                state.topic = r.topic || "";
                state.conflict = r.conflict || "";
                state.stalls = r.stalls || 0;
                state.pendingCanon = r.pending_canon || null;
                const said = [r.reply, r.question].filter(Boolean).join("\n\n");
                if (said) state.history.push({ role: "assistant", content: said });
                setBusy(false);
                if (r.source && r.source.label) pushMsg({ role: "assistant", text: r.source.label, kind: "source", url: r.source.url || "" });
                if (r.reply) pushMsg({ role: "assistant", text: r.reply, kind: "note" });
                if (r.question) pushMsg({ role: "assistant", text: r.question });
                paintFiche();
                paintChips();
                save();
                if (r.review) await openReview();
            } catch (err) {
                setBusy(false);
                toast({ title: tr("assist.failed"), text: err.message }, true);
                paintChips();
            }
        };
        function answer(text) {
            text = String(text || "").trim();
            if (!text || busy) return;
            state.history.push({ role: "user", content: text });
            pushMsg({ role: "user", text });
            save();
            input.value = "";
            autosize();
            turn("reply", text);
        }
        function suggest() {
            if (busy) return;
            state.history.push({ role: "user", content: tr("assist.suggest_said") });
            pushMsg({ role: "user", text: tr("assist.suggest_said"), kind: "suggest" });
            save();
            turn("suggest");
        }
        const reviewLabels = new Map([
            ...FIELDS.filter(([key]) => key !== "source"),
            ["source", tr("assist.f.source")], ["source_url", tr("assist.f.source_url")],
            ["system_prompt", tr("assist.f.system_prompt")], ["image_prompt", tr("assist.f.image_prompt")],
        ]);
        async function openReview() {
            if (busy || state.pendingCanon) return;
            if (!state.review) {
                busy = true;
                finalEl.hidden = false;
                try {
                    const r = await post("/assist/preview", { draft: state.draft, history: state.history });
                    state.review = r.fields;
                    state.reviewProgress = r.progress;
                    save();
                } catch (err) {
                    toast({ title: tr("assist.failed"), text: err.message }, true);
                    return;
                } finally {
                    busy = false;
                    finalEl.hidden = true;
                }
            }
            const rows = [];
            for (const [key, label] of reviewLabels) {
                if (!Object.hasOwn(state.review, key)) continue;
                const field = el("label", "as-review-field");
                const profileField = ["history", "family", "friends", "work", "goals", "personality"].includes(key);
                const multiline = profileField || ["description", "meta", "system_prompt", "image_prompt"].includes(key);
                const control = el(multiline ? "textarea" : "input", "input");
                if (multiline) control.rows = key.endsWith("_prompt") ? 6 : 3;
                control.name = key;
                control.value = state.review[key];
                control.required = key === "name";
                if (profileField) control.maxLength = 200;
                if (key === "meta") control.placeholder = tr("assist.meta_example");
                field.append(el("span", "", label), control);
                rows.push(field);
            }
            reviewFields.replaceChildren(...rows);
            const coverage = review.querySelector("[data-as-review-progress]");
            coverage.hidden = !state.reviewProgress;
            if (state.reviewProgress) coverage.textContent = tr("assist.review_progress", { percent: state.reviewProgress.percent });
            reviewError.hidden = true;
            review.showModal();
            reviewScroll.scrollTop = 0;
        }
        reviewForm.addEventListener("input", (event) => {
            const { name, value } = event.target;
            if (Object.hasOwn(state.review, name)) {
                state.review[name] = value;
                save();
            }
        });
        reviewBack.addEventListener("click", () => review.close());
        review.addEventListener("cancel", (event) => { if (busy) event.preventDefault(); });
        reviewForm.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (busy) return;
            busy = true;
            reviewFields.disabled = reviewBack.disabled = reviewSave.disabled = true;
            reviewError.hidden = true;
            try {
                const r = await post("/assist/create", { fields: state.review });
                try { sessionStorage.removeItem(KEY); } catch {}
                window.location.href = r.redirect;
            } catch (err) {
                busy = false;
                reviewFields.disabled = reviewBack.disabled = reviewSave.disabled = false;
                reviewError.textContent = err.message;
                reviewError.hidden = false;
            }
        });
        asRoot.querySelector("[data-as-create]")?.addEventListener("click", openReview);
        asRoot.querySelector("[data-as-restart]")?.addEventListener("click", async () => {
            if (busy) return;
            if (state.history.length > 1 && !(await pvModal.confirm(tr("assist.restart_confirm"), { ok: tr("assist.restart_ok") }))) return;
            try { sessionStorage.removeItem(KEY); } catch {}
            window.location.reload();
        });

        // saisie : Entrée envoie, Maj+Entrée va à la ligne ; la zone grandit avec le texte
        const autosize = () => {
            input.style.height = "auto";
            input.style.height = Math.min(input.scrollHeight, 140) + "px";
        };
        input.addEventListener("input", autosize);
        input.addEventListener("keydown", (e) => {
            if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                answer(input.value);
            }
        });
        formEl.addEventListener("submit", (e) => {
            e.preventDefault();
            answer(input.value);
        });

        /* démarrage : reprise de la conversation, sinon premier message */
        const saved = load();
        if (saved && Array.isArray(saved.msgs) && saved.msgs.length) {
            state = Object.assign(state, saved);
            if (!state.progress.missing) {
                const draft = state.draft;
                post("/assist/status", { draft, conflict: state.conflict || "" }).then((r) => {
                    if (state.draft !== draft) return;
                    state.progress = r.progress;
                    state.ready = r.ready && !state.pendingCanon;
                    if (state.ready) state.choices = [];
                    paintFiche();
                    paintChips();
                    save();
                }).catch(() => {});
            }
            paintThread();
            paintChips();
        } else if (isNew) {
            const intro = tr("assist.intro_new");
            state.history.push({ role: "assistant", content: intro });
            state.msgs.push({ role: "assistant", text: intro });
            state.choices = tr("assist.intro_choices").split("|").map((s) => s.trim()).filter(Boolean);
            paintThread();
            paintChips();
            save();
        } else {
            paintChips();
            turn("start");
        }
        paintFiche();
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
                    const v = field && (field.type !== "checkbox" || field.checked) ? field.value : "";
                    return m[2] === "=" ? v === m[3] : v !== m[3];
                });
            });
        };
        document.addEventListener("change", applyConds);
        applyConds();
    }

    const splitPicker = document.querySelector("[data-split-position-picker]");
    document.querySelectorAll("[data-relationship-conflict]").forEach((row) => {
        const kind = row.closest("form").elements.namedItem("kind");
        kind.addEventListener("change", () => { row.hidden = kind.value !== "conflict"; });
    });
    splitPicker?.addEventListener("click", async () => {
        const field = splitPicker.form.elements.namedItem("chat_split_position");
        const position = await pvModal.open({
            title: splitPicker.dataset.title,
            actions: ["before", "after"].map((value) => ({
                label: splitPicker.dataset[value],
                value,
                kind: field.value === value ? "primary" : "ghost",
            })),
        });
        if (!position || field.value === position) return;
        field.value = position;
        splitPicker.textContent = splitPicker.dataset[position];
        field.dispatchEvent(new Event("change", { bubbles: true }));
    });

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
            if (input.dataset.format === "idle") {
                // déchargement après inactivité : tout à gauche = illimité
                const h = Math.floor(v / 60);
                const m = v % 60;
                out.textContent = v <= 0 ? tr("settings.idle_never")
                    : h === 0 ? tr("settings.idle_min", { m })
                    : m === 0 ? tr("settings.idle_h", { h })
                    : tr("settings.idle_hm", { h, m: String(m).padStart(2, "0") });
                return;
            }
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
            // adresses compatibles OpenAI ; noms de modèles indicatifs (à vérifier chez le fournisseur)
            xai: {
                url: "https://api.x.ai/v1",
                model: "grok-4",
                models: ["grok-4", "grok-3", "grok-3-mini"],
            },
            gemini: {
                url: "https://generativelanguage.googleapis.com/v1beta/openai",
                model: "gemini-2.5-flash",
                models: ["gemini-2.5-flash", "gemini-2.5-pro"],
            },
            mistral: {
                url: "https://api.mistral.ai/v1",
                model: "mistral-medium-latest",
                models: ["mistral-medium-latest", "mistral-large-latest", "pixtral-large-latest", "mistral-small-latest"],
            },
            openrouter: {
                url: "https://openrouter.ai/api/v1",
                model: "deepseek/deepseek-chat",
                models: ["deepseek/deepseek-chat", "openai/gpt-4o-mini", "google/gemini-2.5-flash", "x-ai/grok-4", "mistralai/mistral-medium-3"],
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

    /* liste des discussions : appui long (mobile), clic droit ou « ⋯ » → menu d'actions */
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
        const retitleBtn = convSheet.querySelector("[data-act=retitle]");
        const retitleLabel = retitleBtn.querySelector("span");
        const retitleText = retitleLabel.textContent;
        const pendingTitles = new Set();
        const syncRetitle = () => {
            const loading = pendingTitles.has(current?.dataset.convId);
            retitleBtn.disabled = loading;
            retitleBtn.setAttribute("aria-busy", String(loading));
            retitleLabel.textContent = loading ? tr("chat.retitling") : retitleText;
        };
        const openMenu = (item, byTouch = false) => {
            current = item;
            openedAt = Date.now();
            titleEl.textContent = item.dataset.convTitle || "";
            pinLabel.textContent = item.dataset.pinned === "1" ? pinLabel.dataset.labelUnpin : pinLabel.dataset.labelPin;
            syncRetitle();
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

        const retitle = async (item) => {
            const id = item.dataset.convId;
            if (pendingTitles.has(id)) return;
            pendingTitles.add(id);
            syncRetitle();
            try {
                const response = await fetch("/chat/" + id + "/retitle", {
                    method: "POST",
                    body: new URLSearchParams({ _token: convSheet.dataset.token }),
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.ok || !data.title) throw new Error(data.error || tr("chat.retitle_failed"));
                item.dataset.convTitle = data.title;
                item.querySelector("[data-conv-title-text]").textContent = data.title;
                const contactTitle = item.closest("[data-contact]")?.querySelector('[data-contact-title="' + id + '"]');
                if (contactTitle) contactTitle.textContent = data.title;
                if (current === item) {
                    titleEl.textContent = data.title;
                    if (!convSheet.hidden) closeMenu();
                }
                toast(tr("chat.retitled"));
            } catch (error) {
                toast(error.message, true);
            } finally {
                pendingTitles.delete(id);
                syncRetitle();
            }
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
            } else if (btn.dataset.act === "retitle") {
                await retitle(current);
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
        document.querySelectorAll(".modal[data-modal]:not([hidden])").forEach(modalClose);
    });

    const chat = document.getElementById("chat");
    if (chat) {
        const form = chat.querySelector("[data-chat-form]");
        const input = chat.querySelector("[data-chat-input]");
        const scroll = chat.querySelector("[data-scroll]");
        const sendBtn = form.querySelector(".chat-send");
        const avatarHTML = chat.querySelector(".chat-avatar")?.innerHTML || "";

        /* *action* en italique, **texte** en gras : mise en forme à l'affichage seulement.
           Les astérisques restent dans le texte (cachés) : textContent est inchangé, donc la
           copie, la modification, la recherche et les exports voient le message d'origine. */
        const EMPH = /(\*\*)([^*\n]+?)\*\*|(\*)([^*\n]+?)\*/g;
        const emphasize = (b) => {
            if (!b.textContent.includes("*")) return;
            b.normalize();
            [...b.childNodes].forEach((n) => {
                if (n.nodeType !== 3 || !n.data.includes("*")) return;
                const txt = n.data;
                EMPH.lastIndex = 0;
                let m, last = 0;
                const frag = document.createDocumentFragment();
                while ((m = EMPH.exec(txt))) {
                    const mark = m[1] || m[3];
                    const inner = m[2] ?? m[4];
                    if (!inner.trim()) continue;
                    if (m.index > last) frag.append(txt.slice(last, m.index));
                    const w = document.createElement(mark === "**" ? "strong" : "em");
                    w.className = "md-emph";
                    const open = document.createElement("span");
                    open.className = "md-mark";
                    open.textContent = mark;
                    const close = open.cloneNode(true);
                    w.append(open, inner, close);
                    frag.append(w);
                    last = m.index + m[0].length;
                }
                if (!last) return;
                if (last < txt.length) frag.append(txt.slice(last));
                n.replaceWith(frag);
            });
        };
        const emphasizeIn = (root) => root.querySelectorAll?.(".msg-bot .bubble, .msg-user .bubble").forEach(emphasize);
        emphasizeIn(scroll);
        // nouveaux messages, réponses en direct, versions régénérées : remis en forme au fil de l'eau
        let emphRaf = 0;
        new MutationObserver(() => {
            if (emphRaf) return;
            emphRaf = requestAnimationFrame(() => { emphRaf = 0; emphasizeIn(scroll); });
        }).observe(scroll, { childList: true, subtree: true, characterData: true });
        const endpoint = chat.dataset.send;
        const token = chat.dataset.token;
        let busy = false;
        let opening = Boolean(chat.dataset.opening);

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
        const targetMessage = chat.dataset.targetMessage;
        if (targetMessage) {
            const target = scroll.querySelector('[data-msg-id="' + targetMessage + '"]') || scroll.querySelector("[data-source-excerpt]");
            if (target) {
                stick = false;
                requestAnimationFrame(() => target.scrollIntoView({ block: "center" }));
            }
        }

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

        const clearIntro = () => {
            chat.querySelector(".chat-intro")?.remove();
            chat.querySelector("[data-opening-questions]")?.remove();
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
            if (m.id) wrap.dataset.msgId = m.id;
            wrap.dataset.versionId = m.version_id || "0";
            if (m.reaction_reply) wrap.dataset.reactionReply = "1";
            if (m.at) wrap.dataset.at = m.at;
            if (m.role !== "user") {
                const av = document.createElement("span");
                av.className = "msg-avatar";
                av.innerHTML = avatarHTML;
                wrap.appendChild(av);
            }
            let img = null;
            if (m.image) {
                img = document.createElement("img");
                img.className = "bubble-img";
                img.alt = tr("chat.photo_alt");
                img.src = "/media/" + m.image + ".thumb.webp";
                img.dataset.full = "/media/" + m.image + ".webp";
            }

            const content = String(m.content || "");
            const chars = Array.from(content);
            const pts = (Array.isArray(m.split_points) ? m.split_points : [])
                .map(Number)
                .filter((n) => n > 0 && n < chars.length);
            let parts = [content];
            if (pts.length) {
                const bounds = [0, ...pts, chars.length];
                parts = bounds.slice(1).map((end, i) =>
                    // Même ensemble de caractères que trim() côté PHP.
                    chars.slice(bounds[i], end).join("").replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, "")
                ).filter(Boolean);
                if (!parts.length) parts = [content];
            }
            const holder = document.createElement("div");
            holder.className = "bubbles";
            if (img && content !== "") wrap.appendChild(img);
            parts.forEach((part, i) => {
                const item = document.createElement("div");
                item.className = "msg-bubble";
                item.dataset.bubbleIndex = String(i);
                const b = document.createElement("div");
                b.className = "bubble";
                b.textContent = part;
                if (img && content === "") {
                    item.appendChild(img);
                    b.hidden = true;
                }
                item.appendChild(b);
                holder.appendChild(item);
                if (wrap.dataset.msgId && m.role === "assistant" && !b.hidden) prepareReactionBubble(b);
                paintReaction(item, m.bubble_reactions?.[i] ?? (i === 0 ? m.reaction : ""), m.role === "assistant");
            });
            wrap.appendChild(holder);
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
                    const freed = (d.purged || []).length;
                    toast({
                        title: d.override ? tr("chat.model_changed") : tr("chat.model_back"),
                        text: d.model + (d.loading ? " · " + tr("chat.model_loading") : "") + (freed ? " · " + tr("chat.models_unloaded", freed) : ""),
                        code: true,
                    });
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
                s.textContent = f.subject === "story" ? tr("facts.story") + " · " + f.content : f.content;
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
        let relationshipTimer = 0;
        let relationshipDeadline = 0;
        const scheduleRelationship = (windowMs = 0) => {
            if (windowMs) relationshipDeadline = Date.now() + windowMs;
            clearTimeout(relationshipTimer);
            if (Date.now() >= relationshipDeadline) return;
            relationshipTimer = setTimeout(async () => {
                if (Date.now() >= relationshipDeadline) return;
                if (busy) {
                    scheduleRelationship();
                    return;
                }
                const fd = new FormData();
                fd.append("_token", token);
                try {
                    const response = await fetch(chat.dataset.relationship, { method: "POST", body: fd, keepalive: true });
                    const data = await response.json();
                    if (response.ok && data.pending) {
                        clearTimeout(relationshipTimer);
                        relationshipTimer = setTimeout(() => scheduleRelationship(), 3800);
                    }
                } catch {
                    // La prochaine réponse reprendra les échanges encore en attente.
                }
            }, 1200);
        };
        scheduleRelationship(Number(chat.dataset.relationshipWindow) || 0);
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
                const row = document.querySelector('.chat-side-row.is-on [data-side-title="' + chat.dataset.conversationId + '"]');
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
            sendBtn.disabled = opening || (!busy && input.value.trim() === "" && !pendingFile);
        };

        const streamUrl = endpoint.replace(/\/send$/, "/stream");

        const splitOn    = chat.dataset.split === "1";
        const splitWords = Math.max(8, parseInt(chat.dataset.splitWords || "50", 10));
        const splitAfter = chat.dataset.splitPosition === "after";
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
        const resetSteps = () => ["server", "purge", "request", "loading", "ready"].forEach((n) => setStep(n, ""));
        const purgedEl = document.querySelector("[data-lms-purged]");
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

            // 2. libérer la mémoire (le serveur décharge les autres modèles) puis 3. demande de chargement
            if (purgedEl) purgedEl.hidden = true;
            setStep("purge", "active");
            let asked = null;
            try {
                const fd = new FormData();
                fd.append("_token", token);
                const r = await fetch(loadUrl, { method: "POST", body: fd, headers: { "X-Requested-With": "XMLHttpRequest" } });
                asked = await r.json();
            } catch (e) {
                asked = { ok: false, error: tr("lms.request_failed") };
            }
            const purged = (asked && asked.purged) || [];
            const purgeFailed = (asked && asked.purge_failed) || [];
            if (asked && asked.ok) {
                setStep("purge", purgeFailed.length ? "fail" : "done");
                if (purgedEl && (purged.length || purgeFailed.length)) {
                    purgedEl.textContent = purgeFailed.length
                        ? tr("lms.purge_failed", { models: purgeFailed.join(", ") })
                        : tr("lms.purged", purged.length) + " " + purged.join(", ");
                    purgedEl.hidden = false;
                }
            }
            if (!asked || !asked.ok) {
                // modèle introuvable : rien n'a été déchargé (étape laissée neutre)
                setStep("purge", asked && asked.kind === "not_found" ? "" : "fail");
                setStep("request", "fail");
                done(tr("lms.load_failed"));
                showLoadError((asked && asked.error) || tr("lms.unknown_error"), "");
                return;
            }
            setStep("request", "done");
            if (asked.already) {
                setStep("loading", "done");
                setStep("ready", "done");
                if (timerEl) timerEl.textContent = tr("lms.already_loaded_timer");
                done(tr("lms.already_loaded"));
                loadStatus();
                return;
            }

            // 4. chargement en cours -> 5. prêt
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
        let retryFallback = null;

        const askFallback = (text, msg, kind, retry) => {
            lastText = text;
            retryFallback = retry;
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
            retryFallback?.();
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

        const reactions = JSON.parse(chat.dataset.reactions || "{}");
        const reactionUrl = endpoint.replace(/\/send$/, "/react");
        const reactionQueue = new Map();
        const picker = document.createElement("div");
        picker.id = "message-reactions";
        picker.className = "reaction-picker";
        picker.hidden = true;
        picker.setAttribute("role", "group");
        picker.setAttribute("aria-label", tr("chat.reactions"));
        document.body.appendChild(picker);
        let reactionAnchor = null;
        let reactionBubble = null;
        let reactionPoint = null;

        const closeReactions = (restoreFocus = false) => {
            picker.hidden = true;
            reactionAnchor?.setAttribute("aria-expanded", "false");
            if (restoreFocus) reactionAnchor?.focus({ preventScroll: true });
            reactionAnchor = null;
            reactionBubble = null;
            reactionPoint = null;
        };

        const reactionTrigger = (anchor) => {
            anchor.dataset.react = "1";
            anchor.setAttribute("aria-expanded", "false");
            anchor.setAttribute("aria-controls", picker.id);
        };

        const prepareReactionBubble = (bubble) => {
            reactionTrigger(bubble);
            bubble.tabIndex = 0;
            bubble.setAttribute("role", "button");
            bubble.setAttribute("aria-description", tr("chat.react"));
        };

        const paintReaction = (item, key, bot) => {
            item.dataset.reaction = Object.hasOwn(reactions, key) ? key : "";
            const oldChip = item.querySelector(".msg-reaction-chip");
            const focused = oldChip === document.activeElement;
            oldChip?.remove();
            if (!item.dataset.reaction) {
                if (focused) item.querySelector(".bubble")?.focus({ preventScroll: true });
                return;
            }
            const chip = document.createElement(bot ? "button" : "span");
            chip.className = "msg-reaction-chip";
            if (bot) {
                chip.type = "button";
                reactionTrigger(chip);
            } else {
                chip.setAttribute("role", "img");
            }
            const emotion = reactions[key].label;
            const label = bot ? tr("chat.reaction_you", { emotion })
                : tr("chat.reaction_persona", { name: chat.dataset.name, emotion });
            chip.title = label;
            chip.setAttribute("aria-label", label);
            const emoji = document.createElement("span");
            emoji.textContent = reactions[key].emoji;
            emoji.setAttribute("aria-hidden", "true");
            chip.appendChild(emoji);
            if (!bot && avatarHTML) {
                const avatar = document.createElement("span");
                avatar.className = "reaction-avatar";
                avatar.setAttribute("aria-hidden", "true");
                avatar.innerHTML = avatarHTML;
                chip.appendChild(avatar);
            }
            item.appendChild(chip);
            if (focused) chip.focus({ preventScroll: true });
        };
        scroll.querySelectorAll(".msg[data-msg-id] .msg-bubble").forEach((item) => {
            const bot = Boolean(item.closest(".msg-bot"));
            const bubble = item.querySelector(".bubble");
            if (bot && !bubble.hidden) prepareReactionBubble(bubble);
            paintReaction(item, item.dataset.reaction, bot);
        });
        const paintUserReaction = ({ id, reaction }) => {
            const item = scroll.querySelector('.msg-user[data-msg-id="' + Number(id) + '"] [data-bubble-index="0"]');
            if (item) paintReaction(item, reaction, false);
        };

        const positionReactions = () => {
            if (picker.hidden) return;
            const bounds = scroll.getBoundingClientRect();
            const anchor = reactionAnchor.getBoundingClientRect();
            const y = anchor.top + Math.min(reactionPoint.y, anchor.height);
            const visual = window.visualViewport;
            const left = visual?.offsetLeft ?? 0;
            const top = Math.max(bounds.top, visual?.offsetTop ?? 0);
            const right = left + (visual?.width ?? document.documentElement.clientWidth);
            const bottom = Math.min(bounds.bottom, (visual?.offsetTop ?? 0) + (visual?.height ?? window.innerHeight));
            if (!reactionAnchor.isConnected || y < top || y > bottom) {
                closeReactions();
                return;
            }
            picker.style.maxHeight = `${bottom - top}px`;
            const { width, height } = picker.getBoundingClientRect();
            const x = anchor.left + Math.min(reactionPoint.x, anchor.width);
            picker.style.left = Math.max(left + 8, Math.min(x - width / 2, right - width - 8)) + "px";
            const offset = y - top >= height + 8 || y - top >= bottom - y ? y - height - 8 : y + 8;
            picker.style.top = Math.max(top, Math.min(offset, bottom - height)) + "px";
        };

        const showReactions = (expanded = false) => {
            const entries = Object.entries(reactions);
            picker.replaceChildren();
            picker.classList.toggle("is-expanded", expanded);
            for (const [key, { emoji, label }] of expanded ? entries : entries.slice(0, 6)) {
                const option = document.createElement("button");
                option.type = "button";
                option.textContent = emoji;
                option.dataset.reactionOption = key;
                option.title = label;
                option.setAttribute("aria-label", option.title);
                option.setAttribute("aria-pressed", String(reactionBubble.dataset.reaction === key));
                picker.appendChild(option);
            }
            if (!expanded) {
                const more = toolBtn("more-reactions", '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>', tr("chat.more_reactions"));
                picker.appendChild(more);
            }
            const msg = reactionBubble.closest(".msg-bot");
            const actions = document.createElement("div");
            actions.className = "reaction-actions";
            if (lastReply() === msg && scroll.querySelector(".msg-user[data-msg-id]")) {
                actions.appendChild(toolBtn("regen", ICONS.regen, tr("chat.regen")));
            }
            actions.appendChild(toolBtn("del", ICONS.del, tr("chat.delete")));
            picker.appendChild(actions);
            picker.hidden = false;
            positionReactions();
            (picker.querySelector('[aria-pressed="true"]') || picker.firstElementChild)?.focus({ preventScroll: true });
        };

        const saveReaction = async (item, key) => {
            const msg = item.closest(".msg-bot");
            if (!msg || item.dataset.reacting || msg.dataset.switching) return;
            item.dataset.reacting = "1";
            const mid = msg.dataset.msgId;
            const version = msg.dataset.versionId || "0";
            const bubble = item.dataset.bubbleIndex;
            const queueKey = `${mid}:${version}:${bubble}`;
            const wanted = item.dataset.reaction === key ? "" : key;
            try {
                const body = new URLSearchParams({ _token: token, mid, version_id: version, bubble, reaction: wanted });
                const response = await fetch(reactionUrl, { method: "POST", body, headers: { "X-Requested-With": "XMLHttpRequest" } });
                const data = await response.json();
                if (!data.ok) throw new Error(data.error || tr("chat.reaction_error"));
                paintReaction(item, data.reaction, true);
                if (!data.reaction) reactionQueue.delete(queueKey);
                if (data.reply) {
                    reactionQueue.set(queueKey, { mid, version, bubble, key: data.reaction, revision: data.revision });
                    sendQueuedReaction();
                }
            } catch (err) {
                notice(err.message || tr("chat.reaction_error"));
            } finally {
                delete item.dataset.reacting;
            }
        };

        picker.addEventListener("click", (event) => {
            event.stopPropagation();
            if (!reactionBubble) return;
            const option = event.target.closest("[data-reaction-option]");
            const action = event.target.closest("[data-regen], [data-del]");
            if (option) {
                const item = reactionBubble;
                closeReactions(true);
                saveReaction(item, option.dataset.reactionOption);
            } else if (event.target.closest("[data-more-reactions]")) {
                showReactions(true);
            } else if (action) {
                const msg = reactionBubble.closest(".msg-bot");
                closeReactions(true);
                if (!msg?.isConnected) return;
                if (action.hasAttribute("data-regen")) regenerate(msg);
                else removeMsg(msg.dataset.msgId);
            }
        });
        document.addEventListener("click", (event) => {
            if (!event.target.closest("[data-react]")) closeReactions();
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && !picker.hidden) closeReactions(true);
        });
        scroll.addEventListener("scroll", positionReactions, { passive: true });
        window.addEventListener("resize", positionReactions);
        window.visualViewport?.addEventListener("resize", positionReactions);

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

        const messageTime = (msg) => {
            const when = fmtMsgTime(msg.dataset.at);
            if (!when) return null;
            const time = document.createElement("time");
            time.className = "msg-time";
            time.dateTime = msg.dataset.at;
            time.textContent = when;
            return time;
        };

        const showReplyTime = (msg) => {
            const bubbles = msg.querySelector(".bubbles");
            if (!bubbles || bubbles.querySelector(".msg-time")) return;
            scroll.querySelectorAll(".msg-tools, .bubbles > .msg-time").forEach((el) => el.remove());
            const time = messageTime(msg);
            if (time) bubbles.appendChild(time);
        };

        const buildUserTools = (msg) => {
            const tools = document.createElement("div");
            tools.className = "msg-tools";
            const time = messageTime(msg);
            if (time) tools.appendChild(time);
            tools.append(toolBtn("edit", ICONS.edit, tr("chat.edit")), toolBtn("del", ICONS.del, tr("chat.delete")));
            return tools;
        };

        /* idées de premier message : remplit la zone de saisie (sans envoyer) */
        const starters = chat.querySelector("[data-starter-packs]");
        const starterButtons = starters?.querySelectorAll("[data-starter]");
        scroll.addEventListener("click", (event) => {
            const button = event.target.closest("[data-starter]");
            if (!button) return;
            input.value = button.textContent;
            input.dispatchEvent(new Event("input", { bubbles: true }));
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        });
        if (starters) {
            const packs = JSON.parse(starters.dataset.starterPacks);
            let currentPack = 0;
            chat.querySelector("[data-starters-more]").addEventListener("click", () => {
                currentPack = (currentPack + 1) % packs.length;
                starterButtons.forEach((button, i) => { button.textContent = packs[currentPack][i]; });
            });
        }

        /* ‹ 1/3 › sous la dernière réponse : passer d'une version régénérée à l'autre */
        const versionUrl = endpoint.replace(/\/send$/, "/version");
        const lastReply = () => {
            const message = [...scroll.querySelectorAll(".msg[data-msg-id]:not([data-reaction-reply])")].at(-1);
            return message?.classList.contains("msg-bot") ? message : null;
        };
        const paintVersions = () => {
            scroll.querySelectorAll(".msg-versions").forEach((n) => n.remove());
            const last = lastReply();
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
            if (busy || el.dataset.switching === "1" || el.querySelector("[data-reacting]")) return;
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
                    version_id: d.version_id,
                    reaction: d.reaction,
                    bubble_reactions: d.bubble_reactions,
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
            const block = el.querySelector(".bubbles");
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
                    image: d.image || "", // la photo jointe reste affichée
                    at: el.dataset.at,    // et la date du message aussi
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

        const regenerate = (msg) => {
            if (busy || msg !== lastReply() || msg.querySelector(".bubble")?.textContent === "") return;
            scroll.querySelectorAll(".msg-tools").forEach((t) => t.remove());
            send(null, "auto", false, true, null, Number(msg.dataset.msgId));
        };

        scroll.addEventListener("keydown", (event) => {
            if (event.target.matches(".bubble[data-react]") && ["Enter", " "].includes(event.key)) {
                event.preventDefault();
                if (!event.repeat) event.target.click();
            }
        });

        scroll.addEventListener("click", (e) => {
            const react = e.target.closest("[data-react]");
            if (react) {
                const msg = react.closest(".msg-bot");
                const item = react.closest(".msg-bubble");
                if (!msg || item.dataset.reacting || msg.dataset.switching || (e.detail && window.getSelection()?.isCollapsed === false)) return;
                if (reactionAnchor === react) {
                    closeReactions(true);
                } else {
                    closeReactions();
                    const anchor = react.getBoundingClientRect();
                    reactionPoint = e.detail ? { x: e.clientX - anchor.left, y: e.clientY - anchor.top }
                        : { x: anchor.width / 2, y: Math.max(0, scroll.getBoundingClientRect().top - anchor.top) };
                    reactionAnchor = react;
                    reactionBubble = item;
                    react.setAttribute("aria-expanded", "true");
                    showReplyTime(msg);
                    showReactions();
                }
                return;
            }
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

            const msg = e.target.closest(".msg-user[data-msg-id]");
            if (!msg || msg.dataset.editing === "1") return;

            if (msg.querySelector(".msg-tools")) {
                msg.querySelector(".msg-tools").remove();
                return;
            }
            scroll.querySelectorAll(".msg-tools, .bubbles > .msg-time").forEach((el) => el.remove());
            const tools = buildUserTools(msg);
            msg.appendChild(tools);
            setTimeout(() => tools.scrollIntoView({ block: "nearest", behavior: "smooth" }), 40);
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

        const sendQueuedReaction = () => {
            if (busy || !reactionQueue.size) return;
            const [key, reaction] = reactionQueue.entries().next().value;
            reactionQueue.delete(key);
            send(null, "auto", false, false, null, null, reaction);
        };

        const send = async (overrideText = null, mode = "auto", echo = true, regen = false, regenMid = null, regenReply = null, reaction = null) => {
            const text = regen || reaction ? "" : (overrideText ?? input.value.trim());
            if (!regen && !reaction && text === "" && !pendingFile) return;
            if (busy || opening) return;

            const tryFallback = () => {
                if (busy) return;
                if (!regen && !reaction && input.value.trim() === text) {
                    input.value = "";
                    grow();
                }
                send(text, "fallback", echo, regen, regenMid, regenReply, reaction);
            };
            busy = true;
            syncSend();
            setStreaming(true);
            if (overrideText === null && !regen && !reaction) {
                input.value = "";
                grow();
            }

            let userEl = null;
            const restoreDraft = () => {
                userEl?.remove();
                if (!regen && !reaction && !input.value) {
                    input.value = text;
                    grow();
                }
            };
            if (!regen && !reaction) {
                clearIntro();
                if (echo) {
                    userEl = renderMsg({ role: "user", content: text, at: new Date().toISOString() });
                    scroll.appendChild(userEl);
                }
                scrollBottom(true, true);
            }
            typing();

            if (!reaction && mode === "auto" && lastStatus && lastStatus.reachable === false) {
                document.getElementById("chat-typing")?.remove();
                setStreaming(false);
                restoreDraft();
                askFallback(text, tr("chat.lms_no_answer", { url: lastStatus.url || tr("chat.configured_address") }), "down", tryFallback);
                busy = false;
                syncSend();
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
            let userId = 0;
            let afterId = 0;

            const clearLive = () => {
                clearTimeout(splitTimer);
                splitTimer = null;
                [live, ...bubbleEls].forEach((b) => b?.closest(".msg")?.remove());
            };

            /* fin de réponse : les bulles affichées pendant l'arrivée du texte (un bloc par bulle)
               sont remplacées par UN message, comme après un rechargement. Sinon seule la première
               bulle portait l'identifiant : supprimer, régénérer ou voir la date ne marchait pas
               sur les autres, et elles restaient à l'écran après suppression du message. */
            const settleLive = (reply) => {
                clearLive();
                if (regen) {
                    scroll.querySelectorAll(".msg-bot[data-msg-id]:not([data-reaction-reply])").forEach((el) => {
                        const id = Number(el.dataset.msgId);
                        if (id > userId && id <= afterId) el.remove();
                    });
                }
                const fresh = renderMsg({
                    ...reply,
                    role: "assistant",
                    at: reply.at || new Date().toISOString(),
                });
                if (reply.versions) {
                    fresh.dataset.ver = String(reply.versions.index);
                    fresh.dataset.vers = String(reply.versions.total);
                }
                scroll.appendChild(fresh);
                paintVersions();
            };

            // Après une coupure, retrouver la réponse de ce tour par ses identifiants.
            const recoverReply = async () => {
                const url = endpoint.replace(/\/send$/, "/messages") + "?limit=10&generation=1";
                for (let i = 0; i < 12; i++) {
                    const d = await fetch(url, { signal: AbortSignal.timeout(1000) }).then((r) => r.json()).catch(() => null);
                    if (d?.ok && !d.generating) {
                        for (const msg of d.messages) {
                            if (reaction) {
                                if (msg.id > afterId && msg.reaction_reply) return msg;
                                continue;
                            }
                            if (msg.id === userId && msg.reaction) paintUserReaction(msg);
                            if (msg.id <= userId) continue;
                            if (msg.role === "user") break;
                            if (msg.role === "assistant" && !msg.reaction_reply && msg.id > afterId) return msg;
                        }
                        return null;
                    }
                    await new Promise((resolve) => setTimeout(resolve, 400));
                }
                return null;
            };

            const countWords = (s) => (s.trim() === "" ? 0 : s.trim().split(/\s+/).length);

            // Cherche la dernière fin de phrase avant le seuil ou la première après.
            const cutInSegment = (seg) => {
                const words = [];
                const reW = /\S+/g;
                let wm;
                while ((wm = reW.exec(seg))) words.push(wm.index);
                if (words.length <= splitWords) return 0;

                const ends = [];
                const reEnd = /[.!?…]+(?=\s|$)|\r\n|[\n\r\v\f\x85\u2028\u2029]/g;
                let mm;
                while ((mm = reEnd.exec(seg))) {
                    const at = mm.index + mm[0].length;
                    if (seg.slice(at).trim() !== "") ends.push(at); // pas de coupe en toute fin de texte
                }
                if (!ends.length) return 0;

                const bounds = ends.map((at) => ({ at, n: words.filter((w) => w < at).length }));

                const before = bounds.findLast((b) => b.n <= splitWords && b.n >= 6);
                const after = bounds.find((b) => b.n > splitWords);
                const chosen = splitAfter ? after : (before || after);
                // Attendre la suite quand une coupure très courte peut encore être évitée.
                if (!chosen) return 0;

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
                if (regen && regenReply) fd.append("regen_reply", String(regenReply));
                if (reaction) {
                    fd.append("reaction_mid", reaction.mid);
                    fd.append("reaction_version", reaction.version);
                    fd.append("reaction_bubble", reaction.bubble);
                    fd.append("reaction_revision", reaction.revision);
                    fd.append("reaction", reaction.key);
                }
                const attachment = regen || reaction ? null : pendingFile;
                if (attachment) {
                    const file = (await pendingShrink?.catch(() => null)) || attachment;
                    fd.append("image", file, file.name || "photo.jpg");
                }

                const request = {
                    method: "POST",
                    body: fd,
                    headers: { "Accept": "text/event-stream", "X-Requested-With": "XMLHttpRequest" },
                    signal: controller.signal,
                };
                let res = await fetch(streamUrl, request);
                // Un autre onglet peut encore avoir le verrou de cette discussion.
                for (let attempt = 0; reaction && res.status === 409 && attempt < 8; attempt++) {
                    await res.body?.cancel();
                    await new Promise((resolve) => setTimeout(resolve, 750));
                    res = await fetch(streamUrl, request);
                }

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
                    let finalReply = null;
                    let finalNotice = null;
                    let failed = "";
                    let failedKind = "error";
                    let keptUser = false;
                    let skipped = false;

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
                            } else if (evt.phase === "loading") {
                                if (!live) loadingLabel(tr("lms.loading"));
                            } else if (evt.phase === "memory") {
                                if (!live) loadingLabel(tr("chat.memory_phase"));
                            } else if (evt.start) {
                                userId = Number(evt.user?.id || evt.user_id || 0);
                                afterId = Number(evt.after_id || userId);
                                if (attachment && attachment === pendingFile) clearAttach();
                                if (evt.user && userEl) {
                                    const fresh = renderMsg({ ...evt.user, at: userEl.dataset.at });
                                    userEl.replaceWith(fresh);
                                    userEl = fresh;
                                }
                            } else if (evt.done) {
                                finalReply = evt.reply || null;
                                if (evt.relationship_pending) scheduleRelationship(300000);
                                finalNotice = evt.notice || null;
                                skipped = evt.skipped === true;
                                if (evt.user_reaction) paintUserReaction(evt.user_reaction);
                            } else if (evt.error) {
                                failed = evt.error;
                                failedKind = evt.kind || "error";
                                keptUser = evt.kept_user === true;
                            }
                        }
                    }

                    document.getElementById("chat-typing")?.remove();
                    if (skipped) {
                        clearLive();
                        return;
                    }
                    if (failed) {
                        clearLive();
                        if (!keptUser) restoreDraft();
                        if (mode === "auto" && !streamed && !keptUser && ["down", "not_loaded", "no_model", "error"].includes(failedKind)) {
                            askFallback(text, failed, failedKind, tryFallback);
                        } else {
                            notice(tr("chat.interrupted", { message: failed }));
                        }
                        scrollBottom();
                        return;
                    }
                    if (!finalReply) {
                        throw new Error(tr("chat.empty_reply"));
                    }
                    settleLive(finalReply);
                    if (finalNotice) notice(finalNotice.content);
                }

                scrollBottom();
                if (!reaction) {
                    compressMemory()
                        .then((d) => {
                            if (d && d.compressed > 0) {
                                notice(tr("chat.memory_updated", d.compressed));
                                scrollBottom();
                            }
                        })
                        .then(() => scheduleFacts())
                        .then(() => autoTitle());
                }
            } catch (err) {
                document.getElementById("chat-typing")?.remove();
                clearInterval(probe);
                clearTimeout(splitTimer);
                const reply = userId || afterId ? await recoverReply() : null;
                if (reply) settleLive(reply);
                else clearLive();
                if (!userId) restoreDraft();
                notice(err.name === "AbortError" ? tr("chat.aborted") : tr("common.error", { message: err.message }));
                scrollBottom();
            } finally {
                [live, ...bubbleEls].filter(Boolean).forEach((b) => {
                    const w = b.closest(".msg");
                    if (w && w.isConnected && b.textContent.trim() === "" && ![...w.querySelectorAll(".bubble")].some((x) => x.textContent.trim() !== "")) w.remove();
                });
                controller = null;
                clearInterval(probe);
                loadingLabel("");
                loadStatus();
                busy = false;
                setStreaming(false);
                syncSend();
                // sur téléphone, redonner le focus ouvrirait le clavier à chaque réponse
                if (!reaction && !matchMedia("(pointer: coarse)").matches) input.focus({ preventScroll: true });
                sendQueuedReaction();
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

        if (opening) {
            const loadOpening = async () => {
                const questions = scroll.querySelector("[data-opening-questions]");
                const intro = scroll.querySelector(".chat-intro");
                try {
                    const fd = new FormData();
                    fd.append("_token", token);
                    let response = await fetch(chat.dataset.opening, { method: "POST", body: fd });
                    // Un autre onglet peut être en train d'ouvrir le même fil.
                    for (let attempt = 0; response.status === 409 && attempt < 15; attempt++) {
                        await new Promise((resolve) => setTimeout(resolve, 1000));
                        response = await fetch(chat.dataset.opening, { method: "POST", body: fd });
                    }
                    const data = await response.json();
                    if (!response.ok || !data.ok) return;
                    const messages = data.messages || (data.message ? [data.message] : []);
                    if (messages.length) {
                        intro?.remove();
                        for (const message of messages) {
                            scroll.insertBefore(renderMsg(message), questions);
                        }
                    }
                    for (const question of data.questions || []) {
                        const button = document.createElement("button");
                        button.type = "button";
                        button.className = "chip";
                        button.dataset.starter = "";
                        button.textContent = question;
                        questions.append(button);
                    }
                    questions.hidden = !questions.children.length;
                    scrollBottom(false);
                } catch {
                    // Le modèle peut être indisponible, le fil reste utilisable.
                } finally {
                    intro?.querySelector("[data-opening-status]")?.setAttribute("hidden", "");
                    intro?.querySelector("[data-opening-fallback]")?.removeAttribute("hidden");
                    if (questions.hidden) {
                        starters?.removeAttribute("hidden");
                        intro?.querySelector("[data-starters-more]")?.removeAttribute("hidden");
                    }
                    opening = false;
                    syncSend();
                }
            };
            loadOpening();
        }
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
