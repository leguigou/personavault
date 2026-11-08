<?php
/*
 * Textos mostrados por public/assets/app.js (función tr(), clave sin el prefijo «js.»).
 * Las claves js.modal.*, js.unsaved.* y js.chat.jump están en common.php.
 */
return [
    // común
    'js.common.error'         => 'Error: {message}',
    'js.common.unreadable'    => 'Respuesta ilegible.',
    'js.common.send_failed'   => 'Error al enviar.',
    'js.common.delete_failed' => 'no se pudo eliminar',
    'js.common.impossible'    => 'no se pudo',

    // consigna de juego propuesta por la IA
    'js.directive.generating' => 'Generando…',
    'js.directive.unreadable' => 'respuesta ilegible',
    'js.directive.failed'     => 'error al generar',
    'js.directive.done'       => 'Consigna propuesta: revísala, ajústala y guárdala.',

    // copiar un prompt
    'js.copy.copied' => 'Copiado',
    'js.copy.toast'  => 'Prompt copiado',

    // búsqueda
    'js.search.free' => 'Buscar «{q}»',

    // ficha del persona
    'js.persona.preview_alt'     => 'Vista previa',
    'js.persona.ai_fill_busy'    => 'Analizando los prompts…',
    'js.persona.ai_fill_failed'  => 'Error al completar.',
    'js.persona.ai_fill_nothing' => 'Nada que completar.',
    'js.persona.ai_fill_done'    => 'Ficha completada.',
    'js.persona.photo_adding'    => 'Añadiendo la imagen…',
    'js.persona.photo_added'     => 'Imagen añadida.',

    // ajustes: resúmenes de las secciones plegables (partes separadas por «·»)
    'js.settings.sum_split_off'      => 'Desactivado',
    'js.settings.sum_split'          => '{max} burbujas máx. · {words} palabras',
    'js.settings.sum_facts_off'      => 'Desactivados',
    'js.settings.sum_facts_explicit' => 'A petición',
    'js.settings.sum_facts_every'    => 'Cada {n} mensajes',
    'js.settings.sum_facts_each'     => 'Después de cada intercambio',
    'js.settings.sum_facts_idle'     => 'en calma',
    'js.settings.sum_facts_remote'   => 'remoto',
    'js.settings.sum_facts_local'    => 'local',
    'js.settings.sum_titles_off'     => 'Desactivados',
    'js.settings.sum_titles_local'   => 'Modelo local',
    'js.settings.sum_titles_remote'  => 'Modelo remoto',
    'js.settings.sum_followup'       => 'De {from} h a {to} h',
    'js.settings.sum_followup_off'   => 'Desactivados',

    // ajustes: prueba del modelo, purga de LM Studio
    'js.settings.test_running'    => 'Probando…',
    'js.settings.test_connecting' => 'Conectando con el modelo…',
    'js.settings.test_failed'     => 'La prueba ha fallado.',
    'js.settings.test_ok'         => 'Conectado · {model} · {ms} ms',
    'js.settings.purging'         => 'Purgando…',
    'js.settings.purge_failed'    => 'Error al purgar.',
    'js.settings.purge_unloaded'  => ['one' => '{n} modelo descargado: {list}', 'other' => '{n} modelos descargados: {list}'],
    'js.settings.purge_none'      => 'Ningún modelo cargado en memoria: nada que purgar.',
    'js.settings.purge_done'      => 'Memoria purgada ({n})',
    'js.settings.purge_nothing'   => 'Nada que purgar',

    // chat
    'js.chat.load_failed'         => 'no se pudo cargar',
    'js.chat.photo_alt'           => 'Foto enviada',
    'js.chat.percent'             => '{n} %',
    'js.chat.ctx_percent'         => '{n} % del contexto',
    'js.chat.ctx_tokens'          => '{used} / {window} tokens',
    'js.chat.foot'                => [
        'one'   => '{model} · contexto {used} / {window} · {n} compresión',
        'other' => '{model} · contexto {used} / {window} · {n} compresiones',
    ],
    'js.chat.compressing'         => 'Comprimiendo…',
    'js.chat.compressed'          => ['one' => '{n} mensaje resumido', 'other' => '{n} mensajes resumidos'],
    'js.chat.nothing_to_compress' => 'Nada que resumir',
    'js.chat.compress_failed'     => 'No se pudo comprimir',
    'js.chat.memory_updated'      => [
        'one'   => 'Memoria actualizada: {n} mensaje resumido.',
        'other' => 'Memoria actualizada: {n} mensajes resumidos.',
    ],
    'js.chat.memory_phase'        => 'Actualizando la memoria…',
    'js.chat.fb_not_loaded'       => 'El modelo no está cargado',
    'js.chat.fb_no_reply'         => 'LM Studio no ha respondido',
    'js.chat.fb_kept'             => 'Mensaje guardado en el campo: vuelve a enviarlo cuando LM Studio esté listo.',
    'js.chat.lms_no_answer'       => 'LM Studio no responde en {url}.',
    'js.chat.configured_address'  => 'la dirección configurada',
    'js.chat.regen'               => 'Regenerar la respuesta',
    'js.chat.edit'                => 'Editar este mensaje',
    'js.chat.delete'              => 'Eliminar este mensaje',
    'js.chat.save'                => 'Guardar',
    'js.chat.erase_error'         => 'No se pudo eliminar: {message}',
    'js.chat.empty_message'       => 'El mensaje no puede estar vacío.',
    'js.chat.edit_failed'         => 'no se pudo editar',
    'js.chat.edited_replay'       => 'Mensaje editado, nueva respuesta…',
    'js.chat.edited'              => 'Mensaje editado.',
    'js.chat.edit_error'          => 'No se pudo editar: {message}',
    'js.chat.stop_label'          => 'Detener la generación',
    'js.chat.stop'                => 'Detener',
    'js.chat.send'                => 'Enviar',
    'js.chat.choose_image'        => 'Elige una imagen (jpg, png, webp).',
    'js.chat.interrupted'         => 'Respuesta interrumpida: {message}',
    'js.chat.empty_reply'         => 'Respuesta vacía.',
    'js.chat.aborted'             => 'Generación interrumpida.',

    // estado de LM Studio y carga del modelo
    'js.lms.down'                 => 'LM Studio no está abierto',
    'js.lms.down_url'             => 'LM Studio no está abierto ({url})',
    'js.lms.down_no_answer'       => 'LM Studio no está abierto: ninguna respuesta en {url}',
    'js.lms.loaded'               => 'Modelo cargado: {model}',
    'js.lms.loaded_ctx'           => 'Modelo cargado: {model} · {n} tokens',
    'js.lms.not_loaded_title'     => '{model} no está cargado (se carga con el primer mensaje)',
    'js.lms.not_loaded'           => '{model} no está cargado: se cargará con el primer mensaje',
    'js.lms.loading'              => 'Cargando el modelo…',
    'js.lms.seconds'              => '{n} s',
    'js.lms.load_now'             => 'Cargar el modelo ahora',
    'js.lms.unreachable'          => 'LM Studio inaccesible',
    'js.lms.already_loaded_timer' => 'ya cargado',
    'js.lms.already_loaded'       => 'Ya cargado',
    'js.lms.request_failed'       => 'No se pudo enviar la solicitud.',
    'js.lms.load_failed'          => 'Error de carga',
    'js.lms.load_error'           => 'No se pudo cargar: {message}',
    'js.lms.unknown_error'        => 'error desconocido',
    'js.lms.loaded_short'         => 'Modelo cargado',
    'js.lms.too_long'             => 'La carga tarda demasiado',

    // recuerdos compartidos y búsqueda en las otras conversaciones
    'js.facts.empty'      => 'Todavía no hay recuerdos.',
    'js.facts.delete'     => 'Eliminar este recuerdo',
    'js.facts.add_failed' => 'no se pudo añadir',
    'js.facts.added'      => 'Recuerdo añadido: compartido con todas las conversaciones de este persona.',
    'js.facts.added_one'  => 'Recuerdo añadido: {fact}',
    'js.facts.enabled'    => 'Recuerdos activados para esta conversación.',
    'js.facts.disabled'   => 'Recuerdos desactivados: siguen en memoria para las otras conversaciones.',
    'js.recall.memorize'  => 'Memorizar',
    'js.recall.memorized' => 'Añadido a los recuerdos compartidos.',
    'js.recall.searching' => 'Buscando…',
    'js.recall.none'      => 'No se ha encontrado nada en las otras conversaciones.',
    'js.recall.failed'    => 'No se pudo buscar.',
    // partagés avec les vues (affichage initial côté serveur, mise à jour côté JS)
    'js.chat.load_older'               => ['one' => 'Cargar {n} mensaje anterior', 'other' => 'Cargar {n} mensajes anteriores'],
    'js.chat.ctx_auto_summary'   => [
        'one'   => 'Resumen automático a partir de {threshold} tokens sin resumir · {n} hecho',
        'other' => 'Resumen automático a partir de {threshold} tokens sin resumir · {n} hechos',
    ],
    // date et heure affichées sous un message touché ({date} : « Aujourd’hui », « 8 nov. »…)
    'js.chat.msg_time' => '{date}, {time}',
];
