# VAST-001 — alta estándar de nuevas instancias Vast.ai (PRL)

**Ámbito:** nueva instancia candidata o ya alquilada en Vast.ai, con cualquier combinación admitida de GPU NVIDIA (1–16). Esta regla es obligatoria para la puesta en servicio; no constituye permiso para alquilar ni dispara acciones por sí misma. La instancia de referencia 2× RTX 5090 demostró el flujo, pero **no se deben copiar sus identificadores, precio, billeteras de prueba o umbrales** a otras máquinas.

**Extensión obligatoria en producción:** ejecutar **VAST-002** como fase de alta de cada instancia y seguir `docs/VAST_WATCHDOG_ALERTS.md`. El vigilante externo ya está desplegado en HACHE-MINER y usa el canal **ntfy del teléfono** que también utiliza Salad Monitor, con temporizador systemd propio cada minuto. **Registrar la instancia no basta:** hay que verificar telemetría fresca, inclusión en vigilancia, temporizador activo y entrega real de ntfy. Si alguna verificación falla, declarar `MINANDO SIN ALERTAS` y no certificar alta protegida. La clasificación directa «minero parado» mediante heartbeat independiente y el reinicio automático siguen pendientes de implementar.

**Principio:** *Una instancia no está operativa hasta que mina a la billetera aprobada, comparte shares válidos y transmite telemetría por GPU a HACHE-MINER automáticamente.*

## A. Selección y autorización (antes de generar costos nuevos)

- Reunir: modelo(s) GPU, cantidad, VRAM, límites de potencia y disponibilidad real, región y confiabilidad del host, versión de CUDA/driver, tarifa USD/h **total** (incluir costos adicionales de la oferta).
- Calcular hashrate estimado **con evidencia de pruebas comparables**, costo USD/TH-h y punto de equilibrio bajo el precio actual de PRL, dificultad y tarifas de Kryptex; marcar estimaciones como tales. No extrapolar resultados de 5090 a otros modelos.
- Antes de alquilar, confirmar **autorización explícita**, límite USD, duración de prueba y criterio de apagado. No reutilizar autorización para una máquina distinta.
- Aplicar primeramente comandos de lectura; parar si la identidad o el costo no coinciden con los autorizados.

## B. Inventario y credenciales correctas (antes de arrancar)

1. Registrar **`instance_id`, `machine_id`, `host_id` y tarifa USD/h** reales obtenidos de Vast; no usar `pending` como dato final. Detectar GPU físicas mediante `nvidia-smi -L`, comprobar nombres, temperatura, potencia y conteo (1–16).
2. Verificar acceso SSH con huella de host conocida y la clave autorizada para **esa instancia**. No publicar secretos, desactivar comprobación de host ni copiar claves privadas a Git.
3. Confirmar la dirección pública PRL aprobada por el operador **antes del primer arranque**. El destino del minero debe ser `<wallet_aprobada>.<worker_unico>`; ejemplo de worker genérico: `vast-<instance_id>`. No emplear billeteras heredadas ni copiar el lanzador de otra máquina sin validar su destino.
4. Instalar o verificar **SRBMiner-MULTI con versión y SHA-256 aprobados**, compatibilidad con GPU, algoritmo `pearlhash`, Kryptex por TLS, contraseña de pool cuando corresponda y minería CPU deshabilitada. Las rutas, flags y binarios son específicos del host.
5. Configurar lanzador desacoplado del terminal (salida a `/var/log/prl-miner.log`) y comprobar que no existe un proceso de minería duplicado; no reemplazar un minero existente que ya produce sin diagnóstico y aprobación.

## C. Arranque y resiliencia

- Instalar un `/root/onstart.sh` apropiado **a la nueva instancia**, con respaldo, `bash -n`, permisos `0700`, detección del ejecutable y lanzador correctos, `flock` y verificación del proceso existente antes de lanzar. El `deploy/vast-onstart.sh` actual está **ligado al lanzador de la prueba 5090x2**: debe parametrizarse o adaptarse de forma versionada y ensayada antes de reutilizarse.
- Habilitar `cron` para telemetría. No asumir que está arrancado: comprobar `pgrep -x cron` y que vuelva a arrancar con la instancia. No probar con `reboot` o detener GPU activas.
- No confundir «arranque al encender instancia» con «recuperación si SRBMiner muere mientras permanece encendida». Lo segundo requiere supervisión independiente, límites de reinicio y autorización explícita.

## D. Registro y telemetría HACHE-MINER

1. En MariaDB del VPS, garantizar migración aditiva `database/009_vast_telemetry.sql` aplicada y registrar una fila **por instancia** en `vast_instances`, con su `instance_id`, `machine_id`, `host_id`, `gpu_count`, tarifa real `hourly_usd`, nombre y umbrales sustentados por prueba. No alterar las tablas de Salad.
2. Instalar `deploy/vast-collector.py` en Vast desde una **versión de GitHub fijada por commit**, validar con `python3 -m py_compile`, comprobar que lee la tabla física completa más reciente del log y rechaza logs obsoletos. No usar el promedio del pool como si fuera cada GPU.
3. Utilizar el receptor **`https://miner.hacheinteractive.com/vast-ingest`** (no `/vast-ingest.php`). En el VPS, la clave compartida se administra fuera del repositorio, por ejemplo `/etc/hache-miner/vast-ingest.key`; en Vast debe almacenarse fuera de Git con permisos de solo root, por ejemplo `/etc/hache-vast/ingest.key`, `0600`.
4. Transferir la clave por canal SSH autenticado, con autorización de lectura limitada y caducidad si se necesita un puente. **No escribir la clave en los argumentos de procesos, capturas, consola, logs ni mensajes.** Revocar la excepción de SSH tan pronto termine el aprovisionamiento (no esperar a su expiración).
5. Configurar tarea **cada minuto** con `flock`, sin procesos solapados, enrutando las muestras firmadas HMAC al receptor. No activar envíos si faltan la clave, la identidad real o el conteo de GPU.
6. Verificar por separado: salida de prueba del colector (`202 Accepted`), **dos ejecuciones posteriores programadas** con éxito y dos muestras actuales por cada GPU en el VPS/panel; confirmar que no haya otro worker con el mismo identificador. Un `400` sin firma solo demuestra el rechazo correcto de una petición no autenticada.

## E. VAST-002 — vigilancia ntfy obligatoria en cada nueva instancia

1. **Servidor VPS:** el servicio ya instalado `hache-miner-vast-watchdog.service` se ejecuta desde el timer `hache-miner-vast-watchdog.timer` cada minuto, con el usuario restringido `hache-miner`, separado de SaladCloud. Antes del alta verificar `systemctl is-active hache-miner-vast-watchdog.timer`, una última ejecución `Result=success` y que la base de datos esté accesible. No crear un segundo timer por GPU o por instancia: **un solo vigilante del VPS recorre las filas `vast_instances WHERE enabled=1`**.
2. **Canal ntfy existente:** conservar el tema privado de Salad en `/etc/hache-salad-ntfy.env`; para el watchdog se usa una copia protegida `/etc/hache-miner/vast-ntfy-topic` legible por el servicio (`root:hache-miner`, modo `0640`). No publicar, imprimir ni versionar el tema; no crear un tema nuevo ni solicitar suscripción adicional al teléfono. Si el tema de Salad rota, actualizar la copia de Vast de manera controlada.
3. **Registro correcto:** al insertar/actualizar `vast_instances`, confirmar `enabled=1`, `instance_id` real, modelo/cantidad de GPU, etiqueta y `hourly_usd` de la oferta, sin tocar otras filas ni copiar la tarifa de las 5090. La fila habilitada queda incluida automáticamente en la siguiente evaluación del watchdog; comprobarlo mediante `php -d auto_prepend_file=/etc/hache-miner/load-env.php bin/vast-watchdog.php --dry-run` **como usuario hache-miner**, no con una simulación que interrumpa minería.
4. **Salud comprobada:** esperar dos muestras auténticas de telemetría por GPU y comprobar que el monitor reconozca la nueva instancia. No declarar protección mientras la nueva instancia esté en la ventana de gracia sin muestras. Consultar log del servicio y panel tras dos ejecuciones del timer; esperar `VAST_WATCHDOG_OK` con `instances` igual al inventario habilitado y `issues=0` si todos están sanos.
5. **Notificación al teléfono:** ejecutar una **prueba de notificación explícitamente rotulada PRUEBA** en el mismo ntfy del usuario, sin modificar el estado persistente del watchdog ni provocar una incidencia falsa. Confirmar recepción real en Android; HTTP 2xx por sí solo **no** acredita recepción en el teléfono. Probar también deduplicación, transición a alerta y recuperación con mocks/tests, sin matar SRBMiner en una máquina facturando.
6. **Criterios de alerta activos (VAST-002 v1):** sin telemetría reciente más de 180 segundos → alerta roja de señal perdida (la instancia **podría seguir facturando**, no confirmar que se apagó); 2 lecturas consecutivas de una GPU a 0 TH/s → alerta roja; shares aceptadas sin avanzar durante ventana de aproximadamente 10 minutos con suficientes observaciones → alerta amarilla contextual; recuperación → aviso único. Cada instancia tiene estado persistente para evitar duplicados; el watchdog **no reinicia SRBMiner ni apaga Vast**, y no identifica inequívocamente un proceso muerto hasta incorporar heartbeat independiente.
7. **Fallas y reversión:** si `ntfy` devuelve error, faltan permisos/credenciales, el timer deja de estar activo o los datos son obsoletos, mostrar `MINANDO SIN ALERTAS`, avisar al operador por el canal que sí esté disponible y no llamar «protegida» a la instancia. Conservar Salad Monitor y su timer sin modificaciones.

## F. Criterios de aceptación («lista para minar y protegida»)

No marcar la instancia como lista hasta comprobar:

- Identidad de Vast, GPU físicas y costo/h correctos; presupuesto y tiempo de prueba autorizados.
- Billetera pública exacta y worker exclusivo; **shares aceptados** por Kryptex de esa máquina, no de una diferente.
- SRBMiner activo una sola vez, hashrate instantáneo y lectura de 5–15 minutos por GPU; temperatura, potencia y rechazo de shares razonables para el modelo probado.
- Hook al inicio y `cron` verificados sin reiniciar ni duplicar el minero.
- Muestras por GPU **frescas (menos de 180 s)** registradas en HACHE-MINER; dos ciclos consecutivos, estado no obsoleto y cálculo separado de **costo estimado vs. cargo real**.
- **VAST-002 obligatorio:** la instancia está habilitada en el inventario del vigilante externo, el timer del VPS está activo, dos evaluaciones consecutivas terminaron sin errores, el estado es sano y una notificación de prueba fue recibida en el ntfy del Android. Si no: **`MINANDO SIN ALERTAS`**, aunque los TH/s y shares sean buenos.
- Respaldo/reversión documentados y credenciales temporales revocadas.

Si cualquiera de estas comprobaciones falla, dejar estado **PENDIENTE/NO LISTA**, informar el motivo exacto y el costo que sigue corriendo. No declarar «telemetría terminada» solo por una respuesta de prueba aislada.

## G. Operación, límites y reversión

- Las alertas y autorrelocaciones de SaladCloud **no se reutilizan automáticamente** para Vast. Nuevos umbrales de GPU solo después de mediciones consistentes y autorización. Mantener los datos por proveedor aislados.
- Ante pool sin shares, wallet discordante, log estancado, key HMAC inválida o muestra atrasada, avisar y diagnosticar; evitar reinicios en bucle que consuman presupuesto.
- Conservar respaldo del lanzador y del hook existentes; revertir solo archivos nuevos si falla el aprovisionamiento y preservar los procesos activos ajenos. Si se decide apagar una instancia **de alquiler**, solicitar autorización cuando afecte costos/producción.
- Una instancia nueva puede tener almacenamiento efímero: comprobar persistencia del hook/configuración al reanudar y repetir aprovisionamiento seguro cuando se recree.
- No subir direcciones reales de operación, credenciales, endpoints privados no documentados, tarifas específicas de la cuenta ni secretos a este repositorio público.

## H. Plantilla de entrega por cada instancia

```text
VAST-001 — RESULTADO DE ALTA
Vast instance_id / machine_id / host_id: [identidad verificada]
GPU: [modelo y cantidad]
Tarifa efectiva USD/h: [valor verificable]
Wallet: [coincide con la autorizada: sí/no; no imprimir completa]
Worker único: [nombre]
SRBMiner (versión, hash, proceso): [verificado/no]
Hashrate por GPU / total: [TH/s, período, hora UTC]
Shares aceptados / rechazados: [conteos nuevos]
Onstart idempotente / cron 1 min: [sí/no]
HACHE-MINER: [dos muestras, hora UTC, frescura, por GPU]
VAST-002: [instancia habilitada: sí/no; timer VPS: activo/inactivo; 2 ciclos OK]
ntfy en Android: [prueba rotulada recibida/no recibida, hora UTC]
Estado de protección: [PROTEGIDA / MINANDO SIN ALERTAS]
Rentabilidad: [estimada, con supuestos y comisión de pool]
Excepciones y pendientes: [...]
Decisión: LISTA / NO LISTA
```

**Para agentes:** antes de poner a trabajar cualquier nueva oferta Vast, ejecutar esta lista como parte de la tarea, no como una sugerencia opcional. Automatizar el procedimiento con scripts genéricos y pruebas en una rama separada antes de anunciar que existe *aprovisionamiento desatendido*. Nunca alquilar automáticamente solo porque esta regla existe.
