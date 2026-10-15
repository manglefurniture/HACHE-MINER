# VAST-001 — alta estándar de nuevas instancias Vast.ai (PRL)

**Ámbito:** nueva instancia candidata o ya alquilada en Vast.ai, con cualquier combinación admitida de GPU NVIDIA (1–16). Esta regla es obligatoria para la puesta en servicio; no constituye permiso para alquilar ni dispara acciones por sí misma. La instancia de referencia 2× RTX 5090 demostró el flujo, pero **no se deben copiar sus identificadores, precio, billeteras de prueba o umbrales** a otras máquinas.

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

## E. Criterios de aceptación («lista para minar»)

No marcar la instancia como lista hasta comprobar:

- Identidad de Vast, GPU físicas y costo/h correctos; presupuesto y tiempo de prueba autorizados.
- Billetera pública exacta y worker exclusivo; **shares aceptados** por Kryptex de esa máquina, no de una diferente.
- SRBMiner activo una sola vez, hashrate instantáneo y lectura de 5–15 minutos por GPU; temperatura, potencia y rechazo de shares razonables para el modelo probado.
- Hook al inicio y `cron` verificados sin reiniciar ni duplicar el minero.
- Muestras por GPU **frescas (menos de 180 s)** registradas en HACHE-MINER; dos ciclos consecutivos, estado no obsoleto y cálculo separado de **costo estimado vs. cargo real**.
- Respaldo/reversión documentados y credenciales temporales revocadas.

Si cualquiera de estas comprobaciones falla, dejar estado **PENDIENTE/NO LISTA**, informar el motivo exacto y el costo que sigue corriendo. No declarar «telemetría terminada» solo por una respuesta de prueba aislada.

## F. Operación, límites y reversión

- Las alertas y autorrelocaciones de SaladCloud **no se reutilizan automáticamente** para Vast. Nuevos umbrales de GPU solo después de mediciones consistentes y autorización. Mantener los datos por proveedor aislados.
- Ante pool sin shares, wallet discordante, log estancado, key HMAC inválida o muestra atrasada, avisar y diagnosticar; evitar reinicios en bucle que consuman presupuesto.
- Conservar respaldo del lanzador y del hook existentes; revertir solo archivos nuevos si falla el aprovisionamiento y preservar los procesos activos ajenos. Si se decide apagar una instancia **de alquiler**, solicitar autorización cuando afecte costos/producción.
- Una instancia nueva puede tener almacenamiento efímero: comprobar persistencia del hook/configuración al reanudar y repetir aprovisionamiento seguro cuando se recree.
- No subir direcciones reales de operación, credenciales, endpoints privados no documentados, tarifas específicas de la cuenta ni secretos a este repositorio público.

## G. Plantilla de entrega por cada instancia

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
Rentabilidad: [estimada, con supuestos y comisión de pool]
Excepciones y pendientes: [...]
Decisión: LISTA / NO LISTA
```

**Para agentes:** antes de poner a trabajar cualquier nueva oferta Vast, ejecutar esta lista como parte de la tarea, no como una sugerencia opcional. Automatizar el procedimiento con scripts genéricos y pruebas en una rama separada antes de anunciar que existe *aprovisionamiento desatendido*. Nunca alquilar automáticamente solo porque esta regla existe.
