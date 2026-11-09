<?php
/** @var array $conv @var array $persona @var array $messages @var array $convs @var array $models @var bool $chatReady @var string $chatModel */
$hue   = hue_for((string) $persona['name']);
$token = csrf_token();
$model = (string) ($conv['model'] !== '' ? $conv['model'] : $chatModel);
$fmt   = fn (float $n): string => fmt_number($n);
// « . "\n" » après un texte en fin de ligne : garde le saut de ligne qu’avalerait la balise PHP fermante.
?>
<div class="chat-shell">
    <aside class="chat-side">
        <div class="chat-side-head">
            <span><?= e(t('nav.chats')) ?></span>
            <a class="mini" href="/chat"><?= e(t('chat.side.all')) ?></a>
        </div>
        <?php foreach ($convs as $c): ?>
            <a class="chat-side-row <?= (int) $c['id'] === (int) $conv['id'] ? 'is-on' : '' ?>" href="/chat/<?= (int) $c['id'] ?>">
                <span class="chat-side-mono"><?= e(initials((string) $c['persona_name'])) ?></span>
                <span class="chat-side-name">
                    <?php if ((int) ($c['unread'] ?? 0) === 1): ?><span class="unread-dot" aria-label="<?= e(t('chat.unread')) ?>"></span><?php endif; ?>
                    <?= e((string) $c['title']) ?>
                </span>
            </a>
        <?php endforeach; ?>
        <a class="chat-side-row chat-side-new" href="/chat">
            <span class="chat-side-mono">+</span>
            <span class="chat-side-name"><?= e(t('chat.side.new')) ?></span>
        </a>
    </aside>

    <section class="chat-main" id="chat" data-chat
             data-send="/chat/<?= (int) $conv['id'] ?>/send"
             data-compress="/chat/<?= (int) $conv['id'] ?>/compress"
             data-remember="/chat/<?= (int) $conv['id'] ?>/remember"
             <?php $__facts = facts_settings(); ?>
             data-facts-timing="<?= e($__facts['frequency'] === 'off' ? 'off' : $__facts['timing']) ?>"
             data-facts-idle="<?= (int) $__facts['idle'] ?>"
             data-status="/chat/<?= (int) $conv['id'] ?>/status"
             data-token="<?= e($token) ?>"
             data-auto-title="<?= (int) ($conv['title_auto'] ?? 1) === 1 && chat_title_mode() !== 'off' ? 1 : 0 ?>"
             data-split="<?= (string) setting('chat_split_enabled', '1') === '1' ? '1' : '0' ?>"
             data-split-words="<?= (int) setting('chat_split_words', '50') ?>"
             data-split-max="<?= (int) setting('chat_split_max_bubbles', '2') ?>"
             data-split-delay-min="<?= e((string) setting('chat_split_delay_min', '1')) ?>"
             data-split-delay-max="<?= e((string) setting('chat_split_delay_max', '2')) ?>"
             data-name="<?= e((string) $persona['name']) ?>"
             data-model="<?= e($model) ?>"
             style="--hue: <?= $hue ?>">

        <header class="chat-head">
            <a class="icon-btn" href="/chat" aria-label="<?= e(t('chat.head.back')) ?>">
                <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <a class="chat-id" href="/p/<?= e((string) $persona['slug']) ?>" title="<?= e(t('chat.head.open_persona')) ?>">
                <div class="chat-avatar">
                    <?php if ($persona['avatar']): ?>
                        <img src="<?= e(media_url((string) $persona['avatar'])) ?>" alt="">
                    <?php else: ?>
                        <div class="avatar-fallback"><span><?= e(initials((string) $persona['name'])) ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="chat-id-text">
                    <strong><?= e((string) $persona['name']) ?></strong>
                    <span class="chat-model">
                        <i class="model-dot" data-model-dot data-state="idle" title="<?= e(t('chat.head.model_state')) ?>"></i>
                        <span data-model-name><?= e($model !== '' ? $model : t('chat.head.model_default')) ?></span>
                    </span>
                </div>
            </a>
            <button class="ctx-ring-btn" type="button" data-open-settings="memory" aria-expanded="false"
                    aria-controls="chat-settings" aria-label="<?= e(t('chat.head.context_memory')) ?>">
                <span data-ctx-ring><?= chat_ring($memory, 32) ?></span>
            </button>
            <button type="button" class="icon-btn" data-open-settings="conversation" aria-expanded="false"
                    aria-controls="chat-settings" title="<?= e(t('chat.head.settings')) ?>" aria-label="<?= e(t('chat.head.settings')) ?>">
                <svg viewBox="0 0 24 24" class="ico"><path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M19.4 13.5a7.6 7.6 0 0 0 0-3l1.7-1.2-1.8-3.1-2 .8a7.6 7.6 0 0 0-2.6-1.5L14.4 3h-3.6l-.3 2.5a7.6 7.6 0 0 0-2.6 1.5l-2-.8L4 9.3l1.7 1.2a7.6 7.6 0 0 0 0 3L4 14.7l1.8 3.1 2-.8a7.6 7.6 0 0 0 2.6 1.5l.3 2.5h3.6l.3-2.5a7.6 7.6 0 0 0 2.6-1.5l2 .8 1.8-3.1-1.6-1.2Z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
            </button>
        </header>

        <div class="chat-scroll" data-scroll>
            <?php if (!empty($olderLeft)): ?>
                <div class="chat-older">
                    <button type="button" class="btn btn-ghost btn-sm" data-load-older
                            data-before="<?= (int) $messages[0]['id'] ?>"
                            data-url="/chat/<?= (int) $conv['id'] ?>/messages">
                        <?= e(t('js.chat.load_older', (int) $olderLeft)) ?>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!$messages): ?>
                <div class="chat-intro">
                    <div class="chat-avatar chat-avatar-lg">
                        <?php if ($persona['avatar']): ?>
                            <img src="<?= e(media_url((string) $persona['avatar'])) ?>" alt="">
                        <?php else: ?>
                            <div class="avatar-fallback"><span><?= e(initials((string) $persona['name'])) ?></span></div>
                        <?php endif; ?>
                    </div>
                    <h2><?= e((string) $persona['name']) ?></h2>
                    <?php if ($persona['tagline']): ?><p><?= e((string) $persona['tagline']) ?></p><?php endif; ?>
                    <span class="hint"><?= e(t('chat.intro.first_message')) ?></span>
                </div>
            <?php endif; ?>

            <?php foreach ($messages as $m): ?>
                <?php $role = (string) $m['role']; ?>
                <?php if ($role === 'memory'): ?>
                    <div class="msg-memory" data-msg-id="<?= (int) $m['id'] ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a4 4 0 0 0-4 4 3 3 0 0 0 0 6 3.5 3.5 0 0 0 4 4V5Zm0 0a4 4 0 0 1 4 4 3 3 0 0 1 0 6 3.5 3.5 0 0 1-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                        <span><?= e((string) $m['content']) ?></span>
                    </div>
                <?php elseif ($role === 'notice'): ?>
                    <div class="msg-notice" data-msg-id="<?= (int) $m['id'] ?>"><?= e((string) $m['content']) ?></div>
                <?php else: ?>
                    <div class="msg msg-<?= $role === 'user' ? 'user' : 'bot' ?>" data-msg-id="<?= (int) $m['id'] ?>" data-at="<?= e(gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $m['created_at'] . ' UTC'))) ?>">
                        <?php if ($role !== 'user'): ?>
                            <span class="msg-avatar">
                                <?php if ($persona['avatar']): ?>
                                    <img src="<?= e(media_url((string) $persona['avatar'])) ?>" alt="">
                                <?php else: ?>
                                    <span class="avatar-fallback"><span><?= e(initials((string) $persona['name'])) ?></span></span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <?php if ((string) ($m['image'] ?? '') !== ''): ?>
                            <img class="bubble-img" src="<?= e(media_url((string) $m['image'], true)) ?>"
                                 alt="<?= e(t('chat.msg.photo_sent')) ?>" data-full="<?= e(media_url((string) $m['image'])) ?>">
                        <?php endif; ?>
                        <?php
                        $__mc   = (string) $m['content'];
                        $__len  = mb_strlen($__mc);
                        $__pts  = array_values(array_filter(
                            array_map('intval', json_list($m['split_points'] ?? '[]')),
                            static fn (int $x): bool => $x > 0 && $x < $__len
                        ));
                        if (!$__pts && (int) ($m['split_at'] ?? 0) > 0 && (int) $m['split_at'] < $__len) {
                            $__pts = [(int) $m['split_at']];
                        }
                        ?>
                        <?php if ($__pts): ?>
                            <div class="bubbles">
                                <?php $__prev = 0; ?>
                                <?php foreach (array_merge($__pts, [$__len]) as $__cut): ?>
                                    <div class="bubble"><?= nl2br(e(trim(mb_substr($__mc, $__prev, $__cut - $__prev)))) ?></div>
                                    <?php $__prev = $__cut; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="bubble"><?= nl2br(e($__mc)) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="attach-preview" data-attach-preview hidden>
            <img alt="<?= e(t('chat.composer.attach_preview')) ?>">
            <button type="button" data-attach-remove aria-label="<?= e(t('chat.composer.attach_remove')) ?>">&times;</button>
        </div>

        <form class="chat-composer" data-chat-form>
            <div class="chat-field">
                <button class="chat-attach" type="button" data-attach hidden aria-label="<?= e(t('chat.composer.attach')) ?>" title="<?= e(t('chat.composer.attach')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.6 12.4 15 6a3.4 3.4 0 1 1 4.8 4.8l-7.6 7.6a5.4 5.4 0 0 1-7.6-7.6l7.2-7.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <input type="file" accept="image/*" hidden data-attach-input>
                <textarea class="chat-input" data-chat-input rows="1" placeholder="<?= e(t('chat.composer.placeholder')) ?>" aria-label="<?= e(t('chat.composer.label')) ?>"></textarea>
            </div>
            <button class="chat-send" type="submit" aria-label="<?= e(t('chat.composer.send')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M12.5 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </form>

        <p class="chat-foot <?= $chatReady ? '' : 'is-ko' ?>" data-chat-foot>
            <?php if ($chatReady): ?>
                <?= e($model) ?> · <?= e(t('chat.foot.context', ['used' => $fmt((float) $memory['context_tokens']), 'window' => $fmt((float) $memory['window'])])) ?>
                · <?= e(t('chat.foot.compressions', (int) $memory['compressions'])) ?>
            <?php else: ?>
                <?= e(t('chat.foot.no_model')) . "\n" ?>
            <?php endif; ?>
        </p>
    </section>
</div>

<!-- panneau : l'anneau montre « Contexte et mémoire », la roue « Conversation » (sections data-sheet-group) -->
<aside class="chat-sheet" id="chat-settings" aria-hidden="true" aria-label="<?= e(t('chat.sheet.label')) ?>">
    <header class="sheet-head">
        <div>
            <p class="eyebrow" data-sheet-eyebrow><?= e(t('chat.sheet.eyebrow')) ?></p>
            <h2><?= e((string) $conv['title']) ?></h2>
        </div>
        <button type="button" class="icon-btn" data-close-settings aria-label="<?= e(t('common.close')) ?>">&times;</button>
    </header>

    <div class="sheet-body">
        <!-- contexte -->
        <section data-sheet-group="memory" class="sheet-ctx">
            <span data-ctx-ring-big><?= chat_ring($memory, 62) ?></span>
            <div class="sheet-ctx-text">
                <strong><?= e(t('chat.sheet.ctx_percent', ['percent' => (int) $memory['percent']])) ?></strong>
                <span><?= e(t('chat.sheet.ctx_tokens', ['used' => $fmt((float) $memory['context_tokens']), 'window' => $fmt((float) $memory['window'])])) ?></span>
                <span data-threshold="<?= e($fmt((float) chat_memory_threshold())) ?>"><?= e(t('js.chat.ctx_auto_summary', ['n' => (int) $memory['compressions'], 'threshold' => $fmt((float) chat_memory_threshold())])) ?></span>
            </div>
        </section>

        <section data-sheet-group="conversation" class="lms-status" data-lms-status data-state="loading">
            <span class="lms-dot"></span>
            <span class="lms-text"><?= e(t('chat.lms.checking')) ?></span>
            <button type="button" class="lms-refresh" data-lms-refresh aria-label="<?= e(t('chat.lms.refresh')) ?>">&#8635;</button>
        </section>

        <div data-sheet-group="conversation"><!-- chargement du modèle : bouton et progression (masquée par le JS) -->
        <div class="lms-actions">
            <button type="button" class="btn btn-ghost btn-sm" data-load-model>
                <svg viewBox="0 0 24 24" class="ico"><path d="M12 4v10m0 0 4-4m-4 4-4-4M5 18h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span data-load-label><?= e(t('js.lms.load_now')) ?></span>
            </button>
        </div>

        <div class="lms-progress" data-lms-progress hidden>
            <ol class="lms-steps" data-lms-steps>
                <li data-step="server"><span class="n">1</span><span><?= e(t('chat.lms.step_server')) ?></span></li>
                <li data-step="request"><span class="n">2</span><span><?= e(t('chat.lms.step_request')) ?></span></li>
                <li data-step="loading"><span class="n">3</span><span><?= e(t('chat.lms.step_loading')) ?></span></li>
                <li data-step="ready"><span class="n">4</span><span><?= e(t('chat.lms.step_ready')) ?></span></li>
            </ol>
            <p class="lms-timer" data-lms-timer><?= e(t('chat.lms.timer_zero')) ?></p>
        </div>
        </div>

        <!-- réglages du personnage : communs à toutes ses conversations -->
        <section data-sheet-group="conversation" class="persona-summary">
            <div class="mem-head">
                <h3><?= e(t('chat.persona.title')) ?></h3>
                <a class="mini" href="/p/<?= e((string) $persona['slug']) ?>/edit#ia"><?= e(t('chat.persona.edit')) ?></a>
            </div>
            <p class="perso-line">
                <b><?= e($model !== '' ? $model : t('chat.persona.model_global')) ?></b>
                <span><?= e(t('chat.persona.temperature', ['value' => fmt_number((float) $conv['temperature'], 2, false)])) ?></span>
                <span><?= e(t('chat.persona.max_tokens', ['count' => (int) $conv['max_tokens']])) ?></span>
                <span><?= e(t('chat.persona.window', ['size' => fmt_number((int) $conv['context_window'])])) ?></span>
            </p>
            <?php if (trim((string) $conv['directive']) !== ''): ?>
                <p class="hint perso-directive"><?= e(t('chat.persona.directive', ['text' => mb_substr(trim((string) $conv['directive']), 0, 160)])) ?></p>
            <?php endif; ?>
        </section>

        <!-- options propres à cette conversation -->
        <form method="post" action="/chat/<?= (int) $conv['id'] ?>/toggles" class="sheet-form" data-sheet-group="conversation">
            <input type="hidden" name="_token" value="<?= e($token) ?>">

            <label class="check">
                <input type="checkbox" name="memory_enabled" value="1" <?= (int) $conv['memory_enabled'] ? 'checked' : '' ?>>
                <span><?= e(t('chat.toggles.memory')) ?></span>
            </label>

            <label class="check">
                <input type="checkbox" name="auto_followup" value="1" <?= (int) ($conv['auto_followup'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span><?= e(t('chat.toggles.followup')) ?></span>
            </label>
            <a class="mini-link" href="/settings#followup"><?= e(t('chat.toggles.followup_settings')) ?></a>

            <div class="form-actions">
                <button class="btn btn-primary btn-sm" type="submit"><?= e(t('chat.sheet.save')) ?></button>
            </div>
        </form>

        <!-- mémoire -->
        <section data-sheet-group="memory" class="mem-box">
            <div class="mem-head">
                <h3><?= e(t('chat.memory.title')) ?></h3>
                <span class="mem-pct <?= $memory['percent'] > 80 ? 'is-hot' : '' ?>"><?= e(t('chat.memory.percent', ['percent' => (int) $memory['percent']])) ?></span>
            </div>

            <div class="mem-stats">
                <div><strong><?= (int) $memory['messages'] ?></strong><span><?= e(t('chat.memory.stat_messages')) ?></span></div>
                <div><strong><?= (int) $memory['unsummarized'] ?></strong><span><?= e(t('chat.memory.stat_unsummarized')) ?></span></div>
                <div><strong><?= (int) $memory['compressions'] ?></strong><span><?= e(t('chat.memory.stat_compressions')) ?></span></div>
                <div><strong><?= $fmt((float) $memory['context_tokens']) ?></strong><span><?= e(t('chat.memory.stat_tokens')) ?></span></div>
            </div>

            <form method="post" action="/chat/<?= (int) $conv['id'] ?>/memory" class="mem-form">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <label class="field">
                    <span><?= e(t('chat.memory.summary_label')) ?></span>
                    <textarea class="input" name="summary" rows="5" placeholder="<?= e(t('chat.memory.summary_placeholder')) ?>"><?= e((string) $conv['summary']) ?></textarea>
                </label>
                <div class="form-actions">
                    <button class="btn btn-ghost btn-sm" type="submit" name="op" value="clear" formnovalidate><?= e(t('chat.memory.clear')) ?></button>
                    <button class="btn btn-ghost btn-sm" type="button" data-compress-now><?= e(t('chat.memory.compress')) ?></button>
                    <button class="btn btn-primary btn-sm" type="submit" name="op" value="save"><?= e(t('chat.sheet.save')) ?></button>
                </div>
            </form>

            <a class="mem-link" href="/memories">
                <?= e(t('chat.memory.manage')) . "\n" ?>
                <svg viewBox="0 0 24 24" class="ico"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </section>

        <!-- souvenirs partagés avec toutes les conversations du persona -->
        <section data-sheet-group="memory" class="fact-box" data-fact-box
                 data-add="/chat/<?= (int) $conv['id'] ?>/fact-add"
                 data-del="/chat/<?= (int) $conv['id'] ?>/fact-delete"
                 data-facts-url="/chat/<?= (int) $conv['id'] ?>/facts-toggle"
                 data-token="<?= e($token) ?>">
            <div class="mem-head">
                <h3><?= e(t('chat.facts.title')) ?></h3>
                <a class="mini" href="/memories"><?= e(t('chat.facts.manage_all')) ?></a>
            </div>
            <p class="fact-hint"><?= e(t('chat.facts.hint', ['name' => (string) $persona['name']])) ?></p>

            <label class="check">
                <input type="checkbox" data-facts-toggle value="1" <?= (int) ($conv['facts_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span><?= e(t('chat.facts.use')) ?></span>
            </label>

            <ul class="fact-list" data-fact-list>
                <?php foreach (($personaMemories ?? []) as $fm): ?>
                    <li data-fid="<?= (int) $fm['id'] ?>">
                        <span class="fact-txt"><?= e((string) $fm['content']) ?></span>
                        <button type="button" class="fact-del" data-fact-del="<?= (int) $fm['id'] ?>" aria-label="<?= e(t('chat.facts.delete')) ?>">&times;</button>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($personaMemories)): ?>
                    <li class="fact-empty"><?= e(t('chat.facts.empty')) ?></li>
                <?php endif; ?>
            </ul>

            <form class="fact-add" data-fact-form>
                <input class="input" type="text" name="content" maxlength="200" placeholder="<?= e(t('chat.facts.add_placeholder')) ?>" aria-label="<?= e(t('chat.facts.add_label')) ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('chat.facts.add')) ?></button>
            </form>
        </section>

        <!-- recherche dans toutes les conversations du persona -->
        <section data-sheet-group="memory" class="recall-box" data-recall-box data-url="/chat/<?= (int) $conv['id'] ?>/search">
            <div class="mem-head">
                <h3><?= e(t('chat.recall.title')) ?></h3>
            </div>
            <p class="fact-hint"><?= e(t('chat.recall.hint', ['name' => (string) $persona['name']])) ?></p>

            <form class="fact-add" data-recall-form>
                <input class="input" type="search" name="q" placeholder="<?= e(t('chat.recall.placeholder')) ?>" aria-label="<?= e(t('chat.recall.label')) ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('chat.recall.submit')) ?></button>
            </form>

            <ul class="fact-list recall-list" data-recall-list></ul>
        </section>

        <!-- exporter toute la conversation (texte, Markdown, page imprimable → PDF) -->
        <section data-sheet-group="conversation" class="recall-box export-box">
            <div class="mem-head">
                <h3><?= e(t('export.sheet.title')) ?></h3>
            </div>
            <p class="fact-hint"><?= e(t('export.sheet.hint')) ?></p>
            <div class="form-actions">
                <a class="btn btn-ghost btn-sm" href="/chat/<?= (int) $conv['id'] ?>/export.txt" download data-export="txt"><?= e(t('export.sheet.txt')) ?></a>
                <a class="btn btn-ghost btn-sm" href="/chat/<?= (int) $conv['id'] ?>/export.md" download data-export="md"><?= e(t('export.sheet.md')) ?></a>
                <a class="btn btn-ghost btn-sm" href="/chat/<?= (int) $conv['id'] ?>/print?auto=1" data-export="pdf"
                   title="<?= e(t('export.sheet.pdf_title')) ?>"><?= e(t('export.sheet.pdf')) ?></a>
            </div>
        </section>

        <!-- renommer / supprimer -->
        <form method="post" action="/chat/<?= (int) $conv['id'] ?>/rename" class="rename-row" data-sheet-group="conversation">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <input class="input" type="text" name="title" value="<?= e((string) $conv['title']) ?>" aria-label="<?= e(t('chat.rename.label')) ?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('chat.rename.submit')) ?></button>
        </form>

        <form method="post" action="/chat/<?= (int) $conv['id'] ?>/delete" data-confirm="<?= e(t('chat.delete.confirm')) ?>" class="chat-danger" data-sheet-group="conversation">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <button class="btn btn-danger-ghost btn-block" type="submit">
                <svg viewBox="0 0 24 24" class="ico"><path d="M6 7h12M9 7V5h6v2m-8 0 1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                <?= e(t('chat.delete.submit')) . "\n" ?>
            </button>
        </form>
    </div>
</aside>

<div class="modal" id="fb-modal" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="fb-title">
        <header class="modal-head">
            <h2 id="fb-title"><?= e(t('chat.fallback.title')) ?></h2>
            <button type="button" class="icon-btn" data-fb-wait aria-label="<?= e(t('common.close')) ?>">&times;</button>
        </header>
        <p class="fb-msg" data-fb-msg></p>
        <p class="fb-hint"><?= e(t('chat.fallback.hint')) ?></p>
        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-fb-wait><?= e(t('chat.fallback.wait')) ?></button>
            <button type="button" class="btn btn-primary" data-fb-use><?= e(t('chat.fallback.use')) ?></button>
        </div>
    </div>
</div>
