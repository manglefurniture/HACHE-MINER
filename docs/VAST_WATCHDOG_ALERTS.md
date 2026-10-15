# VAST-002 — watchdog y alerta de minería detenida

**Estado de esta especificación:** requerida para nuevas instancias; **NO implementada ni activa** hasta desplegar y verificar el servicio. La telemetría actual puede dejar de emitir cuando SRBMiner se detiene. Una muestra antigua marcada `stale` en el panel **no** equivale a una notificación.

## Objetivo
Detectar en cada instancia Vast.ai que: a) el minero murió pero la instancia sigue facturando, b) el proceso existe pero la GPU no produce, c) no se aceptan shares pese a hashrate reportado, d) falla el colector o toda la comunicación. Alertar de forma accionable al operador y confirmar la recuperación, sin depender del proceso de minería para generar alertas.

## Dos detectores independientes

**1. Watchdog local en Vast** (cron/supervisor, 1/min, desacoplado de SRBMiner):
- Reportar un **heartbeat firmado** independientemente del proceso SRBMiner: `instance_id`, `observed_at`, `miner_pid_alive`, `gpu_count`, estado de acceso a logs, última edad de actividad del minero, GPU disponibles. Evitar secretos en payload/logs.
- El endpoint actual `/vast-ingest` acepta **solo muestras de minería con GPU**; no interpreta heartbeats de minero caído: **es necesaria una migración/API nueva probada**; no enviar payloads falsos a la API existente.
- Si `pgrep SRBMiner-MULTI` falla, informar el evento local y seguir emitiendo heartbeat. Nunca tratar la ausencia de minería como «sin costo».
- Capturar el estado exacto del proceso, no deducirlo de la utilización de la GPU a secas.

**2. Vigilante externo en VPS** (ejecutado incluso si Vast y su colector fallan):
- Consultar instancias Vast registradas y habilitadas que se esperan minando; comparar `received_at` de última muestra y, cuando esté habilitado, último heartbeat independiente.
- Sin señal durante **3 minutos** tras una ventana de arranque de cortesía: emitir alerta `VAST_TELEMETRY_LOST`. No concluir «instancia apagada» sin consultarlo a Vast; puede seguir facturando.
- Con heartbeat reciente y `miner_pid_alive=false` en dos chequeos separados por 1 minuto: `VAST_MINER_STOPPED`, alerta inmediata tras confirmación.
- Minero vivo pero 2 muestras consecutivas con 0 TH/s en alguna GPU: `VAST_GPU_NO_HASH` con GPU física concreta.
- Hashrate anormalmente bajo: 2–3 muestras según umbral **individualizado** para el modelo, sin heredar umbrales de Salad ni de RTX 5090.
- Shares aceptados sin incrementar: detección basada en frecuencia estadística observada, dificultad y ventana adaptativa (por ejemplo **10 minutos iniciales de observación**, no como regla rígida universal); un proceso vivo o alto TH/s no prueban pagos. Diferenciar pool caído, conexión rota y minería con shares rechazados.
- Cuando se restauren la telemetría y las condiciones, enviar **una sola alerta de recuperación**, con duración estimada e impacto USD aproximado (tarifa/h * minutos de anomalía / 60).

## Notificaciones y control del ruido

- Notificar vía el **canal aprobado por el usuario** (por ejemplo Telegram, correo o servicio de notificaciones soportado); elegir canal y probar entrega antes de considerar la regla activa. No confiar únicamente en el panel web.
- Cada evento tiene estado `open/acknowledged/resolved`, marca UTC, `instance_id`, GPU afectadas, `last_seen`, última tasa/share, tarifa por hora, coste de inactividad estimado. **Deduplicar**: un aviso al abrir, recordatorio escalado si persiste (por ejemplo 10 min), uno al recuperarse.
- Si el sistema central de alertas no está disponible, usar un segundo canal de salud del VPS cuando pueda configurarse; no prometer avisos si también cae el vigilante.
- Incidente de datos ausentes ≠ instancia detenida; reflejar incertidumbre y evitar afirmar «miner stopped» solo por silencio.

## Recuperación opcional, nunca implícita

- Primero alertas. Un **autorreinicio de SRBMiner** es una fase posterior que requiere permiso explícito y supervisor con límite de intentos (p. ej. 1–2 intentos y cooldown), comprobación de billetera/worker, protección anti-bucle y verificación de shares posteriores.
- No apagar ni destruir alquileres automáticamente para evitar facturación sin política de costos expresamente aprobada por instancia. No reiniciar Vast completo como primera reacción.
- No modificar procesos ni reglas de SaladCloud.

## Aceptación y pruebas

1. Pruebas reproducibles de estados: miner stopped / alive zero TH/s / no new shares / stale telemetry / reboot recovery / low TH/s / network outage / recovery / duplicate heartbeat / orphaned instance. Usar mocks y fixtures; **no matar minería activa en producción para probar alertas**.
2. Verificar realmente entrega al teléfono/canal elegido, duplicados suprimidos, cierre/recuperación y freshness de alertas.
3. Sin credenciales en Git ni logs; HMAC, reloj y replay, validación de instancia y origen. Procesos de vigilancia independientes y resistentes a errores.
4. Al incorporar una nueva instancia Vast bajo `VAST-001`, **no declararla protegida** hasta activar y probar este watchdog. Si falta, etiquetar `MINANDO SIN ALERTAS` y explicitar riesgo por USD/h.

Esta especificación no instala cron, watchdog, esquemas ni alertas: requiere un PR de código y despliegue separados, pruebas y selección del canal de salida.
