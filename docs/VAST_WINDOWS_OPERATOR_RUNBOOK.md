# VAST-001 — runbook Windows y prueba autorizada

Este runbook ordena la preparación local y las pruebas autorizadas en Vast.ai.
Las regresiones locales son simuladas y no prueban el receptor remoto. La prueba
real del 2026-10-17 confirmó una share aceptada por Kryptex, pero la instancia
no tenía configuración ni clave del colector: el éxito del pool se confundió
con la validación completa de VAST-001. Esta diferencia queda como puerta
bloqueante en el flujo siguiente.

## Lo revisado y lo que queda fuera

- `deploy/vast-instance-prepare.py` valida una instancia real solo cuando se
  ejecuta en ese host; en modo normal hace preflight y `--apply` solo prepara
  archivos, pero instala el cron de telemetría.
- `deploy/vast-onstart.sh` arranca un minero ya instalado al reiniciar el
  contenedor. No ejecutarlo como prueba en una instancia facturable.
- `deploy/vast-collector.py` envía datos firmados al receptor HACHE-MINER. Un
  colector real necesita una clave y una instancia ya identificada.
- `deploy/vast-instance-prepare.py` no obtiene la clave ni registra una fila en
  el VPS. Su `--apply` instala el cron, puede iniciar envíos automáticos y se
  niega a aplicar si el proceso minero ya está activo; debe ejecutarse antes
  del primer arranque de SRBMiner.
- Los scripts históricos de `C:\Users\hache\Documents\VAST-001` se
  conservaron intactos. `vast001.ps1` puede alquilar al usar `-Create`,
  programa stop/destroy, y el onstart antiguo descarga un minero, valida solo
  MD5 y lo inicia directamente. `vast001-monitor.ps1` usa una clave con nombre
  de otra prueba y forma una orden de ejecución remota en una sola llamada SSH.
  No usar esos archivos para una nueva instancia; consultar su contenido antes
  de migrar cualquier dato.

## Puerta de prueba real: antes de alquilar y antes de minar

No contratar una prueba real hasta comprobar que la conexión autorizada al VPS
HACHE-MINER funciona y que el método permitido para operar una nueva instancia
Vast está disponible. Localizar y confirmar la ubicación/configuración privada
real de la clave compartida en el VPS (el onboarding da
`/etc/hache-miner/vast-ingest.key` como ejemplo), verificar que se puede
transferir sin mostrarla y que hay un método autorizado para registrar el ID
real en `vast_instances`. Un permiso de acceso no sustituye un alias/host y
credencial técnicamente disponibles. No usar perfiles SSH de otra tarea.

Después del alquiler, inventariar la instancia y preparar manifiesto, lanzador,
clave y archivos. Ejecutar primero `--check`, revisar la salida y luego `--apply`
con autorización para ese host **antes de arrancar el minero**. Comprobar que
`/etc/hache-vast/collector.json`, `/etc/hache-vast/ingest.key`,
`/opt/hache-vast/vast-collector.py` y `/etc/cron.d/hache-vast-collector` están
instalados con permisos correctos. Solo entonces arrancar SRBMiner.

La aceptación de shares en Kryptex y la telemetría HACHE-MINER son verificaciones
separadas. Para telemetría, exigir una ejecución manual del colector con
respuesta `202 Accepted`, dos ciclos del cron y dos muestras frescas por GPU
visibles en HACHE-MINER. Después completar los controles del watchdog VAST-002
y la notificación de prueba. Si falta cualquiera, estado **PENDIENTE/NO LISTA**
o **MINANDO SIN ALERTAS**, nunca prueba terminada/protegida. No repetir llamadas
por otro canal si la ejecución es bloqueada; solicitar el canal permitido.

Si la prueba ya se alquiló y el minero arrancó sin colector, no intentar `--apply`
con SRBMiner activo ni matar/reiniciar el proceso para forzarlo. Informar que la
instancia puede seguir facturando, dejarla sin certificar y seguir el paso de
revisión/autorización antes de cualquier cambio.

## Conexión SSH y bloqueo de ejecución remota

Autenticarse por SSH no implica que el control de seguridad permita ejecutar
órdenes remotas. En la prueba anterior, la autenticación terminó y el control
bloqueó una llamada de ejecución remota; eso no demuestra un problema de clave,
huella o configuración de Vast. El motivo específico del control no aparece en
el material disponible, así que no se atribuye a una causa más precisa.

Para una futura prueba:

1. Obtener autorización separada para la instancia concreta, presupuesto,
   duración, acción permitida y wallet pública PRL. Esta preparación actual no
   otorga autorización de alquiler o minería.
2. Confirmar la identidad y los datos de la instancia desde una fuente
   autorizada: `instance_id`, `machine_id`, `host_id`, tarifa total por hora,
   GPU físicas y huella SSH. Usar una clave creada para esa instancia; no
   reutilizar claves o wallets de otra.
3. Si se repite el bloqueo, parar ahí. Pedir al administrador del control una
   vía explícitamente permitida o autorización de ejecución para el conjunto
   exacto de comandos de solo lectura revisados. No volver a empaquetar la
   misma orden mediante otro cliente, shell, transferencia, script de arranque,
   base64 o canal alternativo.
4. Cuando la política permita una terminal interactiva operada por el usuario,
   el operador puede revisar y ejecutar los comandos mostrados en ella. Si la
   política no autoriza ese mecanismo, no ejecutarlos. Codex no intentará la
   llamada bloqueada ni sustituirá el canal por otro.
5. Empezar por inventario de solo lectura y huella verificada. A continuación,
   transferir únicamente los archivos revisados por el canal permitido y hacer
   el preflight. `--apply` requiere autorización específica porque instala
   cron; nunca lo usar como una comprobación inocua.
6. No arrancar el minero ni probar el hook de arranque en esta fase. La primera
   validación de mining requiere autorización separada, wallet aprobada, worker
   único `vast-<instance_id>`, SHA-256 aprobado del binario y criterio de
   apagado. No usar reboot ni detener procesos para probarlo.

La frontera de permiso es la misma independientemente de que el comando salga
de una shell, PowerShell, una aplicación gráfica o un script transferido. Si
el sistema de seguridad lo deniega, se requiere una autorización explícita del
administrador; cambiar la forma de invocarlo no resuelve el bloqueo.

## SRBMiner 3.7.6 e integridad

La [página oficial del release 3.7.6](https://github.com/doktor83/SRBMiner-Multi/releases/tag/3.7.6) publica el MD5 del archivo Linux, pero no
publica un SHA-256. MD5 no satisface la condición de SHA-256 aprobada. Por eso
el preparador solo acepta un SHA-256 suministrado y aprobado por el operador;
no descargar ni instalar el binario hasta recibir ese valor por un canal
confiable e independiente. Calcular SHA-256 localmente sirve para comparar el
archivo con el valor aprobado, pero por sí solo no acredita procedencia.

La etiqueta del archivo debe coincidir con SRBMiner-MULTI 3.7.6. Mantener el
paquete y el resultado de verificación local, y pasar al manifiesto únicamente
la ruta del ejecutable ya extraído y su SHA-256 verificado. No guardar un
paquete de minero o su hash sin verificar como si estuviera aprobado.

## Validación repetible permitida en esta fase

Desde el checkout local se ejecutan las regresiones unitarias de preparación,
el hook con comandos simulados y validación de sintaxis. Estas pruebas no
contactan Vast.ai ni el receptor de telemetría, no usan claves reales, no
descargan binarios y no inician minería. El resultado local solo valida el
comportamiento simulado; no sustituye las puertas de autorización ni las
comprobaciones de producción de `docs/VAST_INSTANCE_ONBOARDING_RULE.md`.

Scripts previos deben respaldarse y revisarse antes de migrarlos. El flujo
actual no los sobrescribe ni importa sus claves, wallets o identificadores.
