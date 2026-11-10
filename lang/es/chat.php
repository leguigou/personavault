<?php
/*
 * Español — conversaciones: lista (views/chat_list.php) y conversación (views/chat.php).
 * Mismas claves que lang/fr/chat.php (referencia). Se tutea al usuario, como en francés.
 */
return [
    // --- común a ambas pantallas
    'chat.unread'                   => 'Mensaje nuevo',

    // --- lista de conversaciones
    'chat.list.title'               => 'Mis conversaciones',
    'chat.list.new'                 => 'Nueva',
    'chat.list.status_local'        => 'Chat local · {model}',
    'chat.list.model_auto'          => 'modelo automático',
    'chat.list.no_model'            => 'Ningún modelo de chat disponible',
    'chat.list.empty_title'         => 'Ninguna conversación',
    'chat.list.empty_text'          => 'Elige un persona y empieza a hablarle.',
    'chat.list.pick_persona'        => 'Elegir un persona',
    'chat.list.no_message'          => 'Todavía no hay mensajes',
    'chat.list.pinned' => 'Fijadas',
    'chat.list.recent' => 'Recientes',
    'chat.list.photo' => '📷 Foto',
    'chat.list.you' => 'Tú:',
    'chat.list.new_badge' => 'Nuevo',
    'chat.list.pin' => 'Fijar arriba',
    'chat.list.unpin' => 'Quitar de fijadas',
    'chat.list.message_count' => ['one' => '{n} msj', 'other' => '{n} msjs'],
    'chat.list.actions' => 'Acciones de la conversación',
    'chat.list.more' => 'Más acciones',
    'chat.list.delete' => 'Eliminar la conversación',
    'chat.list.delete_confirm' => '¿Eliminar «{title}»? Los mensajes se borrarán definitivamente (los recuerdos del persona se conservan).',
    'chat.list.long_press_hint' => 'Consejo: mantén pulsada una conversación para fijarla o eliminarla.',
    'chat.list.search_persona'      => 'Buscar un persona…',
    'chat.list.no_persona'          => 'No hay ningún persona en la biblioteca. Crea una ficha primero.',

    // --- conversación: columna lateral y cabecera
    'chat.side.all'                 => 'Todas',
    'chat.side.new'                 => 'Nueva conversación',
    'chat.head.back'                => 'Todas las conversaciones',
    'chat.head.open_persona'        => 'Abrir la ficha del personaje',
    'chat.head.model_state'         => 'Estado del modelo',
    'chat.head.model_default'       => 'modelo predeterminado',
    'chat.head.context_memory'      => 'Contexto y memoria',
    'chat.head.settings'            => 'Ajustes de la conversación',

    // --- conversación: mensajes y redacción
    'chat.intro.first_message'      => 'Escribe el primer mensaje.',
    'chat.intro.starters_label' => 'Ideas para empezar',
    'chat.intro.starter_1' => '¡Hola! ¿Qué tal?',
    'chat.intro.starter_2' => 'Cuéntame tu día.',
    'chat.intro.starter_3' => 'Háblame un poco de ti.',
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
    'chat.model.hint_off' => 'Para probar otro modelo solo aquí: el persona conserva el suyo.',
    'chat.model.hint_on' => 'Modelo de prueba, solo para esta conversación. El persona conserva el suyo.',
    'chat.model.test_badge' => 'prueba',
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
