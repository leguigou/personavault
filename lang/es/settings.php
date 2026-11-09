<?php
/*
 * Español — página Ajustes (views/settings.php). Mismas claves que lang/fr/settings.php.
 * Tuteo (« tú »), como en francés.
 */
return [
    // --- cabecera y pestañas
    'settings.subtitle'         => 'Conexiones, conversaciones y apariencia — una pestaña por tema.',
    'settings.tabs_label'       => 'Secciones de los ajustes',
    'settings.tab.ia_long'      => 'Inteligencia',
    'settings.tab.ia_short'     => 'IA',
    'settings.tab.chat_long'    => 'Conversación',
    'settings.tab.chat_short'   => 'Chat',
    'settings.tab.look'         => 'Apariencia',
    'settings.tab.prompts'      => 'Prompts',
    'settings.tab.backup'       => 'Copia de seguridad',

    // --- comunes a varias pestañas
    'settings.info_more'        => 'Más información: {label}',
    'settings.dirty'            => 'Cambios sin guardar',
    'settings.save'             => 'Guardar',
    'settings.field.provider'   => 'Proveedor',
    'settings.field.base_url'   => 'URL base',
    'settings.field.model'      => 'Modelo',
    'settings.field.model_used' => 'Modelo utilizado',
    'settings.clear_key'        => 'Eliminar la clave guardada',
    'settings.model.remote'     => 'Modelo de las fichas (remoto)',
    'settings.model.chat'       => 'Modelo de conversación (local)',
    'settings.model.remote_not_ready' => 'El modelo de las fichas no está configurado (pestaña IA): mientras tanto se usa el modelo local.',
    'settings.provider.lmstudio' => 'LM Studio (local)',
    'settings.provider.custom'  => 'Otro / compatible con OpenAI',
    'settings.provider.other'   => 'Otro',

    // --- pestaña IA: creación de fichas
    'settings.ia.title'         => 'Creación de fichas',
    'settings.ia.subtitle'      => 'Modelo que rellena las fichas de persona (botón IA).',
    'settings.ia.model_examples' => 'Ej.: deepseek-chat, deepseek-reasoner',
    'settings.ia.api_key'       => 'Clave API',
    'settings.ia.api_key_keep'  => 'Déjalo vacío para conservar la clave',
    'settings.ia.api_key_saved_help' => 'Ya hay una clave guardada. Escribe una nueva para sustituirla.',
    'settings.ia.api_key_help'  => 'Se guarda en la base local de la app y nunca se muestra en claro.',
    'settings.ia.status_ready'  => 'Listo · {model}',
    'settings.ia.status_no_key' => 'Ninguna clave guardada',
    'settings.ia.test'          => 'Probar la conexión',
    'settings.ia.how_title'     => 'Cómo funciona',
    'settings.ia.how_text_html' => 'En una ficha, el botón <strong>Completar con la IA</strong> lee los prompts de la persona y
            rellena solo lo que falta: frase gancho, descripción, etiquetas y atributos.
            Lo que ya está escrito nunca se sobrescribe, y <strong>tus prompts nunca se modifican</strong>:
            la IA solo los lee.',
    'settings.ia.how_privacy'   => 'Nada se envía sin que hagas clic: la app solo envía los datos al modelo en el momento de esta acción.',

    // --- pestaña Conversación
    'settings.chat.title'       => 'Modelo de chat',
    'settings.chat.subtitle'    => 'Se usa solo para hablar con las personas. Si LM Studio está apagado, la app cambia a DeepSeek y te avisa.',
    'settings.chat.connection_title' => 'Conexión al modelo',
    'settings.chat.model_aria'  => 'Modelo de conversación',
    'settings.chat.models_detected' => [
        'one'   => '{n} modelo detectado · ★ = recomendados para conversar.',
        'other' => '{n} modelos detectados · ★ = recomendados para conversar.',
    ],
    'settings.chat.model_manual' => 'Servidor inaccesible: escribe el nombre del modelo a mano.',
    'settings.chat.api_key'     => 'Clave API (opcional)',
    'settings.chat.api_key_saved_placeholder' => 'Clave guardada — déjalo vacío',
    'settings.chat.api_key_placeholder' => 'vacío para LM Studio',
    'settings.chat.memory_title' => 'Razonamiento y memoria',
    'settings.chat.reasoning'   => 'Razonamiento del modelo',
    'settings.chat.reasoning_none'   => 'Desactivado (recomendado)',
    'settings.chat.reasoning_low'    => 'Bajo',
    'settings.chat.reasoning_medium' => 'Medio',
    'settings.chat.reasoning_high'   => 'Alto',
    'settings.chat.reasoning_help' => 'Los modelos Qwen3 / Gemma 4 «razonan» antes de responder y pueden agotar el presupuesto sin llegar a responder. Desactivado = respuesta directa.',
    'settings.chat.threshold'   => 'Umbral de compresión (tokens)',
    'settings.chat.threshold_help' => 'Por encima, los mensajes antiguos se resumen.',
    'settings.chat.target'      => 'Mensajes recientes conservados (tokens)',
    'settings.chat.target_help' => 'Lo que queda sin resumir tras la compresión.',

    // --- pestaña Conversación: respuestas en varias burbujas
    'settings.split.title'      => 'Respuestas en varias burbujas',
    'settings.split.hint'       => 'Divide una respuesta larga en dos mensajes, al final de una frase, con una pequeña pausa.',
    'settings.split.enable'     => 'Dividir las respuestas en varias burbujas',
    'settings.split.words'      => 'Palabras máximas por burbuja',
    'settings.split.words_help' => 'La burbuja se corta en el último final de frase antes de este límite.',
    'settings.split.bubbles'    => 'Burbujas máximas',
    'settings.split.bubbles_help' => '1 = nunca dividir.',
    'settings.split.delay_min'  => 'Pausa mín.',
    'settings.split.delay_max'  => 'Pausa máx.',
    'settings.split.delay_help' => 'Pausa aleatoria entre dos burbujas, dentro de este intervalo.',

    // --- pestaña Conversación: mensajes espontáneos
    'settings.followup.title'   => 'Mensajes espontáneos',
    'settings.followup.hint'    => 'La persona puede escribirte por iniciativa propia tras un largo silencio (se puede desactivar conversación por conversación).',
    'settings.followup.enable'  => 'Permitir mensajes espontáneos',
    'settings.followup.idle'    => 'Relanzar tras (horas de silencio)',
    'settings.followup.idle_help' => 'Horas de silencio antes de que pueda escribirte.',
    'settings.followup.hours'   => 'Franja horaria',
    'settings.followup.hours_from' => 'de',
    'settings.followup.hours_to'   => 'h a',
    'settings.followup.hours_end'  => 'h',
    'settings.followup.from_aria'  => 'Hora de inicio',
    'settings.followup.to_aria'    => 'Hora de fin',
    'settings.followup.hours_help' => 'Ningún mensaje espontáneo fuera de esta franja.',

    // --- pestaña Conversación: recuerdos duraderos
    'settings.facts.title'      => 'Recuerdos duraderos',
    'settings.facts.hint'       => 'Lo que las personas recuerdan de ti de una conversación a otra. Elige cuándo
                la app los extrae y con qué modelo.',
    'settings.facts.frequency'  => 'Frecuencia',
    'settings.facts.frequency_aria' => 'Frecuencia de la extracción',
    'settings.facts.freq_each'  => 'Después de cada intercambio',
    'settings.facts.freq_every' => 'Cada N mensajes',
    'settings.facts.freq_explicit' => 'Solo cuando lo pidas («recuerda que…»)',
    'settings.facts.freq_off'   => 'Nunca',
    'settings.facts.every'      => 'Número de mensajes nuevos',
    'settings.facts.every_help' => 'Un intercambio = 2 mensajes (el tuyo y la respuesta).',
    'settings.facts.timing'     => 'Momento',
    'settings.facts.timing_aria' => 'Momento de la extracción',
    'settings.facts.timing_now' => 'Justo después de la respuesta',
    'settings.facts.timing_idle' => 'Cuando la conversación está tranquila',
    'settings.facts.idle'       => 'Tranquila desde hace (segundos)',
    'settings.facts.idle_help'  => 'Sin mensajes nuevos ni escritura durante este tiempo.',
    'settings.facts.model_aria' => 'Modelo utilizado para los recuerdos',

    // --- pestaña Conversación: títulos automáticos
    'settings.titles.title'     => 'Títulos de las conversaciones',
    'settings.titles.hint'      => 'Tras los primeros intercambios, la conversación recibe un título corto
                (mientras no le hayas cambiado el nombre tú mismo).',
    'settings.titles.model_aria' => 'Modelo de los títulos automáticos',
    'settings.titles.off'       => 'Sin título automático',

    // --- pestaña Conversación: mantenimiento de LM Studio
    'settings.purge.title'      => 'Mantenimiento de LM Studio',
    'settings.purge.text'       => 'Cambiar de modelo ya descarga el anterior automáticamente. Este botón fuerza la liberación
            de la memoria de vídeo (VRAM) si LM Studio mantiene modelos cargados.',
    'settings.purge.button'     => 'Vaciar la memoria de LM Studio',

    // --- pestaña Apariencia
    'settings.look.title'       => 'Apariencia de las conversaciones',
    'settings.look.subtitle'    => 'Se aplica a todas las conversaciones. La vista previa cambia en directo.',
    'settings.look.preview'     => 'Vista previa',
    'settings.look.preview_bot_html'  => '¡Hola! ¿Has tenido un buen día?',
    'settings.look.preview_user_html' => 'Sí, ¿y tú?',
    'settings.look.tabbar'          => 'Barra de navegación (móvil)',
    'settings.look.tabbar_float'    => 'Cápsula flotante',
    'settings.look.tabbar_pill'     => 'Píldora que se despliega',
    'settings.look.tabbar_classic'  => 'Clásica cuidada',
    'settings.look.tabbar_help'     => 'Visible en móvil y tableta; la vista previa se aplica al instante en la parte inferior de la pantalla.',
    'settings.look.font_size'   => 'Tamaño del texto',
    'settings.look.font_small'  => 'Pequeño',
    'settings.look.font_normal' => 'Normal',
    'settings.look.font_large'  => 'Grande',
    'settings.look.font_xlarge' => 'Muy grande',
    'settings.look.bubble_style' => 'Estilo de las burbujas',
    'settings.look.bubble_round' => 'Burbujas redondeadas',
    'settings.look.bubble_flat' => 'Planas',
    'settings.look.bubble_card' => 'Tarjetas',
    'settings.look.density'     => 'Densidad',
    'settings.look.density_comfort' => 'Cómoda',
    'settings.look.density_compact' => 'Compacta',
    'settings.look.width'       => 'Ancho de los mensajes',
    'settings.look.width_normal' => 'Normal',
    'settings.look.width_large' => 'Ancho',
    'settings.look.avatars'     => 'Mostrar el avatar del personaje junto a sus mensajes',
    'settings.look.save'        => 'Guardar la apariencia',

    // --- pestaña Prompts
    'settings.prompts.title'    => 'Prompts internos',
    'settings.prompts.subtitle' => 'Las instrucciones que la app envía al modelo para la memoria, los recuerdos y los mensajes espontáneos. Vacíos = texto por defecto.',
    'settings.prompts.memory_label'   => 'Resumen de la conversación',
    'settings.prompts.memory_help'    => 'Comprime los mensajes antiguos. Debe devolver un JSON con current_topic, conversation_goal, important_facts, decisions, open_questions, context_summary.',
    'settings.prompts.facts_label'    => 'Extracción de recuerdos',
    'settings.prompts.facts_help'     => 'Decide qué se convierte en un recuerdo duradero sobre el usuario (persona). Debe devolver un JSON con memories / forget.',
    'settings.prompts.followup_label' => 'Mensaje espontáneo',
    'settings.prompts.followup_help'  => 'Instrucción del mensaje no solicitado. Marcadores disponibles: {persona} y {gap}.',
    'settings.prompts.custom'   => 'personalizado',
    'settings.prompts.reset'    => 'Restablecer',
    'settings.prompts.save'     => 'Guardar los prompts',

    // --- pestaña Copia de seguridad
    'settings.backup.title'     => 'Copia de seguridad',
    'settings.backup.subtitle'  => 'Un ZIP por copia: una carpeta por persona con su ficha, sus prompts y sus fotos.',
    'settings.backup.export'    => 'Exportar',
    'settings.backup.count_personas' => ['one' => '{n} persona', 'other' => '{n} personas'],
    'settings.backup.count_photos'   => ['one' => '{n} foto', 'other' => '{n} fotos'],
    'settings.backup.count_prompts'  => ['one' => '{n} prompt', 'other' => '{n} prompts'],
    'settings.backup.count_conversations' => ['one' => '{n} conversación', 'other' => '{n} conversaciones'],
    'settings.backup.include_conversations' => 'Incluir las conversaciones, los mensajes y los recuerdos',
    'settings.backup.download'  => 'Descargar la copia de seguridad',
    'settings.backup.last'      => 'Última copia: {date}',
    'settings.backup.size_mb'   => '{size} MB',
    'settings.backup.none'      => 'Todavía no has descargado ninguna copia.',
    'settings.backup.restore'   => 'Restaurar',
    'settings.backup.restore_hint' => 'Importa un ZIP de copia de seguridad. Las personas existentes nunca se sobrescriben: se crea un slug único.',
    'settings.backup.import_confirm' => '¿Importar esta copia de seguridad? Las personas se añadirán a la biblioteca.',
    'settings.backup.import_confirm_title' => 'Importar la copia de seguridad',
    'settings.backup.import_confirm_ok' => 'Importar',
    'settings.backup.choose_file' => 'Elegir un archivo ZIP',
    'settings.backup.import_submit' => 'Importar el archivo',
    'settings.backup.tip'       => 'Consejo: guarda el ZIP fuera del servidor (disco externo, nube). Contiene todo lo necesario para empezar de cero.',

    // --- burbujas de información (botón «i»): etiqueta del botón, título de la ventana, contenido
    'settings.info.ia_model_label'    => 'modelo de las fichas',
    'settings.info.ia_model_title'    => 'Modelo de las fichas',
    'settings.info.ia_model_html'     => '<p>Este modelo se usa para tareas puntuales: <strong>completar una ficha</strong> con el botón IA,
            proponer una consigna de juego y, si lo eliges, extraer los recuerdos.</p>
            <p>Suele ser un servicio remoto (DeepSeek, OpenAI, OpenRouter…). Los datos solo se envían
            cuando haces clic en una acción de IA.</p>',
    'settings.info.api_key_label'     => 'clave API',
    'settings.info.api_key_html'      => '<p>La clave se guarda en la base local de la app y <strong>nunca se vuelve a mostrar</strong>
            en la página. Deja el campo vacío para conservar la clave actual.</p>
            <p>El botón «Probar la conexión» solo envía la clave guardada a la dirección guardada: si
            cambias de dirección, vuelve a escribir la clave.</p>',
    'settings.info.chat_model_label'  => 'modelo de conversación',
    'settings.info.chat_model_html'   => '<p>Es el modelo que hace hablar a las personas, normalmente <strong>LM Studio en tu equipo</strong>
            (dirección <code>http://localhost:1234/v1</code> por defecto). Nada sale de tu casa.</p>
            <p>Si el servidor no responde, la app te pregunta qué hacer: nunca cambia por sí sola
            a un servicio remoto.</p>',
    'settings.info.reasoning_label'   => 'razonamiento del modelo',
    'settings.info.reasoning_html'    => '<p>Algunos modelos (Qwen3, Gemma…) «razonan» antes de responder. Es más lento y
            consume el presupuesto de tokens, a veces hasta el punto de no responder nunca.</p>
            <p>Para una conversación natural, déjalo <strong>Desactivado</strong>.</p>',
    'settings.info.compression_label' => 'memoria de la conversación',
    'settings.info.compression_title' => 'Memoria de la conversación',
    'settings.info.compression_html'  => '<p>El modelo solo puede leer un número limitado de tokens (su «ventana»). Cuando los mensajes
            <strong>aún no resumidos</strong> superan el <strong>umbral de compresión</strong>, los más antiguos
            se resumen en unas líneas (tema, hechos, decisiones).</p>
            <p>Después, el modelo recibe ese resumen + los mensajes recientes (aproximadamente el valor
            «mensajes recientes conservados»), y no todo el historial: respuestas más rápidas y
            más espacio para lo que sigue.</p>
            <p>Un token ≈ 3 a 4 caracteres de texto.</p>',
    'settings.info.split_label'       => 'burbujas',
    'settings.info.split_html'        => '<p>Una respuesta larga se corta al final de una frase y se muestra en varios mensajes, con una
            breve pausa entre cada uno, como en una mensajería de verdad.</p>
            <p>«Burbujas máximas» en 1 desactiva la división.</p>',
    'settings.info.followup_label'    => 'mensajes espontáneos',
    'settings.info.followup_html'     => '<p>Tras un largo silencio, la persona puede escribirte por iniciativa propia, solo dentro de la franja
            horaria elegida y como mucho una vez por periodo de silencio.</p>
            <p>Estos mensajes los lanza una tarea programada en el servidor, por ejemplo cada
            30 minutos: <code>php bin/followup.php</code> (cron). Sin ella, no se envía nada.</p>',
    'settings.info.facts_label'       => 'recuerdos duraderos',
    'settings.info.facts_html'        => '<p>Tras algunos intercambios, la app pregunta a un modelo qué merece la pena recordar sobre ti
            (nombre, gustos, proyectos…). Estos recuerdos se comparten entre todas las conversaciones de una persona
            y se pueden ver en la página <strong>Recuerdos</strong>.</p>
            <p>La extracción se hace <strong>en segundo plano</strong>: nunca bloquea la pantalla.
            En cambio, si usa el modelo local, lo ocupa unos segundos.</p>',
    'settings.info.facts_frequency_label' => 'frecuencia',
    'settings.info.facts_frequency_html'  => '<p><strong>Después de cada intercambio</strong>: lo más reactivo, pero una llamada al modelo en cada respuesta.</p>
            <p><strong>Cada N mensajes</strong>: agrupa varios intercambios en una sola llamada. No se pierde nada:
            se analizan todos los mensajes desde la última extracción.</p>
            <p><strong>Solo cuando lo pidas</strong>: únicamente cuando escribes «recuerda que…», «acuérdate de…».</p>
            <p><strong>Nunca</strong>: ningún recuerdo automático nuevo (los existentes se siguen usando).</p>',
    'settings.info.facts_timing_label' => 'momento de la extracción',
    'settings.info.facts_timing_html'  => '<p><strong>Justo después de la respuesta</strong>: se lanza justo después de cada respuesta.</p>
            <p><strong>Cuando la conversación está tranquila</strong>: espera a que no hayas escrito nada durante el tiempo
            elegido. Así el modelo local sigue disponible mientras conversáis. Si sales de la página
            antes, la extracción se hará en la próxima ocasión, sin pérdidas.</p>',
    'settings.info.facts_model_label' => 'modelo de los recuerdos',
    'settings.info.facts_model_html'  => '<p><strong>Modelo de conversación (local)</strong>: todo se queda en tu equipo.</p>
            <p><strong>Modelo de las fichas (remoto)</strong>: alivia el modelo local, pero los últimos mensajes de
            la conversación se <strong>envían entonces al servicio remoto</strong> (DeepSeek, OpenAI…).
            Elígelo con conocimiento de causa.</p>',
    'settings.info.titles_label'      => 'títulos automáticos',
    'settings.info.titles_html'       => '<p><strong>Modelo de las fichas (remoto)</strong>: los 4 primeros mensajes de la conversación
            (300 caracteres cada uno como máximo) se <strong>envían al servicio remoto</strong> para encontrar un título.
            Rápido, y el modelo local queda libre.</p>
            <p><strong>Modelo de conversación (local)</strong>: nada sale de tu equipo; el modelo local
            está ocupado uno o dos segundos después del primer intercambio.</p>
            <p><strong>Sin título automático</strong>: la conversación conserva «Conversación con …»
            hasta que le cambies el nombre.</p>',
    'settings.info.purge_label'       => 'vaciado',
    'settings.info.purge_html'        => '<p>Descarga todos los modelos cargados en LM Studio para liberar la memoria de la tarjeta gráfica
            (VRAM). Útil si LM Studio mantiene varios modelos en memoria y va lento.</p>
            <p>El modelo de conversación se volverá a cargar automáticamente con el próximo mensaje.</p>',
    'settings.info.look_label'        => 'apariencia',
    'settings.info.look_html'         => '<p>Estos ajustes solo cambian cómo se muestran las conversaciones, en todos tus dispositivos.
            La vista previa en la parte superior del formulario muestra el resultado antes de guardar.</p>',
    'settings.info.prompts_label'     => 'prompts internos',
    'settings.info.prompts_html'      => '<p>Son las instrucciones que la app envía al modelo para <strong>resumir</strong> una conversación,
            <strong>extraer los recuerdos</strong> y escribir los <strong>mensajes espontáneos</strong>.</p>
            <p>Los dos primeros deben hacer que el modelo responda en JSON con los campos indicados: si los
            modificas, mantén ese formato. «Restablecer» vuelve al texto original.</p>',
    'settings.info.backup_label'      => 'copia de seguridad',
    'settings.info.backup_html'       => '<p>El ZIP contiene una carpeta por persona: ficha, prompts, retrato y fotos. Marca la opción para
            añadir las conversaciones y los recuerdos.</p>
            <p>Al importar, no se sobrescribe nada: una persona que ya existe se vuelve a crear con un nuevo nombre de URL.
            Guarda tus copias de seguridad fuera del servidor.</p>',
    'settings.followup.gap'       => 'Tiempo entre dos mensajes (horas)',
    'settings.followup.gap_help'  => 'En una misma conversación, no habrá otro mensaje antes de este plazo, aunque el silencio continúe.',
];
