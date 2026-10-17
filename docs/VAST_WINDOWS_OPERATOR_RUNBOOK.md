# VAST-001 — runbook local Windows y prueba futura autorizada

Este runbook organiza la preparación desde Windows sin contratar ni modificar
instancias. Las pruebas de esta fase son locales y simuladas. No incluye una
conexión real a Vast.ai.

## Lo revisado y lo que queda fuera

- `deploy/vast-instance-prepare.py` valida una instancia real solo cuando se
  ejecuta en ese host; en modo normal hace preflight y `--apply` solo prepara
  archivos, pero instala el cron de telemetría.
- `deploy/vast-onstart.sh` arranca un minero ya instalado al reiniciar el
  contenedor. No ejecutarlo como prueba en una instancia facturable.
- `deploy/vast-collector.py` envía datos firmados al receptor HACHE-MINER. Un
  colector real necesita una clave y una instancia ya identificada.
- Los scripts históricos de `C:\Users\hache\Documents\VAST-001` se
  conservaron intactos. `vast001.ps1` puede alquilar al usar `-Create`,
  programa stop/destroy, y el onstart antiguo descarga un minero, valida solo
  MD5 y lo inicia directamente. `vast001-monitor.ps1` usa una clave con nombre
  de otra prueba y forma una orden de ejecución remota en una sola llamada SSH.
  No usar esos archivos para una nueva instancia; consultar su contenido antes
  de migrar cualquier dato.

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
