# VAST-002 — watchdog y alerta de minería detenida

**Estado operativo (15 de octubre de 2026):** watchdog **externo del VPS implementado y activado** mediante `hache-miner-vast-watchdog.timer`/`.service` con el usuario `hache-miner`, usando **el mismo canal ntfy del Salad Monitor**. El servicio vigiló correctamente la instancia de referencia y su primera ejecución informó `VAST_WATCHDOG_OK instances=1 issues=0 notifications=0`. Requiere probar la entrega al teléfono y confirmar cobertura para cada nueva instancia. **Heartbeat independiente emitido por Vast, autorreinicio, avisos escalados y otras funciones indicadas como futuras abajo aún no están implementados.** Una muestra antigua marcada `stale` en el panel por sí sola no constituye una notificación.

## Objetivo
Detectar en cada instancia Vast.ai que: a) el minero murió pero la instancia sigue facturando, b) el proceso existe pero la GPU no produce, c) no se aceptan shares pese a hashrate reportado, d) falla el colector o toda la comunicación. Alertar de forma accionable al operador y confirmar la recuperación, sin depender del proceso de minería para generar alertas.

## Componentes: implementado y pendiente

**1. Heartbeat local en Vast — PENDIENTE** (cron/supervisor, 1/min, desacoplado de SRBMiner):
- Reportar un **heartbeat firmado** independientemente del proceso SRBMiner: `instance_id`, `observed_at`, `miner_pid_alive`, `gpu_count`, estado de acceso a logs, última edad de actividad del minero, GPU disponibles. Evitar secretos en payload/logs.
- El endpoint actual `/vast-ingest` acepta **solo muestras de minería con GPU**; no interpreta heartbeats de minero caído: **es necesaria una migración/API nueva probada**; no enviar payloads falsos a la API existente.
- Si `pgrep SRBMiner-MULTI` falla, informar el evento local y seguir emitiendo heartbeat. Nunca tratar la ausencia de minería como «sin costo».
- Capturar el estado exacto del proceso, no deducirlo de la utilización de la GPU a secas.

**2. Vigilante externo en VPS — ACTIVO (versión 1)** (ejecutado incluso si Vast y su colector fallan):
- Consultar instancias Vast registradas y habilitadas que se esperan minando; comparar `received_at` de última muestra y, cuando esté habilitado, último heartbeat independiente.
- Sin señal durante **3 minutos** tras una ventana de arranque de cortesía: emitir alerta `VAST_TELEMETRY_LOST`. No concluir «instancia apagada» sin consultarlo a Vast; puede seguir facturando.
- **Pendiente:** con heartbeat reciente y `miner_pid_alive=false` en dos chequeos separados por 1 minuto: `VAST_MINER_STOPPED`. Hoy, si SRBMiner deja de ejecutarse, el colector de Vast deja de transmitir y el VPS avisa como `telemetry_lost` después de 180 segundos, sin atribuir causa confirmada.
- Minero vivo pero 2 muestras consecutivas con 0 TH/s en alguna GPU: `VAST_GPU_NO_HASH` con GPU física concreta.
- **Pendiente:** hashrate anormalmente bajo, con 2–3 muestras según umbral **individualizado** para el modelo; no heredar umbrales de Salad ni de RTX 5090. La versión 1 detecta 0 TH/s en dos lecturas consecutivas, pero no degradación porcentual.
- Shares aceptados sin incrementar: detección basada en frecuencia estadística observada, dificultad y ventana adaptativa (por ejemplo **10 minutos iniciales de observación**, no como regla rígida universal); un proceso vivo o alto TH/s no prueban pagos. Diferenciar pool caído, conexión rota y minería con shares rechazados.
- Al restaurarse las condiciones, el watchdog v1 envía **una sola alerta de recuperación**. Incluye duración aproximada y tarifa horaria estimada; el cálculo de impacto económico acumulado sigue pendiente.

## Notificaciones y control del ruido

- **Canal activo:** `ntfy.sh` usando el mismo tema privado de Salad Monitor. El tema original queda en `/etc/hache-salad-ntfy.env`, la copia para HACHE-MINER en `/etc/hache-miner/vast-ntfy-topic` (`root:hache-miner`, `0640`). Probar **entrega real a Android** antes de certificar la cobertura de cada alta; no confiar únicamente en el panel web o en una respuesta HTTP.
- **Implementado:** un estado persistente por instancia con transición de código de incidente; un aviso al entrar y otro al recuperarse, con hora UTC e información de identificación/tarifa. **Pendiente:** estado `acknowledged` interactivo, recordatorios escalados, costo de inactividad cuantificado y presentación enriquecida de todas las GPU.
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

**Implementación real:** `app/vast-watchdog.php`, `bin/vast-watchdog.php`, `deploy/systemd/hache-miner-vast-watchdog.{service,timer}` y `tests/vast-watchdog-regression.php`. El timer del VPS se instala/activa una sola vez; cada nueva instancia habilitada entra en su barrido. El alta solo cuenta como `PROTEGIDA` tras verificar dos ciclos sanos y ntfy recibido en Android. El watchdog nunca detiene, reinicia o alquila equipos.
