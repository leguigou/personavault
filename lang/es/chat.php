<?php
/*
 * Español — conversaciones: lista (views/chat_list.php) y conversación (views/chat.php).
 * Mismas claves que lang/fr/chat.php (referencia). Se tutea al usuario, como en francés.
 */
return [
    'chat.gif_spontaneous' => 'GIF espontáneos',
    'chat.gif_alt' => 'GIF animado elegido por el personaje',
    'chat.reasoning_inherit' => 'Usar el ajuste del personaje',
    'chat.reasoning_global' => 'Usar el ajuste global',
    'chat.list.retitle' => 'Reescribir el título',
    'chat.emoji.like' => 'De acuerdo',
    'chat.emoji.heart' => 'Afecto',
    'chat.emoji.laugh' => 'Me hace reír',
    'chat.emoji.hug' => 'Un abrazo',
    'chat.emoji.dislike' => 'En desacuerdo',
    'chat.emoji.angry' => 'Enfado',
    'chat.emoji.tender' => 'Ternura',
    'chat.emoji.kiss' => 'Un beso',
    'chat.emoji.love' => 'Enamorado o admirado',
    'chat.emoji.celebrate' => 'Para celebrar',
    'chat.emoji.smile' => 'Sonrisa',
    'chat.emoji.surprise' => 'Sorpresa',
    'chat.emoji.sad' => 'Tristeza',

    // --- común a ambas pantallas
    'chat.unread'                   => 'Mensaje nuevo',

    // --- lista de conversaciones
    'chat.list.title'               => 'Mis conversaciones',
    'chat.list.status_local'        => 'Chat local · {model}',
    'chat.list.model_auto'          => 'modelo automático',
    'chat.list.no_model'            => 'Ningún modelo de chat disponible',
    'chat.list.empty_title'         => 'Ninguna conversación',
    'chat.list.empty_text'          => 'Elige un persona y empieza a hablarle.',
    'chat.list.no_message'          => 'Todavía no hay mensajes',
    'chat.list.pinned' => 'Fijadas',
    'chat.list.recent' => 'Recientes',
    'chat.list.photo' => '📷 Foto',
    'chat.list.you' => 'Tú:',
    'chat.list.new_badge' => 'Nuevo',
    'chat.list.pin' => 'Fijar arriba',
    'chat.list.unpin' => 'Quitar de fijadas',
    'chat.list.message_count' => ['one' => '{n} msj', 'other' => '{n} msjs'],
    'chat.list.thread_count' => ['one' => '{n} conversación', 'other' => '{n} conversaciones'],
    'chat.list.actions' => 'Acciones de la conversación',
    'chat.list.more' => 'Más acciones',
    'chat.list.delete' => 'Eliminar la conversación',
    'chat.list.delete_confirm' => '¿Eliminar «{title}»? Los mensajes se borrarán definitivamente (los recuerdos del persona se conservan).',
    'chat.list.long_press_hint' => 'Consejo: mantén pulsada una conversación para fijarla o eliminarla.',

    // --- conversación: columna lateral y cabecera
    'chat.side.all'                 => 'Todas',
    'chat.head.back'                => 'Todas las conversaciones',
    'chat.head.open_persona'        => 'Abrir la ficha del personaje',
    'chat.head.model_state'         => 'Estado del modelo',
    'chat.head.model_default'       => 'modelo predeterminado',
    'chat.head.context_memory'      => 'Contexto y memoria',
    'chat.head.settings'            => 'Ajustes de la conversación',

    // --- conversación: mensajes y redacción
    'chat.intro.first_message'      => 'Puedes escribir un mensaje.',
    'chat.intro.preparing'          => '{name} está preparando su primer mensaje…',
    'chat.head.new'                => 'Nueva conversación con este persona',
    'chat.intro.you'               => 'tú',
    'chat.facts.story'             => 'Historia del personaje',
    'chat.intro.starters_label' => 'Ideas para empezar',
    'chat.intro.starters_more' => 'Más ideas',
    'chat.intro.starters' => '¡Hola, {name}! ¿Qué planes tienes hoy?|¿Qué te pone de buen humor últimamente?|Si me llevaras a tu lugar favorito, ¿adónde iríamos?'
        . '|¡Hola, {name}! ¿Qué te hizo sonreír hoy?|¿Hay algún pequeño ritual que nunca te saltas?|¿Qué te gustaría que supiera de ti?'
        . '|¡Hola, {name}! ¿Tienes alguna anécdota que contarme?|¿Cómo sería un día ideal para ti?|¿Qué te apasiona tanto que pierdes la noción del tiempo?'
        . '|¡Hola, {name}! ¿En qué pensabas antes de que llegara?|Si tuviéramos una hora libre, ¿qué propondrías?|¿Hay algo que siempre hayas querido probar?',
    'chat.msg.photo_sent'           => 'Foto enviada',
    'chat.composer.attach_preview'  => 'Vista previa de la foto adjunta',
    'chat.composer.attach_remove'   => 'Quitar la foto',
    'chat.composer.attach'          => 'Adjuntar una foto',
    'chat.composer.placeholder'     => 'Escribe un mensaje…',
    'chat.composer.label'           => 'Mensaje',
    'chat.composer.send'            => 'Enviar',
    'chat.foot.no_model'            => 'Ningún modelo de chat disponible: revisa LM Studio en los ajustes.',

    // --- panel «contexto y ajustes»
    'chat.sheet.label'              => 'Contexto y ajustes de la conversación',
    'chat.sheet.eyebrow'            => 'Conversación',
    'chat.sheet.ctx_percent'        => '{percent} % del contexto',
    'chat.sheet.ctx_tokens'         => '{used} / {window} tokens',
    'chat.sheet.save'               => 'Guardar',

    // --- estado del servidor local y carga del modelo
    'chat.lms.checking'             => 'Comprobando el servidor…',
    'chat.lms.refresh'              => 'Actualizar el estado',
    'chat.lms.step_server'          => 'Servidor',
    'chat.lms.step_purge' => 'Memoria',
    'chat.lms.step_request'         => 'Petición',
    'chat.lms.step_loading'         => 'Carga',
    'chat.lms.step_ready'           => 'Listo',
    'chat.lms.timer_zero'           => '0,0 s',

    // --- ajustes del personaje (resumen)
    'chat.persona.title'            => 'Ajustes del personaje',
    'chat.persona.edit'             => 'Modificar',
    'chat.persona.model_global'     => 'modelo global',
    'chat.model.label' => 'Modelo de esta conversación',
    'chat.model.persona_default' => 'El del persona · {model}',
    'chat.model.reset' => 'Volver al persona',
    'chat.model.vision_legend' => '👁 = también lee imágenes (se pueden adjuntar fotos).',
    'chat.lms.error_reset' => 'Volver al modelo del persona',
    'chat.lms.error_detail' => 'Mensaje de LM Studio:',
    'chat.model.hint_off' => 'Para probar otro modelo solo aquí: el persona conserva el suyo.',
    'chat.model.hint_on' => 'Modelo de prueba, solo para esta conversación. El persona conserva el suyo.',
    'chat.model.test_badge' => 'Modelo elegido solo para esta conversación',
    'chat.persona.temperature'      => 'temperatura {value}',
    'chat.persona.max_tokens'       => '{count} tokens',
    'chat.persona.window'           => 'ventana {size}',
    'chat.persona.directive'        => '«{text}»',

    // --- opciones de la conversación
    'chat.toggles.memory'           => 'Conservar la memoria (resumen continuo)',
    'chat.toggles.pinned' => 'Fijar arriba de la lista',
    'chat.toggles.followup_settings' => 'Ajustar el plazo y el horario de los mensajes',
    'chat.toggles.followup_global_off' => 'Los mensajes espontáneos están desactivados en Ajustes: este interruptor no tiene efecto por ahora.',
    'chat.toggles.followup_enable' => 'Activarlos',
    'chat.toggles.followup_no_cron' => 'El programador de mensajes espontáneos nunca se ha ejecutado en este servidor: no se enviará ningún mensaje espontáneo.',
    'chat.toggles.followup_how' => 'Cómo activarlo',
    'chat.toggles.followup'         => 'Mensajes espontáneos tras un largo silencio',

    // --- memoria (resumen continuo)
    'chat.memory.title'             => 'Memoria',
    'chat.memory.percent'           => '{percent} %',
    'chat.memory.stat_messages'     => 'mensajes',
    'chat.memory.stat_unsummarized' => 'sin resumir',
    'chat.memory.stat_compressions' => 'compresiones',
    'chat.memory.stat_tokens'       => 'tokens',
    'chat.memory.summary_label'     => 'Resumen actual',
    'chat.memory.summary_placeholder' => 'Vacío por ahora: se rellena automáticamente cuando la conversación se alarga.',
    'chat.memory.clear'             => 'Borrar',
    'chat.memory.compress'          => 'Comprimir',
    'chat.memory.manage'            => 'Gestionar los recuerdos duraderos',

    // --- recuerdos compartidos
    'chat.facts.title'              => 'Recuerdos compartidos',
    'chat.facts.manage_all'         => 'Gestionar todo',
    'chat.facts.hint'               => 'Válidos para todas las conversaciones con {name}.',
    'chat.facts.use'                => 'Usar estos recuerdos en esta conversación',
    'chat.facts.delete'             => 'Eliminar este recuerdo',
    'chat.facts.empty'              => 'Todavía no hay recuerdos.',
    'chat.facts.add_placeholder'    => 'Añadir un recuerdo…',
    'chat.facts.add_label'          => 'Nuevo recuerdo',
    'chat.facts.add'                => 'Añadir',

    // --- búsqueda en las conversaciones del persona
    'chat.recall.title'             => 'Buscar en sus recuerdos',
    'chat.recall.hint'              => 'En todas las conversaciones con {name}.',
    'chat.recall.placeholder'       => 'P. ej. Le Pradet, su trabajo…',
    'chat.recall.label'             => 'Buscar',
    'chat.recall.submit'            => 'Buscar',

    // --- renombrar / eliminar
    'chat.rename.label'             => 'Título de la conversación',
    'chat.rename.submit'            => 'Renombrar',
    'chat.delete.confirm'           => '¿Eliminar esta conversación y todos sus mensajes?',
    'chat.delete.submit'            => 'Eliminar la conversación',

    // --- ventana para pasar al modelo remoto (#fb-modal)
    'chat.fallback.title'           => 'LM Studio no responde',
    'chat.fallback.hint'            => 'Puedes esperar a que vuelva (el mensaje se reenviará tal cual) o hacer que DeepSeek genere la respuesta.',
    'chat.fallback.wait'            => 'Esperar y reintentar',
    'chat.fallback.use'             => 'Usar DeepSeek',
    // consigne propre à la conversation
    'chat.directive.label'       => 'Instrucción para esta conversación',
    'chat.directive.placeholder' => 'Ej. Estamos de vacaciones en Lisboa y ella me enseña la ciudad.',
    'chat.directive.help'        => 'Se añade a la instrucción del personaje, solo en esta conversación.',
    'chat.directive.save'        => 'Guardar la instrucción',
];
